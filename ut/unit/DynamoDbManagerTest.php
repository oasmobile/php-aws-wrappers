<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit;

use Aws\Result;
use GuzzleHttp\Promise\FulfilledPromise;
use Oasis\Mlib\AwsWrappers\DynamoDbIndex;
use Oasis\Mlib\AwsWrappers\DynamoDbItem;
use Oasis\Mlib\AwsWrappers\DynamoDbManager;
use PHPUnit\Framework\TestCase;

/**
 * Lightweight stub that replaces DynamoDbClient for unit testing.
 *
 * PHPUnit 5.7's getMockBuilder triggers "ReflectionType::__toString() is deprecated"
 * on PHP 7.4 when reflecting AwsClient's typed parameters. This manual stub avoids
 * that issue entirely.
 */
class StubDynamoDbClient
{
    /** @var array Queued return values for getCommand+execute pairs */
    public $executeResults = [];

    /** @var array Queued return values for createTable calls */
    public $createTableResults = [];

    /** @var array Queued return values for deleteTable calls */
    public $deleteTableResults = [];

    /** @var array Recorded getCommand calls: [['name' => ..., 'args' => ...], ...] */
    public $getCommandCalls = [];

    /** @var array Recorded createTable calls */
    public $createTableCalls = [];

    /** @var array Recorded deleteTable calls */
    public $deleteTableCalls = [];

    /** @var array Recorded getWaiter calls: [['name' => ..., 'args' => ...], ...] */
    public $getWaiterCalls = [];

    /** @var StubWaiter|null Waiter to return from getWaiter */
    public $waiterStub;

    private $executeIndex = 0;

    public function getCommand($name, array $args = [])
    {
        $this->getCommandCalls[] = ['name' => $name, 'args' => $args];

        // Return a simple token object; execute() uses the index to pick the result
        return (object)['_index' => count($this->getCommandCalls) - 1];
    }

    public function execute($cmd)
    {
        $idx = $this->executeIndex++;

        return $this->executeResults[$idx];
    }

    public function createTable(array $args = [])
    {
        $this->createTableCalls[] = $args;

        return array_shift($this->createTableResults);
    }

    public function deleteTable(array $args = [])
    {
        $this->deleteTableCalls[] = $args;

        return array_shift($this->deleteTableResults);
    }

    public function getWaiter($name, array $args = [])
    {
        $this->getWaiterCalls[] = ['name' => $name, 'args' => $args];

        return $this->waiterStub;
    }
}

/**
 * Stub waiter that returns a configurable promise.
 */
class StubWaiter
{
    /** @var \GuzzleHttp\Promise\PromiseInterface */
    private $promise;

    /** @var bool Whether wait() was called on the promise */
    public $waitCalled = false;

    public function __construct($shouldResolve = true)
    {
        $self = $this;
        $this->promise = new StubPromise($self);
    }

    public function promise()
    {
        return $this->promise;
    }
}

/**
 * Minimal promise stub that tracks wait() calls.
 */
class StubPromise
{
    /** @var StubWaiter */
    private $waiter;

    public function __construct(StubWaiter $waiter)
    {
        $this->waiter = $waiter;
    }

    public function wait()
    {
        $this->waiter->waitCalled = true;
    }
}

/**
 * Test subclass that bypasses AwsConfigDataProvider + new DynamoDbClient()
 * and allows injecting a stub client directly.
 */
class TestableDynamoDbManager extends DynamoDbManager
{
    public function __construct($mockClient)
    {
        $this->db = $mockClient;
    }
}

class DynamoDbManagerTest extends TestCase
{
    /** @var StubDynamoDbClient */
    private $stub;

    /** @var TestableDynamoDbManager */
    private $manager;

    protected function setUp(): void
    {
        $this->stub    = new StubDynamoDbClient();
        $this->manager = new TestableDynamoDbManager($this->stub);
    }

    // ── listTables: single page ──────────────────────────────────

    public function testListTablesSinglePage()
    {
        $this->stub->executeResults = [
            new Result([
                'TableNames' => ['users', 'orders', 'products'],
            ]),
        ];

        $tables = $this->manager->listTables();

        $this->assertSame(['users', 'orders', 'products'], $tables);
        $this->assertCount(1, $this->stub->getCommandCalls);
        $this->assertSame('ListTables', $this->stub->getCommandCalls[0]['name']);
        $this->assertSame(30, $this->stub->getCommandCalls[0]['args']['Limit']);
    }

    // ── listTables: multi-page pagination ────────────────────────

    public function testListTablesMultiPagePagination()
    {
        $this->stub->executeResults = [
            new Result([
                'TableNames'             => ['table_a', 'table_b'],
                'LastEvaluatedTableName' => 'table_b',
            ]),
            new Result([
                'TableNames' => ['table_c'],
            ]),
        ];

        $tables = $this->manager->listTables();

        $this->assertSame(['table_a', 'table_b', 'table_c'], $tables);
        $this->assertCount(2, $this->stub->getCommandCalls);

        // Second call should include ExclusiveStartTableName
        $this->assertArrayHasKey('ExclusiveStartTableName', $this->stub->getCommandCalls[1]['args']);
        $this->assertSame('table_b', $this->stub->getCommandCalls[1]['args']['ExclusiveStartTableName']);
    }

    // ── listTables: pattern filtering ────────────────────────────

    public function testListTablesWithPatternFilter()
    {
        $this->stub->executeResults = [
            new Result([
                'TableNames' => ['dev_users', 'prod_users', 'dev_orders', 'prod_orders'],
            ]),
        ];

        $tables = $this->manager->listTables('/^dev_/');

        $this->assertSame(['dev_users', 'dev_orders'], $tables);
    }

    // ── listTables: empty result ─────────────────────────────────

    public function testListTablesEmptyResult()
    {
        $this->stub->executeResults = [
            new Result([
                'TableNames' => [],
            ]),
        ];

        $tables = $this->manager->listTables();

        $this->assertSame([], $tables);
    }

    // ── listTables: pagination with pattern filtering ────────────

    public function testListTablesMultiPageWithPatternFilter()
    {
        $this->stub->executeResults = [
            new Result([
                'TableNames'             => ['test_a', 'prod_b'],
                'LastEvaluatedTableName' => 'prod_b',
            ]),
            new Result([
                'TableNames' => ['test_c', 'prod_d'],
            ]),
        ];

        $tables = $this->manager->listTables('/^test_/');

        $this->assertSame(['test_a', 'test_c'], $tables);
        $this->assertCount(2, $this->stub->getCommandCalls);
    }

    // ── createTable: basic ───────────────────────────────────────

    public function testCreateTableBasic()
    {
        $primaryIndex = new DynamoDbIndex('id');

        $this->stub->createTableResults = [
            new Result([
                'TableDescription' => ['TableName' => 'my_table', 'TableStatus' => 'CREATING'],
            ]),
        ];

        $result = $this->manager->createTable('my_table', $primaryIndex);

        $this->assertTrue($result);
        $this->assertCount(1, $this->stub->createTableCalls);

        $args = $this->stub->createTableCalls[0];
        $this->assertSame('my_table', $args['TableName']);
        $this->assertSame(5, $args['ProvisionedThroughput']['ReadCapacityUnits']);
        $this->assertSame(5, $args['ProvisionedThroughput']['WriteCapacityUnits']);
        $this->assertArrayHasKey('KeySchema', $args);
        $this->assertArrayHasKey('AttributeDefinitions', $args);
        $this->assertArrayNotHasKey('GlobalSecondaryIndexes', $args);
        $this->assertArrayNotHasKey('LocalSecondaryIndexes', $args);
    }

    // ── createTable: with GSI ────────────────────────────────────

    public function testCreateTableWithGsi()
    {
        $primaryIndex = new DynamoDbIndex('id');
        $gsi = new DynamoDbIndex('email', DynamoDbItem::ATTRIBUTE_TYPE_STRING);
        $gsi->setName('email-index');

        $this->stub->createTableResults = [
            new Result([
                'TableDescription' => ['TableName' => 'my_table'],
            ]),
        ];

        $result = $this->manager->createTable('my_table', $primaryIndex, [], [$gsi]);

        $this->assertTrue($result);

        $args = $this->stub->createTableCalls[0];
        $this->assertArrayHasKey('GlobalSecondaryIndexes', $args);
        $this->assertCount(1, $args['GlobalSecondaryIndexes']);
        $this->assertSame('email-index', $args['GlobalSecondaryIndexes'][0]['IndexName']);
    }

    // ── createTable: with LSI ────────────────────────────────────

    public function testCreateTableWithLsi()
    {
        $primaryIndex = new DynamoDbIndex('id', DynamoDbItem::ATTRIBUTE_TYPE_STRING, 'sort_key');
        $lsi = new DynamoDbIndex('id', DynamoDbItem::ATTRIBUTE_TYPE_STRING, 'created_at');
        $lsi->setName('created-at-index');

        $this->stub->createTableResults = [
            new Result([
                'TableDescription' => ['TableName' => 'my_table'],
            ]),
        ];

        $result = $this->manager->createTable('my_table', $primaryIndex, [$lsi]);

        $this->assertTrue($result);

        $args = $this->stub->createTableCalls[0];
        $this->assertArrayHasKey('LocalSecondaryIndexes', $args);
        $this->assertCount(1, $args['LocalSecondaryIndexes']);
        $this->assertSame('created-at-index', $args['LocalSecondaryIndexes'][0]['IndexName']);
    }

    // ── createTable: returns false on empty description ──────────

    public function testCreateTableReturnsFalseOnEmptyDescription()
    {
        $primaryIndex = new DynamoDbIndex('id');

        $this->stub->createTableResults = [
            new Result([]),
        ];

        $result = $this->manager->createTable('my_table', $primaryIndex);

        $this->assertFalse($result);
    }

    // ── createTable: custom capacity ─────────────────────────────

    public function testCreateTableWithCustomCapacity()
    {
        $primaryIndex = new DynamoDbIndex('id');

        $this->stub->createTableResults = [
            new Result([
                'TableDescription' => ['TableName' => 'my_table'],
            ]),
        ];

        $result = $this->manager->createTable('my_table', $primaryIndex, [], [], 10, 20);

        $this->assertTrue($result);

        $args = $this->stub->createTableCalls[0];
        $this->assertSame(10, $args['ProvisionedThroughput']['ReadCapacityUnits']);
        $this->assertSame(20, $args['ProvisionedThroughput']['WriteCapacityUnits']);
    }

    // ── deleteTable: successful ──────────────────────────────────

    public function testDeleteTableSuccessful()
    {
        $this->stub->deleteTableResults = [
            new Result([
                'TableDescription' => ['TableName' => 'old_table', 'TableStatus' => 'DELETING'],
            ]),
        ];

        $result = $this->manager->deleteTable('old_table');

        $this->assertTrue($result);
        $this->assertCount(1, $this->stub->deleteTableCalls);
        $this->assertSame('old_table', $this->stub->deleteTableCalls[0]['TableName']);
    }

    // ── deleteTable: returns false on empty description ──────────

    public function testDeleteTableReturnsFalseOnEmptyDescription()
    {
        $this->stub->deleteTableResults = [
            new Result([]),
        ];

        $result = $this->manager->deleteTable('old_table');

        $this->assertFalse($result);
    }

    // ── waitForTableCreation: blocking ───────────────────────────

    public function testWaitForTableCreationBlocking()
    {
        $waiter = new StubWaiter();
        $this->stub->waiterStub = $waiter;

        $result = $this->manager->waitForTableCreation('new_table', 60, 1, true);

        $this->assertTrue($result);
        $this->assertTrue($waiter->waitCalled);

        $this->assertCount(1, $this->stub->getWaiterCalls);
        $call = $this->stub->getWaiterCalls[0];
        $this->assertSame('TableExists', $call['name']);
        $this->assertSame('new_table', $call['args']['TableName']);
        $this->assertSame(1, $call['args']['@waiter']['delay']);
        $this->assertEquals(60, $call['args']['@waiter']['maxAttempts']);
    }

    // ── waitForTableCreation: non-blocking returns promise ───────

    public function testWaitForTableCreationNonBlocking()
    {
        $waiter = new StubWaiter();
        $this->stub->waiterStub = $waiter;

        $result = $this->manager->waitForTableCreation('new_table', 60, 1, false);

        $this->assertInstanceOf(StubPromise::class, $result);
        $this->assertFalse($waiter->waitCalled);
    }

    // ── waitForTableCreation: maxAttempts calculation ────────────

    public function testWaitForTableCreationMaxAttemptsCalculation()
    {
        $waiter = new StubWaiter();
        $this->stub->waiterStub = $waiter;

        // timeout=30, pollInterval=7 → ceil(30/7) = 5
        $this->manager->waitForTableCreation('t', 30, 7, true);

        $call = $this->stub->getWaiterCalls[0];
        $this->assertSame(7, $call['args']['@waiter']['delay']);
        $this->assertEquals(5, $call['args']['@waiter']['maxAttempts']);
    }

    // ── waitForTableDeletion: blocking ───────────────────────────

    public function testWaitForTableDeletionBlocking()
    {
        $waiter = new StubWaiter();
        $this->stub->waiterStub = $waiter;

        $result = $this->manager->waitForTableDeletion('old_table', 30, 2, true);

        $this->assertTrue($result);
        $this->assertTrue($waiter->waitCalled);

        $this->assertCount(1, $this->stub->getWaiterCalls);
        $call = $this->stub->getWaiterCalls[0];
        $this->assertSame('TableNotExists', $call['name']);
        $this->assertSame('old_table', $call['args']['TableName']);
        $this->assertSame(2, $call['args']['@waiter']['delay']);
        $this->assertEquals(15, $call['args']['@waiter']['maxAttempts']);
    }

    // ── waitForTableDeletion: non-blocking returns promise ───────

    public function testWaitForTableDeletionNonBlocking()
    {
        $waiter = new StubWaiter();
        $this->stub->waiterStub = $waiter;

        $result = $this->manager->waitForTableDeletion('old_table', 30, 2, false);

        $this->assertInstanceOf(StubPromise::class, $result);
        $this->assertFalse($waiter->waitCalled);
    }
}
