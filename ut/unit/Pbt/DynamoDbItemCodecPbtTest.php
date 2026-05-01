<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit\Pbt;

use Eris\Generator;
use Eris\Generator\GeneratedValue;
use Eris\Generator\GeneratedValueSingle;
use Eris\Generators;
use Eris\Random\RandomRange;
use Eris\TestTrait;
use Oasis\Mlib\AwsWrappers\DynamoDbItem;
use PHPUnit\Framework\TestCase;

/**
 * Property-Based Tests for DynamoDbItem Codec.
 *
 * Feature: release-3.0
 *
 * Tests the correctness properties of the bidirectional type conversion
 * between PHP native types and DynamoDB typed arrays.
 */
class DynamoDbItemCodecPbtTest extends TestCase
{
    use TestTrait;

    // ---------------------------------------------------------------
    // Custom Generator: arbitrary PHP values for DynamoDbItem
    // ---------------------------------------------------------------

    /**
     * Build a recursive generator for valid PHP values that DynamoDbItem can
     * round-trip.
     *
     * Supported leaf types: non-empty string, int, float, bool, null.
     * Composite types: sequential array (L) and associative array (M),
     * with nesting depth capped at $maxDepth.
     *
     * Empty strings are excluded because DynamoDB converts them to NULL
     * (known lossy behaviour covered by example-based tests).
     */
    private static function phpValueGenerator(int $depth = 0, int $maxDepth = 3): Generator
    {
        return new PhpValueGenerator($depth, $maxDepth);
    }

    /**
     * Build a generator for valid DynamoDB typed arrays.
     *
     * Each typed value is a single-key array like ['S' => 'hello'],
     * ['N' => '42'], ['BOOL' => true], ['NULL' => true],
     * ['L' => [...]], ['M' => [...]].
     */
    private static function typedValueGenerator(int $depth = 0, int $maxDepth = 3): Generator
    {
        return new TypedValueGenerator($depth, $maxDepth);
    }

    // ---------------------------------------------------------------
    // Property 1: Codec Round-Trip (untyped → typed → untyped)
    // ---------------------------------------------------------------

    /**
     * Feature: release-3.0, Property 1: Codec Round-Trip
     *
     * For any valid PHP value, encoding via createFromArray then decoding
     * via toArray SHALL produce a value equivalent to the original input.
     *
     * Validates: Requirements 7.2
     */
    public function testRoundTrip(): void
    {
        $this
            ->limitTo(100)
            ->forAll(self::phpValueGenerator())
            ->then(function (mixed $value): void {
                $input  = ['attr' => $value];
                $item   = DynamoDbItem::createFromArray($input);
                $output = $item->toArray();

                self::assertArrayHasKey('attr', $output);
                self::assertPhpValueEquals($value, $output['attr']);
            });
    }

    // ---------------------------------------------------------------
    // Property 2: Typed Codec Round-Trip (typed → item → typed)
    // ---------------------------------------------------------------

    /**
     * Feature: release-3.0, Property 2: Typed Codec Round-Trip
     *
     * For any valid DynamoDB typed array, creating an item via
     * createFromTypedArray then retrieving via getData SHALL produce
     * a value identical to the original typed array.
     *
     * Validates: Requirements 7.3
     */
    public function testTypedRoundTrip(): void
    {
        $this
            ->limitTo(100)
            ->forAll(self::typedValueGenerator())
            ->then(function (mixed $typedValue): void {
                $input = ['attr' => $typedValue];
                $item  = DynamoDbItem::createFromTypedArray($input);
                $data  = $item->getData();

                self::assertSame($input, $data);
            });
    }

    // ---------------------------------------------------------------
    // Property 3: Codec Idempotence
    // ---------------------------------------------------------------

    /**
     * Feature: release-3.0, Property 3: Codec Idempotence
     *
     * For any valid PHP value, applying the codec transformation twice
     * SHALL produce the same result as applying it once.
     * f(f(x)) == f(x) where f = toArray ∘ createFromArray.
     *
     * Validates: Requirements 7.4
     */
    public function testIdempotence(): void
    {
        $this
            ->limitTo(100)
            ->forAll(self::phpValueGenerator())
            ->then(function (mixed $value): void {
                $input = ['attr' => $value];

                // First application: f(x)
                $result1 = DynamoDbItem::createFromArray($input)->toArray();

                // Second application: f(f(x))
                $result2 = DynamoDbItem::createFromArray($result1)->toArray();

                self::assertPhpValueEquals($result1['attr'], $result2['attr']);
            });
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Compare two PHP values with tolerance for float precision and
     * recursive array comparison.
     *
     * DynamoDB stores numbers as strings; integers round-trip exactly,
     * but a float that happens to equal its intval comes back as int
     * (e.g. 3.0 → "3" → 3).  We accept int↔float equivalence when
     * the numeric values are equal.
     */
    private static function assertPhpValueEquals(mixed $expected, mixed $actual, string $path = '$'): void
    {
        if (is_float($expected) && is_float($actual)) {
            self::assertEqualsWithDelta($expected, $actual, 1e-9, "Float mismatch at {$path}");
            return;
        }

        if (is_numeric($expected) && is_numeric($actual)) {
            self::assertEqualsWithDelta(
                (float) $expected,
                (float) $actual,
                1e-9,
                "Numeric mismatch at {$path}",
            );
            return;
        }

        if (is_array($expected) && is_array($actual)) {
            self::assertSameSize($expected, $actual, "Array size mismatch at {$path}");
            foreach ($expected as $k => $v) {
                self::assertArrayHasKey($k, $actual, "Missing key '{$k}' at {$path}");
                self::assertPhpValueEquals($v, $actual[$k], "{$path}[{$k}]");
            }
            return;
        }

        self::assertSame($expected, $actual, "Value mismatch at {$path}");
    }
}

/**
 * Custom Eris Generator that produces arbitrary PHP values suitable for
 * DynamoDbItem round-trip testing.
 *
 * @internal Used only by DynamoDbItemCodecPbtTest.
 */
class PhpValueGenerator implements Generator
{
    public function __construct(
        private readonly int $depth = 0,
        private readonly int $maxDepth = 3,
    ) {}

    public function __invoke($size, RandomRange $rand): GeneratedValueSingle
    {
        $leafTypes = ['string', 'int', 'float', 'bool', 'null'];
        $allTypes  = $this->depth < $this->maxDepth
            ? array_merge($leafTypes, ['list', 'map'])
            : $leafTypes;

        $type = $allTypes[$rand->rand(0, count($allTypes) - 1)];

        $value = match ($type) {
            'string' => $this->genNonEmptyString($size, $rand),
            'int'    => $rand->rand(-$size, $size),
            'float'  => $rand->rand(-$size * 100, $size * 100) / 100.0,
            'bool'   => (bool) $rand->rand(0, 1),
            'null'   => null,
            'list'   => $this->genList($size, $rand),
            'map'    => $this->genMap($size, $rand),
        };

        return GeneratedValueSingle::fromJustValue($value, 'phpValue');
    }

    public function shrink(GeneratedValue $element): GeneratedValue
    {
        return $element;
    }

    private function genNonEmptyString(int $size, RandomRange $rand): string
    {
        $len = $rand->rand(1, max(1, $size));
        $s   = '';
        for ($i = 0; $i < $len; $i++) {
            $s .= chr($rand->rand(33, 126));
        }
        return $s;
    }

    private function genList(int $size, RandomRange $rand): array
    {
        $len   = $rand->rand(0, min(5, $size));
        $child = new self($this->depth + 1, $this->maxDepth);
        $arr   = [];
        for ($i = 0; $i < $len; $i++) {
            $arr[] = $child($size, $rand)->unbox();
        }
        return $arr;
    }

    private function genMap(int $size, RandomRange $rand): array
    {
        $len   = $rand->rand(1, min(5, max(1, $size)));
        $child = new self($this->depth + 1, $this->maxDepth);
        $arr   = [];
        for ($i = 0; $i < $len; $i++) {
            $key       = 'k' . $rand->rand(0, 999);
            $arr[$key] = $child($size, $rand)->unbox();
        }
        return $arr;
    }
}

/**
 * Custom Eris Generator that produces valid DynamoDB typed values
 * (single-key arrays with type markers: S, N, BOOL, NULL, L, M).
 *
 * @internal Used only by DynamoDbItemCodecPbtTest.
 */
class TypedValueGenerator implements Generator
{
    public function __construct(
        private readonly int $depth = 0,
        private readonly int $maxDepth = 3,
    ) {}

    public function __invoke($size, RandomRange $rand): GeneratedValueSingle
    {
        $leafTypes = ['S', 'N', 'BOOL', 'NULL'];
        $allTypes  = $this->depth < $this->maxDepth
            ? array_merge($leafTypes, ['L', 'M'])
            : $leafTypes;

        $type = $allTypes[$rand->rand(0, count($allTypes) - 1)];

        $value = match ($type) {
            'S'    => ['S' => $this->genNonEmptyString($size, $rand)],
            'N'    => ['N' => (string) ($rand->rand(-$size * 100, $size * 100) / 100.0)],
            'BOOL' => ['BOOL' => (bool) $rand->rand(0, 1)],
            'NULL' => ['NULL' => true],
            'L'    => ['L' => $this->genTypedList($size, $rand)],
            'M'    => ['M' => $this->genTypedMap($size, $rand)],
        };

        return GeneratedValueSingle::fromJustValue($value, 'typedValue');
    }

    public function shrink(GeneratedValue $element): GeneratedValue
    {
        return $element;
    }

    private function genNonEmptyString(int $size, RandomRange $rand): string
    {
        $len = $rand->rand(1, max(1, $size));
        $s   = '';
        for ($i = 0; $i < $len; $i++) {
            $s .= chr($rand->rand(33, 126));
        }
        return $s;
    }

    private function genTypedList(int $size, RandomRange $rand): array
    {
        $len   = $rand->rand(0, min(5, $size));
        $child = new self($this->depth + 1, $this->maxDepth);
        $arr   = [];
        for ($i = 0; $i < $len; $i++) {
            $arr[] = $child($size, $rand)->unbox();
        }
        return $arr;
    }

    private function genTypedMap(int $size, RandomRange $rand): array
    {
        $len   = $rand->rand(1, min(5, max(1, $size)));
        $child = new self($this->depth + 1, $this->maxDepth);
        $arr   = [];
        for ($i = 0; $i < $len; $i++) {
            $key       = 'k' . $rand->rand(0, 999);
            $arr[$key] = $child($size, $rand)->unbox();
        }
        return $arr;
    }
}
