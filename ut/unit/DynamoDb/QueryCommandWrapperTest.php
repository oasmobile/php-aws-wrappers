<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit\DynamoDb;

use Aws\Result;
use GuzzleHttp\Promise\FulfilledPromise;
use Oasis\Mlib\AwsWrappers\DynamoDb\QueryCommandWrapper;
use Oasis\Mlib\AwsWrappers\DynamoDbIndex;

class QueryCommandWrapperTest extends \PHPUnit_Framework_TestCase
{
    /** @var StubDynamoDbClient */
    private $stub;

    /** @var QueryCommandWrapper */
    private $wrapper;

    protected function setUp()
    {
        $this->stub    = new StubDynamoDbClient();
        $this->wrapper = new QueryCommandWrapper();
    }

    // ── Basic query with items + callback ────────────────────────

    public function testBasicQueryWithItemsAndCallback()
    {
        $typedItems = [
            ['id' => ['S' => '1'], 'name' => ['S' => 'Alice']],
            ['id' => ['S' => '2'], 'name' => ['S' => 'Bob']],
        ];

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

        $lastKey = null;
        $ret     = call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            $callback,
            '#pk = :pk',
            ['#pk' => 'id'],
            [':pk' => 'val1'],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            &$lastKey,
            null,
            false,
            true,
            false,
            [],
        ]);

        $this->assertSame(2, $ret);
        $this->assertCount(2, $collected);
        $this->assertSame('Alice', $collected[0]['name']);
        $this->assertSame('Bob', $collected[1]['name']);
    }

    // ── countOnly returns count ──────────────────────────────────

    public function testCountOnlyReturnsCount()
    {
        $this->stub->queryAsyncResults = [
            new FulfilledPromise(new Result([
                'Count' => 42,
            ])),
        ];

        $callback = function () {};

        $lastKey = null;
        $ret     = call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            $callback,
            '#pk = :pk',
            ['#pk' => 'id'],
            [':pk' => 'val1'],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            &$lastKey,
            null,
            false,
            true,
            true,       // countOnly
            [],
        ]);

        $this->assertSame(42, $ret);
    }

    // ── Callback returning false stops iteration ─────────────────

    public function testCallbackReturningFalseStopsIteration()
    {
        $typedItems = [
            ['id' => ['S' => '1'], 'name' => ['S' => 'Alice']],
            ['id' => ['S' => '2'], 'name' => ['S' => 'Bob']],
            ['id' => ['S' => '3'], 'name' => ['S' => 'Charlie']],
        ];

        $this->stub->queryAsyncResults = [
            new FulfilledPromise(new Result([
                'Items' => $typedItems,
                'Count' => 3,
            ])),
        ];

        $collected = [];
        $callback  = function ($item) use (&$collected) {
            $collected[] = $item;
            if ($item['name'] === 'Bob') {
                return false;
            }
        };

        $lastKey = null;
        $ret     = call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            $callback,
            '#pk = :pk',
            ['#pk' => 'id'],
            [':pk' => 'val1'],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            &$lastKey,
            null,
            false,
            true,
            false,
            [],
        ]);

        // ret counts items processed (including the one that returned false)
        $this->assertSame(2, $ret);
        $this->assertCount(2, $collected);
        $this->assertSame('Alice', $collected[0]['name']);
        $this->assertSame('Bob', $collected[1]['name']);
    }

    // ── LastKey extraction ───────────────────────────────────────

    public function testLastKeyExtraction()
    {
        $this->stub->queryAsyncResults = [
            new FulfilledPromise(new Result([
                'Items'            => [['id' => ['S' => '1']]],
                'Count'            => 1,
                'LastEvaluatedKey' => ['id' => ['S' => '1']],
            ])),
        ];

        $callback = function () {};

        $lastKey = null;
        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            $callback,
            '#pk = :pk',
            ['#pk' => 'id'],
            [':pk' => 'val1'],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            &$lastKey,
            null,
            false,
            true,
            false,
            [],
        ]);

        $this->assertSame(['id' => ['S' => '1']], $lastKey);
    }

    // ── No LastEvaluatedKey sets lastKey to null ─────────────────

    public function testNoLastEvaluatedKeySetsLastKeyToNull()
    {
        $this->stub->queryAsyncResults = [
            new FulfilledPromise(new Result([
                'Items' => [['id' => ['S' => '1']]],
                'Count' => 1,
            ])),
        ];

        $callback = function () {};

        $lastKey = ['id' => ['S' => 'previous']];
        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            $callback,
            '#pk = :pk',
            ['#pk' => 'id'],
            [':pk' => 'val1'],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            &$lastKey,
            null,
            false,
            true,
            false,
            [],
        ]);

        $this->assertNull($lastKey);
    }

    // ── Empty items result ───────────────────────────────────────

    public function testEmptyItemsResult()
    {
        $this->stub->queryAsyncResults = [
            new FulfilledPromise(new Result([
                'Items' => [],
                'Count' => 0,
            ])),
        ];

        $callbackCalled = false;
        $callback       = function () use (&$callbackCalled) {
            $callbackCalled = true;
        };

        $lastKey = null;
        $ret     = call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            $callback,
            '#pk = :pk',
            ['#pk' => 'id'],
            [':pk' => 'val1'],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            &$lastKey,
            null,
            false,
            true,
            false,
            [],
        ]);

        $this->assertSame(0, $ret);
        $this->assertFalse($callbackCalled);
    }
}
