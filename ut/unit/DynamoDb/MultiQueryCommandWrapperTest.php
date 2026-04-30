<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit\DynamoDb;

use Aws\Result;
use GuzzleHttp\Promise\FulfilledPromise;
use Oasis\Mlib\AwsWrappers\DynamoDb\MultiQueryCommandWrapper;
use Oasis\Mlib\AwsWrappers\DynamoDbIndex;

class MultiQueryCommandWrapperTest extends \PHPUnit_Framework_TestCase
{
    /** @var StubDynamoDbClient */
    private $stub;

    /** @var MultiQueryCommandWrapper */
    private $wrapper;

    protected function setUp()
    {
        $this->stub    = new StubDynamoDbClient();
        $this->wrapper = new MultiQueryCommandWrapper();
    }

    // ── Single hash key value ────────────────────────────────────

    public function testSingleHashKeyValue()
    {
        $typedItems = [
            ['pk' => ['S' => 'a'], 'data' => ['S' => 'item1']],
            ['pk' => ['S' => 'a'], 'data' => ['S' => 'item2']],
        ];

        // First call returns items with no pagination
        $this->stub->queryAsyncResults = [
            new FulfilledPromise(new Result([
                'Items' => $typedItems,
                'Count' => 2,
            ])),
        ];

        $collected = [];
        $callback  = function ($item) use (&$collected) {
            $collected[] = $item;
        };

        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            $callback,
            'pk',               // hashKeyName
            ['value_a'],        // hashKeyValues
            null,               // rangeKeyConditions
            [],                 // fieldsMapping
            [],                 // paramsMapping
            DynamoDbIndex::PRIMARY_INDEX,
            null,               // filterExpression
            null,               // evaluationLimit
            false,              // isConsistentRead
            true,               // isAscendingOrder
            1,                  // concurrency
            [],                 // projectedFields
        ]);

        $this->assertCount(2, $collected);
        $this->assertSame('item1', $collected[0]['data']);
        $this->assertSame('item2', $collected[1]['data']);
    }

    // ── Multiple hash key values ─────────────────────────────────

    public function testMultipleHashKeyValues()
    {
        // Two hash key values, each returns one item, no pagination
        $this->stub->queryAsyncResults = [
            new FulfilledPromise(new Result([
                'Items' => [['pk' => ['S' => 'a'], 'data' => ['S' => 'from_a']]],
                'Count' => 1,
            ])),
            new FulfilledPromise(new Result([
                'Items' => [['pk' => ['S' => 'b'], 'data' => ['S' => 'from_b']]],
                'Count' => 1,
            ])),
        ];

        $collected = [];
        $callback  = function ($item) use (&$collected) {
            $collected[] = $item;
        };

        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            $callback,
            'pk',
            ['value_a', 'value_b'],
            null,
            [],
            [],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            null,
            false,
            true,
            2,                  // concurrency
            [],
        ]);

        $this->assertCount(2, $collected);
        $this->assertSame('from_a', $collected[0]['data']);
        $this->assertSame('from_b', $collected[1]['data']);
    }

    // ── Callback returning false stops processing ────────────────

    public function testCallbackReturningFalseStopsProcessing()
    {
        // Two hash key values; callback stops after first item
        $this->stub->queryAsyncResults = [
            new FulfilledPromise(new Result([
                'Items' => [
                    ['pk' => ['S' => 'a'], 'data' => ['S' => 'first']],
                    ['pk' => ['S' => 'a'], 'data' => ['S' => 'second']],
                ],
                'Count' => 2,
            ])),
            // Second query result should not be needed if stopped
            new FulfilledPromise(new Result([
                'Items' => [['pk' => ['S' => 'b'], 'data' => ['S' => 'third']]],
                'Count' => 1,
            ])),
        ];

        $collected = [];
        $callback  = function ($item) use (&$collected) {
            $collected[] = $item;

            return false; // stop after first item
        };

        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            $callback,
            'pk',
            ['value_a', 'value_b'],
            null,
            [],
            [],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            null,
            false,
            true,
            1,
            [],
        ]);

        $this->assertCount(1, $collected);
        $this->assertSame('first', $collected[0]['data']);
    }

    // ── With range key conditions ────────────────────────────────

    public function testWithRangeKeyConditions()
    {
        $this->stub->queryAsyncResults = [
            new FulfilledPromise(new Result([
                'Items' => [['pk' => ['S' => 'a'], 'sk' => ['S' => 'r1']]],
                'Count' => 1,
            ])),
        ];

        $collected = [];
        $callback  = function ($item) use (&$collected) {
            $collected[] = $item;
        };

        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            $callback,
            'pk',
            ['value_a'],
            '#sk > :skval',     // rangeKeyConditions
            ['#sk' => 'sk'],
            [':skval' => 'start'],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            null,
            false,
            true,
            1,
            [],
        ]);

        $this->assertCount(1, $collected);

        // Verify the key condition includes both hash and range
        $args = $this->stub->queryAsyncCalls[0];
        $this->assertContains('AND', $args['KeyConditionExpression']);
        $this->assertContains('#sk > :skval', $args['KeyConditionExpression']);
    }

    // ── Pagination within a single hash key ──────────────────────

    public function testPaginationWithinSingleHashKey()
    {
        // First page returns items + LastEvaluatedKey
        // Second page returns remaining items without LastEvaluatedKey
        $this->stub->queryAsyncResults = [
            new FulfilledPromise(new Result([
                'Items'            => [['pk' => ['S' => 'a'], 'data' => ['S' => 'page1']]],
                'Count'            => 1,
                'LastEvaluatedKey' => ['pk' => ['S' => 'a'], 'sk' => ['S' => 'cursor']],
            ])),
            new FulfilledPromise(new Result([
                'Items' => [['pk' => ['S' => 'a'], 'data' => ['S' => 'page2']]],
                'Count' => 1,
            ])),
        ];

        $collected = [];
        $callback  = function ($item) use (&$collected) {
            $collected[] = $item;
        };

        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            $callback,
            'pk',
            ['value_a'],
            null,
            [],
            [],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            null,
            false,
            true,
            1,
            [],
        ]);

        $this->assertCount(2, $collected);
        $this->assertSame('page1', $collected[0]['data']);
        $this->assertSame('page2', $collected[1]['data']);
    }
}
