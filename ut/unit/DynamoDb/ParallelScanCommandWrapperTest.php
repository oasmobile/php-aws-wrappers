<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit\DynamoDb;

use Aws\Result;
use GuzzleHttp\Promise;
use Oasis\Mlib\AwsWrappers\DynamoDb\ParallelScanCommandWrapper;
use Oasis\Mlib\AwsWrappers\DynamoDbIndex;

class ParallelScanCommandWrapperTest extends \PHPUnit_Framework_TestCase
{
    /** @var StubDynamoDbClient */
    private $stub;

    /** @var ParallelScanCommandWrapper */
    private $wrapper;

    protected function setUp()
    {
        $this->stub    = new StubDynamoDbClient();
        $this->wrapper = new ParallelScanCommandWrapper();
    }

    /**
     * ParallelScanCommandWrapper uses $promise->then(...) which requires a real Promise.
     */
    private function makeResolvablePromise(Result $result)
    {
        $promise = new Promise\Promise(function () use (&$promise, $result) {
            $promise->resolve($result);
        });

        return $promise;
    }

    // ── Single segment ───────────────────────────────────────────

    public function testSingleSegment()
    {
        $typedItems = [
            ['id' => ['S' => '1'], 'name' => ['S' => 'Alice']],
            ['id' => ['S' => '2'], 'name' => ['S' => 'Bob']],
        ];

        $this->stub->scanAsyncResults = [
            $this->makeResolvablePromise(new Result([
                'Items' => $typedItems,
                'Count' => 2,
            ])),
        ];

        $collected = [];
        $callback  = function ($item) use (&$collected) {
            $collected[] = $item;
        };

        $ret = call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            $callback,
            null,       // filterExpression
            [],         // fieldsMapping
            [],         // paramsMapping
            DynamoDbIndex::PRIMARY_INDEX,
            null,       // evaluationLimit
            false,      // isConsistentRead
            true,       // isAscendingOrder
            1,          // totalSegments
            false,      // countOnly
            [],         // projectedAttributes
        ]);

        $this->assertSame(2, $ret);
        $this->assertCount(2, $collected);
        $this->assertSame('Alice', $collected[0]['name']);
        $this->assertSame('Bob', $collected[1]['name']);
    }

    // ── Multiple segments ────────────────────────────────────────

    public function testMultipleSegments()
    {
        // 2 segments, each returns different items
        $this->stub->scanAsyncResults = [
            $this->makeResolvablePromise(new Result([
                'Items' => [['id' => ['S' => '1'], 'name' => ['S' => 'Alice']]],
                'Count' => 1,
            ])),
            $this->makeResolvablePromise(new Result([
                'Items' => [['id' => ['S' => '2'], 'name' => ['S' => 'Bob']]],
                'Count' => 1,
            ])),
        ];

        $collected = [];
        $callback  = function ($item) use (&$collected) {
            $collected[] = $item;
        };

        $ret = call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            $callback,
            null,
            [],
            [],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            false,
            true,
            2,          // totalSegments
            false,
            [],
        ]);

        $this->assertSame(2, $ret);
        $this->assertCount(2, $collected);

        // Verify segment parameters were passed
        $this->assertSame(0, $this->stub->scanAsyncCalls[0]['Segment']);
        $this->assertSame(2, $this->stub->scanAsyncCalls[0]['TotalSegments']);
        $this->assertSame(1, $this->stub->scanAsyncCalls[1]['Segment']);
        $this->assertSame(2, $this->stub->scanAsyncCalls[1]['TotalSegments']);
    }

    // ── countOnly ────────────────────────────────────────────────

    public function testCountOnly()
    {
        // 2 segments, each returns a count
        $this->stub->scanAsyncResults = [
            $this->makeResolvablePromise(new Result([
                'Count' => 10,
            ])),
            $this->makeResolvablePromise(new Result([
                'Count' => 15,
            ])),
        ];

        $callback = function () {};

        $ret = call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            $callback,
            null,
            [],
            [],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            false,
            true,
            2,
            true,       // countOnly
            [],
        ]);

        $this->assertSame(25, $ret);
    }

    // ── Callback returning false stops processing ────────────────

    public function testCallbackReturningFalseStopsProcessing()
    {
        // Single segment with multiple items; callback stops after first
        $typedItems = [
            ['id' => ['S' => '1'], 'name' => ['S' => 'Alice']],
            ['id' => ['S' => '2'], 'name' => ['S' => 'Bob']],
        ];

        $this->stub->scanAsyncResults = [
            $this->makeResolvablePromise(new Result([
                'Items' => $typedItems,
                'Count' => 2,
            ])),
        ];

        $collected = [];
        $callback  = function ($item) use (&$collected) {
            $collected[] = $item;

            return false; // stop after first item
        };

        $ret = call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            $callback,
            null,
            [],
            [],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            false,
            true,
            1,
            false,
            [],
        ]);

        $this->assertCount(1, $collected);
        $this->assertSame('Alice', $collected[0]['name']);
        // ret counts all items in the batch (count($items)), not just processed ones
        $this->assertSame(2, $ret);
    }

    // ── Pagination within a segment ──────────────────────────────

    public function testPaginationWithinSegment()
    {
        // Single segment: first page has LastEvaluatedKey, second page doesn't
        $this->stub->scanAsyncResults = [
            $this->makeResolvablePromise(new Result([
                'Items'            => [['id' => ['S' => '1']]],
                'Count'            => 1,
                'LastEvaluatedKey' => ['id' => ['S' => '1']],
            ])),
            $this->makeResolvablePromise(new Result([
                'Items' => [['id' => ['S' => '2']]],
                'Count' => 1,
            ])),
        ];

        $collected = [];
        $callback  = function ($item) use (&$collected) {
            $collected[] = $item;
        };

        $ret = call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            $callback,
            null,
            [],
            [],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            false,
            true,
            1,
            false,
            [],
        ]);

        $this->assertSame(2, $ret);
        $this->assertCount(2, $collected);
    }
}
