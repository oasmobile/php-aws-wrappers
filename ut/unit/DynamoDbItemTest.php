<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit;

use Oasis\Mlib\AwsWrappers\DynamoDbItem;
use Oasis\Mlib\Utils\Exceptions\InvalidDataTypeException;

class DynamoDbItemTest extends \PHPUnit_Framework_TestCase
{
    // ---------------------------------------------------------------
    // 1. createFromArray — basic types
    // ---------------------------------------------------------------

    public function testCreateFromArrayWithString()
    {
        $item = DynamoDbItem::createFromArray(['name' => 'Alice']);
        $this->assertSame(['name' => ['S' => 'Alice']], $item->getData());
    }

    public function testCreateFromArrayWithInt()
    {
        $item = DynamoDbItem::createFromArray(['age' => 42]);
        $this->assertSame(['age' => ['N' => '42']], $item->getData());
    }

    public function testCreateFromArrayWithFloat()
    {
        $item = DynamoDbItem::createFromArray(['score' => 3.14]);
        $this->assertSame(['score' => ['N' => '3.14']], $item->getData());
    }

    public function testCreateFromArrayWithBool()
    {
        $item = DynamoDbItem::createFromArray(['active' => true, 'deleted' => false]);
        $this->assertSame(
            ['active' => ['BOOL' => true], 'deleted' => ['BOOL' => false]],
            $item->getData()
        );
    }

    public function testCreateFromArrayWithNull()
    {
        $item = DynamoDbItem::createFromArray(['nothing' => null]);
        $this->assertSame(['nothing' => ['NULL' => true]], $item->getData());
    }

    // ---------------------------------------------------------------
    // 2. createFromArray — List (sequential array) & Map (associative)
    // ---------------------------------------------------------------

    public function testCreateFromArrayWithSequentialArray()
    {
        $item = DynamoDbItem::createFromArray(['tags' => ['a', 'b']]);
        $expected = ['tags' => ['L' => [['S' => 'a'], ['S' => 'b']]]];
        $this->assertSame($expected, $item->getData());
    }

    public function testCreateFromArrayWithAssociativeArray()
    {
        $item = DynamoDbItem::createFromArray(['meta' => ['k1' => 'v1', 'k2' => 'v2']]);
        $expected = ['meta' => ['M' => ['k1' => ['S' => 'v1'], 'k2' => ['S' => 'v2']]]];
        $this->assertSame($expected, $item->getData());
    }

    // ---------------------------------------------------------------
    // 3. Nested structures
    // ---------------------------------------------------------------

    public function testNestedMapContainingList()
    {
        $input = ['data' => ['items' => ['x', 'y']]];
        $item  = DynamoDbItem::createFromArray($input);
        $this->assertSame($input, $item->toArray());
    }

    public function testNestedListContainingMap()
    {
        $input = ['rows' => [['id' => 1], ['id' => 2]]];
        $item  = DynamoDbItem::createFromArray($input);
        $this->assertSame($input, $item->toArray());
    }

    public function testThreeLevelNesting()
    {
        $input = [
            'level1' => [
                'level2' => [
                    'level3' => 'deep',
                ],
            ],
        ];
        $item = DynamoDbItem::createFromArray($input);
        $this->assertSame($input, $item->toArray());
    }

    // ---------------------------------------------------------------
    // 4. createFromTypedArray + getData round-trip
    // ---------------------------------------------------------------

    public function testCreateFromTypedArrayAndGetData()
    {
        $typed = [
            'name'  => ['S' => 'Bob'],
            'age'   => ['N' => '25'],
            'ok'    => ['BOOL' => false],
            'empty' => ['NULL' => true],
        ];
        $item = DynamoDbItem::createFromTypedArray($typed);
        $this->assertSame($typed, $item->getData());
    }

    // ---------------------------------------------------------------
    // 5. toArray round-trip (untyped → typed → untyped)
    // ---------------------------------------------------------------

    public function testToArrayRoundTrip()
    {
        $untyped = [
            'name'  => 'John',
            'age'   => 17,
            'speed' => 9.5,
            'null'  => null,
            'male'  => true,
            'list'  => ['apple', 'orange'],
            'map'   => ['a' => 'apple', 'b' => 'banana'],
        ];
        $item = DynamoDbItem::createFromArray($untyped);
        $this->assertSame($untyped, $item->toArray());
    }

    // ---------------------------------------------------------------
    // 6. Empty string → NULL conversion
    // ---------------------------------------------------------------

    public function testEmptyStringConvertsToNull()
    {
        $item = DynamoDbItem::createFromArray(['field' => '']);
        $this->assertSame(['field' => ['NULL' => true]], $item->getData());
        $this->assertNull($item->toArray()['field']);
    }

    // ---------------------------------------------------------------
    // 7. Non-numeric value with N type → "0"
    // ---------------------------------------------------------------

    public function testNonNumericWithKnownTypeNBecomesZero()
    {
        $item = DynamoDbItem::createFromArray(
            ['val' => 'not-a-number'],
            ['val' => 'N']
        );
        $this->assertSame(['val' => ['N' => '0']], $item->getData());
        $this->assertSame(0, $item->toArray()['val']);
    }

    // ---------------------------------------------------------------
    // 8. Binary type: base64 encode/decode, empty binary → NULL
    // ---------------------------------------------------------------

    public function testBinaryTypeEncodeDecode()
    {
        $raw   = 'binary-data';
        $typed = ['bin' => ['B' => base64_encode($raw)]];
        $item  = DynamoDbItem::createFromTypedArray($typed);
        $this->assertSame($raw, $item->toArray()['bin']);
    }

    public function testBinaryKnownTypeEncodesValue()
    {
        $raw  = 'hello';
        $item = DynamoDbItem::createFromArray(['bin' => $raw], ['bin' => 'B']);
        $this->assertSame(['bin' => ['B' => base64_encode($raw)]], $item->getData());
    }

    public function testEmptyBinaryConvertsToNull()
    {
        $item = DynamoDbItem::createFromArray(['bin' => ''], ['bin' => 'B']);
        $this->assertSame(['bin' => ['NULL' => true]], $item->getData());
    }

    public function testFalsyBinaryConvertsToNull()
    {
        $item = DynamoDbItem::createFromArray(['bin' => null], ['bin' => 'B']);
        $this->assertSame(['bin' => ['NULL' => true]], $item->getData());
    }

    // ---------------------------------------------------------------
    // 9. Number precision: int vs float detection
    // ---------------------------------------------------------------

    public function testIntValueReturnedAsInt()
    {
        $typed = ['num' => ['N' => '42']];
        $item  = DynamoDbItem::createFromTypedArray($typed);
        $val   = $item->toArray()['num'];
        $this->assertSame(42, $val);
        $this->assertTrue(is_int($val));
    }

    public function testFloatValueReturnedAsFloat()
    {
        $typed = ['num' => ['N' => '3.14']];
        $item  = DynamoDbItem::createFromTypedArray($typed);
        $val   = $item->toArray()['num'];
        $this->assertSame(3.14, $val);
        $this->assertTrue(is_float($val));
    }

    public function testIntStoredAsStringInTypedValue()
    {
        $item = DynamoDbItem::createFromArray(['n' => 100]);
        $this->assertSame('100', $item->getData()['n']['N']);
    }

    public function testFloatStoredAsStringInTypedValue()
    {
        $item = DynamoDbItem::createFromArray(['n' => 2.718]);
        $this->assertSame('2.718', $item->getData()['n']['N']);
    }

    // ---------------------------------------------------------------
    // 10. ArrayAccess: offsetExists, offsetGet, offsetSet, offsetUnset
    // ---------------------------------------------------------------

    public function testOffsetExists()
    {
        $item = DynamoDbItem::createFromArray(['key' => 'val']);
        $this->assertTrue(isset($item['key']));
        $this->assertFalse(isset($item['missing']));
    }

    public function testOffsetGet()
    {
        $item = DynamoDbItem::createFromArray(['x' => 10]);
        $this->assertSame(10, $item['x']);
    }

    public function testOffsetSet()
    {
        $item = DynamoDbItem::createFromArray([]);
        $item['color'] = 'red';
        $this->assertTrue(isset($item['color']));
        $this->assertSame('red', $item['color']);
    }

    public function testOffsetUnset()
    {
        $item = DynamoDbItem::createFromArray(['a' => 1, 'b' => 2]);
        unset($item['a']);
        $this->assertFalse(isset($item['a']));
        $this->assertTrue(isset($item['b']));
    }

    // ---------------------------------------------------------------
    // 11. ArrayAccess exceptions: OutOfBoundsException
    // ---------------------------------------------------------------

    public function testOffsetGetThrowsOutOfBoundsForMissingKey()
    {
        $this->setExpectedException(\OutOfBoundsException::class);
        $item = DynamoDbItem::createFromArray([]);
        $item['nonexistent'];
    }

    public function testOffsetUnsetThrowsOutOfBoundsForMissingKey()
    {
        $this->setExpectedException(\OutOfBoundsException::class);
        $item = DynamoDbItem::createFromArray([]);
        unset($item['nonexistent']);
    }

    // ---------------------------------------------------------------
    // 12. InvalidDataTypeException for invalid typed value format
    // ---------------------------------------------------------------

    public function testToUntypedValueThrowsOnNonArray()
    {
        $this->setExpectedException(InvalidDataTypeException::class);
        // createFromTypedArray stores raw data; toArray calls toUntypedValue
        $item = DynamoDbItem::createFromTypedArray(['bad' => 'not-an-array']);
        $item->toArray();
    }

    public function testToUntypedValueThrowsOnArrayWithMultipleKeys()
    {
        $this->setExpectedException(InvalidDataTypeException::class);
        $item = DynamoDbItem::createFromTypedArray(['bad' => ['S' => 'a', 'N' => '1']]);
        $item->toArray();
    }

    public function testToUntypedValueThrowsOnEmptyArray()
    {
        $this->setExpectedException(InvalidDataTypeException::class);
        $item = DynamoDbItem::createFromTypedArray(['bad' => []]);
        $item->toArray();
    }

    // ---------------------------------------------------------------
    // 13. RuntimeException for unknown type string
    // ---------------------------------------------------------------

    public function testUnknownTypeStringThrowsRuntimeException()
    {
        $this->setExpectedException(\RuntimeException::class);
        DynamoDbItem::createFromArray(
            ['val' => 'test'],
            ['val' => 'UNKNOWN_TYPE_XYZ']
        );
    }

    // ---------------------------------------------------------------
    // 14. InvalidDataTypeException for unsupported PHP type (object)
    // ---------------------------------------------------------------

    public function testUnsupportedPhpTypeThrowsInvalidDataTypeException()
    {
        $this->setExpectedException(InvalidDataTypeException::class);
        DynamoDbItem::createFromArray(['obj' => new \stdClass()]);
    }

    // ---------------------------------------------------------------
    // 15. Known type override via $known_types parameter
    // ---------------------------------------------------------------

    public function testKnownTypesOverridesAutoDetection()
    {
        // "42" would auto-detect as string, but known_types forces N
        $item = DynamoDbItem::createFromArray(
            ['val' => '42'],
            ['val' => 'N']
        );
        $this->assertSame(['val' => ['N' => '42']], $item->getData());
    }

    public function testKnownTypesWithLowercaseTypeString()
    {
        // The code resolves lowercase type via constant lookup: "string" → ATTRIBUTE_TYPE_STRING
        $item = DynamoDbItem::createFromArray(
            ['val' => 'hello'],
            ['val' => 'string']
        );
        $this->assertSame(['val' => ['S' => 'hello']], $item->getData());
    }

    // ---------------------------------------------------------------
    // 16. Unicode / Chinese string handling
    // ---------------------------------------------------------------

    public function testUnicodeStringHandling()
    {
        $input = ['greeting' => '你好世界'];
        $item  = DynamoDbItem::createFromArray($input);
        $this->assertSame($input, $item->toArray());
        $this->assertSame(['greeting' => ['S' => '你好世界']], $item->getData());
    }

    public function testUnicodeInNestedStructure()
    {
        $input = ['data' => ['名前' => '太郎', 'tags' => ['日本語', 'テスト']]];
        $item  = DynamoDbItem::createFromArray($input);
        $this->assertSame($input, $item->toArray());
    }

    // ---------------------------------------------------------------
    // Additional edge cases
    // ---------------------------------------------------------------

    public function testZeroIntIsNotConvertedToNull()
    {
        $item = DynamoDbItem::createFromArray(['n' => 0]);
        $this->assertSame(['n' => ['N' => '0']], $item->getData());
        $this->assertSame(0, $item->toArray()['n']);
    }

    public function testNegativeNumber()
    {
        $item = DynamoDbItem::createFromArray(['n' => -5]);
        $this->assertSame(['n' => ['N' => '-5']], $item->getData());
        $this->assertSame(-5, $item->toArray()['n']);
    }

    public function testEmptyArrayIsList()
    {
        $item = DynamoDbItem::createFromArray(['arr' => []]);
        // Empty array: no keys to iterate, idx stays 0, returns L
        $this->assertSame(['arr' => ['L' => []]], $item->getData());
    }

    public function testCreateFromArrayWithEmptyInput()
    {
        $item = DynamoDbItem::createFromArray([]);
        $this->assertSame([], $item->getData());
        $this->assertSame([], $item->toArray());
    }

    public function testOffsetSetOverwritesExistingKey()
    {
        $item = DynamoDbItem::createFromArray(['k' => 'old']);
        $item['k'] = 'new';
        $this->assertSame('new', $item['k']);
    }

    public function testToUntypedValueThrowsOnUnrecognizedTypeInTypedArray()
    {
        $this->setExpectedException(InvalidDataTypeException::class);
        $item = DynamoDbItem::createFromTypedArray(['field' => ['UNKNOWN' => 'val']]);
        $item->toArray();
    }
}
