<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit\DynamoDb;

use Aws\Result;
use GuzzleHttp\Promise;
use Oasis\Mlib\AwsWrappers\DynamoDb\ScanCommandWrapper;
use Oasis\Mlib\AwsWrappers\DynamoDbIndex;

class ScanCommandWrapperTest extends \PHPUnit_Framework_TestCase
{
    /** @var StubDynamoDbClient */
    private $stub;

    /** @var ScanCommandWrapper */
    private $wrapper;

    protected function setUp()
    {
        $this->stub    = new StubDynamoDbClient();
        $this->wrapper = new ScanCommandWrapper();
    }

    /**
     * ScanCommandWrapper uses $promise->then(...) which requires a real Promise
     * (not FulfilledPromise) because FulfilledPromise's then() defers to the task queue.
     */
    private function makeResolvablePromise(Result $result)
    {
        $promise = new Promise\Promise(function () use (&$promise, $result) {
            $promise->resolve($result);
        });

        return $promise;
    }

    // ── Basic scan with items + callback ─────────────────────────

    public function testBasicScanWithItemsAndCallback()
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

        $lastKey = null;
        $ret     = call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            $callback,
            null,       // filterExpression
            [],         // fieldsMapping
            [],         // paramsMapping
            DynamoDbIndex::PRIMARY_INDEX,
            &$lastKey,
            null,       // evaluationLimit
            false,      // isConsistentRead
            true,       // isAscendingOrder
            false,      // countOnly
            [],         // projectedAttributes
        ]);

        $this->assertSame(2, $ret);
        $this->assertCount(2, $collected);
        $this->assertSame('Alice', $collected[0]['name']);
        $this->assertSame('Bob', $collected[1]['name']);
    }

    // ── countOnly returns count ──────────────────────────────────

    public function testCountOnlyReturnsCount()
    {
        $this->stub->scanAsyncResults = [
            $this->makeResolvablePromise(new Result([
                'Count' => 99,
            ])),
        ];

        $callback = function () {};

        $lastKey = null;
        $ret     = call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            $callback,
            null,
            [],
            [],
            DynamoDbIndex::PRIMARY_INDEX,
            &$lastKey,
            null,
            false,
            true,
            true,       // countOnly
            [],
        ]);

        $this->assertSame(99, $ret);
    }

    // ── Callback returning false stops iteration ─────────────────

    public function testCallbackReturningFalseStopsIteration()
    {
        $typedItems = [
            ['id' => ['S' => '1'], 'name' => ['S' => 'Alice']],
            ['id' => ['S' => '2'], 'name' => ['S' => 'Bob']],
            ['id' => ['S' => '3'], 'name' => ['S' => 'Charlie']],
        ];

        $this->stub->scanAsyncResults = [
            $this->makeResolvablePromise(new Result([
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
            null,
            [],
            [],
            DynamoDbIndex::PRIMARY_INDEX,
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

    // ── LastKey extraction ───────────────────────────────────────

    public function testLastKeyExtraction()
    {
        $this->stub->scanAsyncResults = [
            $this->makeResolvablePromise(new Result([
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
            null,
            [],
            [],
            DynamoDbIndex::PRIMARY_INDEX,
            &$lastKey,
            null,
            false,
            true,
            false,
            [],
        ]);

        $this->assertSame(['id' => ['S' => '1']], $lastKey);
    }

    // ── Empty items result ───────────────────────────────────────

    public function testEmptyItemsResult()
    {
        $this->stub->scanAsyncResults = [
            $this->makeResolvablePromise(new Result([
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
            null,
            [],
            [],
            DynamoDbIndex::PRIMARY_INDEX,
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
