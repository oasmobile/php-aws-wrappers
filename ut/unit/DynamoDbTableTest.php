<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\Result;
use GuzzleHttp\Promise\FulfilledPromise;
use Oasis\Mlib\AwsWrappers\DynamoDbIndex;
use Oasis\Mlib\AwsWrappers\DynamoDbItem;
use Oasis\Mlib\AwsWrappers\DynamoDbTable;
use PHPUnit\Framework\TestCase;

/**
 * Test subclass that allows injecting a mock DynamoDbClient.
 *
 * DynamoDbTable creates DynamoDbClient internally via AwsConfigDataProvider,
 * so we bypass the constructor to inject a mock directly.
 */
class TestableDynamoDbTable extends DynamoDbTable
{
    public function __construct(DynamoDbClient $mockClient, $tableName, $attributeTypes = [])
    {
        $this->dbClient       = $mockClient;
        $this->tableName      = $tableName;
        $this->attributeTypes = $attributeTypes;
    }
}

/**
 * Stub DynamoDbClient that records magic method calls and returns queued results.
 * PHPUnit 13 removed addMethods()/setMethods(), so we use a manual stub for
 * AWS SDK clients that rely on __call magic methods.
 */
class StubDynamoDbClientForTable extends DynamoDbClient
{
    /** @var array<string, list<mixed>> Queued return values per method */
    private array $returnQueue = [];

    /** @var array<string, list<\Throwable>> Queued exceptions per method */
    private array $throwQueue = [];

    /** @var array<string, list<array>> Recorded calls per method */
    public array $calls = [];

    public function __construct()
    {
        // Skip parent constructor
    }

    public function queueReturn(string $method, mixed $value): self
    {
        $this->returnQueue[$method][] = $value;
        return $this;
    }

    public function queueThrow(string $method, \Throwable $e): self
    {
        $this->throwQueue[$method][] = $e;
        return $this;
    }

    public function __call($name, array $args)
    {
        $this->calls[$name][] = $args;

        if (!empty($this->throwQueue[$name])) {
            throw array_shift($this->throwQueue[$name]);
        }

        if (!empty($this->returnQueue[$name])) {
            return array_shift($this->returnQueue[$name]);
        }

        return null;
    }
}

class DynamoDbTableTest extends TestCase
{
    /** @var StubDynamoDbClientForTable */
    private $mockClient;

    /** @var TestableDynamoDbTable */
    private $table;

    protected function setUp(): void
    {
        $this->mockClient = new StubDynamoDbClientForTable();
        $this->table = new TestableDynamoDbTable($this->mockClient, 'test-table');
    }

    // ================================================================
    // 1. Simple getters / setters
    // ================================================================

    public function testGetTableName()
    {
        $this->assertSame('test-table', $this->table->getTableName());
    }

    public function testGetDbClient()
    {
        $this->assertSame($this->mockClient, $this->table->getDbClient());
    }

    public function testSetAttributeTypeReturnsSelf()
    {
        $result = $this->table->setAttributeType('id', 'S');
        $this->assertSame($this->table, $result);
    }

    // ================================================================
    // 2. get()
    // ================================================================

    public function testGetReturnsItemArray()
    {
        $typedItem = ['id' => ['S' => '123'], 'name' => ['S' => 'Alice']];

        $this->mockClient->queueReturn('getItem', new Result(['Item' => $typedItem]));

        $result = $this->table->get(['id' => '123']);

        $this->assertSame('123', $result['id']);
        $this->assertSame('Alice', $result['name']);
        $this->assertCount(1, $this->mockClient->calls['getItem']);
        $args = $this->mockClient->calls['getItem'][0][0];
        $this->assertSame('test-table', $args['TableName']);
        $this->assertArrayHasKey('id', $args['Key']);
    }

    public function testGetReturnsNullWhenItemNotFound()
    {
        $this->mockClient->queueReturn('getItem', new Result(['Item' => null]));

        $result = $this->table->get(['id' => '999']);

        $this->assertNull($result);
    }

    public function testGetWithConsistentRead()
    {
        $this->mockClient->queueReturn('getItem', new Result(['Item' => ['id' => ['S' => '1']]]));

        $this->table->get(['id' => '1'], true);

        $args = $this->mockClient->calls['getItem'][0][0];
        $this->assertTrue($args['ConsistentRead']);
    }

    public function testGetWithProjectedFields()
    {
        $this->mockClient->queueReturn('getItem', new Result(['Item' => ['id' => ['S' => '1']]]));

        // Note: the source code uses deprecated implode() parameter order on PHP 7.4,
        // which triggers a deprecation warning but still works.
        @$this->table->get(['id' => '1'], false, ['name', 'email']);

        $args = $this->mockClient->calls['getItem'][0][0];
        $this->assertArrayHasKey('ExpressionAttributeNames', $args);
    }

    // ================================================================
    // 3. set()
    // ================================================================

    public function testSetReturnsTrueOnSuccess()
    {
        $this->mockClient->queueReturn('putItem', new Result([]));

        $result = $this->table->set(['id' => '1', 'name' => 'Bob']);

        $this->assertTrue($result);
        $args = $this->mockClient->calls['putItem'][0][0];
        $this->assertSame('test-table', $args['TableName']);
        $this->assertArrayHasKey('Item', $args);
    }

    public function testSetWithCheckValuesBuildsConditionExpression()
    {
        $this->mockClient->queueReturn('putItem', new Result([]));

        $result = $this->table->set(
            ['id' => '1', 'name' => 'Bob'],
            ['version' => 1]
        );

        $this->assertTrue($result);
        $args = $this->mockClient->calls['putItem'][0][0];
        $this->assertArrayHasKey('ConditionExpression', $args);
        $this->assertArrayHasKey('ExpressionAttributeNames', $args);
        $this->assertArrayHasKey('ExpressionAttributeValues', $args);
    }

    public function testSetReturnsFalseOnConditionalCheckFailedException()
    {
        $exception = $this->createStub(DynamoDbException::class);
        $exception->method('getAwsErrorCode')->willReturn('ConditionalCheckFailedException');

        $this->mockClient->queueThrow('putItem', $exception);

        $result = $this->table->set(
            ['id' => '1', 'name' => 'Bob'],
            ['version' => 1]
        );

        $this->assertFalse($result);
    }

    // ================================================================
    // 4. delete()
    // ================================================================

    public function testDelete()
    {
        $this->table->delete(['id' => '1']);

        $this->assertCount(1, $this->mockClient->calls['deleteItem']);
        $args = $this->mockClient->calls['deleteItem'][0][0];
        $this->assertSame('test-table', $args['TableName']);
        $this->assertArrayHasKey('id', $args['Key']);
    }

    // ================================================================
    // 5. batchGet()
    // ================================================================

    public function testBatchGetReturnsItems()
    {
        $responseItems = [
            ['id' => ['S' => '1'], 'name' => ['S' => 'Alice']],
            ['id' => ['S' => '2'], 'name' => ['S' => 'Bob']],
        ];

        $result = new Result([
            'Responses' => [
                'test-table' => $responseItems,
            ],
            'UnprocessedKeys' => [],
        ]);

        $this->mockClient->queueReturn('batchGetItemAsync', new FulfilledPromise($result));

        $items = $this->table->batchGet([['id' => '1'], ['id' => '2']]);

        $this->assertCount(2, $items);
        $this->assertSame('Alice', $items[0]['name']);
        $this->assertSame('Bob', $items[1]['name']);
    }

    // ================================================================
    // 6. batchPut()
    // ================================================================

    public function testBatchPut()
    {
        $result = new Result([
            'UnprocessedItems' => [],
        ]);

        $this->mockClient->queueReturn('batchWriteItemAsync', new FulfilledPromise($result));

        $this->table->batchPut([
            ['id' => '1', 'name' => 'Alice'],
            ['id' => '2', 'name' => 'Bob'],
        ]);

        $args = $this->mockClient->calls['batchWriteItemAsync'][0][0];
        $items = $args['RequestItems']['test-table'];
        $this->assertArrayHasKey('PutRequest', $items[0]);
    }

    // ================================================================
    // 7. batchDelete()
    // ================================================================

    public function testBatchDelete()
    {
        $result = new Result([
            'UnprocessedItems' => [],
        ]);

        $this->mockClient->queueReturn('batchWriteItemAsync', new FulfilledPromise($result));

        $this->table->batchDelete([
            ['id' => '1'],
            ['id' => '2'],
        ]);

        $args = $this->mockClient->calls['batchWriteItemAsync'][0][0];
        $items = $args['RequestItems']['test-table'];
        $this->assertArrayHasKey('DeleteRequest', $items[0]);
    }

    // ================================================================
    // 8. query() — delegates to QueryCommandWrapper → queryAsync
    // ================================================================

    public function testQueryDelegatesToClient()
    {
        $typedItems = [
            ['id' => ['S' => '1'], 'name' => ['S' => 'Alice']],
        ];

        $result = new Result([
            'Items' => $typedItems,
            'Count' => 1,
        ]);

        $this->mockClient->queueReturn('queryAsync', new FulfilledPromise($result));

        $items = $this->table->query(
            '#pk = :pk',
            ['#pk' => 'id'],
            [':pk' => '1']
        );

        $this->assertCount(1, $items);
        $this->assertSame('Alice', $items[0]['name']);
    }

    // ================================================================
    // 9. scan() — delegates to ScanCommandWrapper → scanAsync
    // ================================================================

    public function testScanDelegatesToClient()
    {
        $typedItems = [
            ['id' => ['S' => '1'], 'name' => ['S' => 'Bob']],
        ];

        $result = new Result([
            'Items' => $typedItems,
            'Count' => 1,
        ]);

        // ScanCommandWrapper uses $promise->then(...) then $promise->wait().
        // With FulfilledPromise, then() defers to the task queue.
        // Use a real Promise that resolves immediately so the then() chain works.
        $promise = new \GuzzleHttp\Promise\Promise(function () use (&$promise, $result) {
            $promise->resolve($result);
        });

        $this->mockClient->queueReturn('scanAsync', $promise);

        $items = $this->table->scan();

        $this->assertCount(1, $items);
        $this->assertSame('Bob', $items[0]['name']);
    }

    // ================================================================
    // 10. describe()
    // ================================================================

    public function testDescribeReturnsTableDescription()
    {
        $tableDesc = [
            'TableName'   => 'test-table',
            'TableStatus' => 'ACTIVE',
            'KeySchema'   => [
                ['AttributeName' => 'id', 'KeyType' => 'HASH'],
            ],
        ];

        $this->mockClient->queueReturn('describeTable', new Result(['Table' => $tableDesc]));

        $result = $this->table->describe();

        $this->assertSame('test-table', $result['TableName']);
        $this->assertSame('ACTIVE', $result['TableStatus']);
    }

    // ================================================================
    // 11. addGlobalSecondaryIndex()
    // ================================================================

    public function testAddGlobalSecondaryIndex()
    {
        $this->mockClient->queueReturn('describeTable', new Result([
            'Table' => [
                'TableName'          => 'test-table',
                'AttributeDefinitions' => [],
            ],
        ]));
        // updateTable returns null by default from stub

        $gsi = new DynamoDbIndex('email', DynamoDbItem::ATTRIBUTE_TYPE_STRING);
        $gsi->setName('email-index');

        $this->table->addGlobalSecondaryIndex($gsi);

        $this->assertCount(1, $this->mockClient->calls['updateTable']);
        $args = $this->mockClient->calls['updateTable'][0][0];
        $this->assertArrayHasKey('Create', $args['GlobalSecondaryIndexUpdates'][0]);
    }

    public function testAddGlobalSecondaryIndexThrowsIfAlreadyExists()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Global Secondary Index exists');

        $this->mockClient->queueReturn('describeTable', new Result([
            'Table' => [
                'TableName' => 'test-table',
                'AttributeDefinitions' => [
                    ['AttributeName' => 'email', 'AttributeType' => 'S'],
                ],
                'GlobalSecondaryIndexes' => [
                    [
                        'IndexName' => 'email-index',
                        'KeySchema' => [
                            ['AttributeName' => 'email', 'KeyType' => 'HASH'],
                        ],
                        'Projection' => ['ProjectionType' => 'ALL'],
                    ],
                ],
            ],
        ]));

        $gsi = new DynamoDbIndex('email', DynamoDbItem::ATTRIBUTE_TYPE_STRING);
        $gsi->setName('email-index');

        $this->table->addGlobalSecondaryIndex($gsi);
    }

    // ================================================================
    // 12. deleteGlobalSecondaryIndex()
    // ================================================================

    public function testDeleteGlobalSecondaryIndex()
    {
        $this->mockClient->queueReturn('describeTable', new Result([
            'Table' => [
                'TableName' => 'test-table',
                'AttributeDefinitions' => [
                    ['AttributeName' => 'email', 'AttributeType' => 'S'],
                ],
                'GlobalSecondaryIndexes' => [
                    [
                        'IndexName' => 'email-index',
                        'KeySchema' => [
                            ['AttributeName' => 'email', 'KeyType' => 'HASH'],
                        ],
                        'Projection' => ['ProjectionType' => 'ALL'],
                    ],
                ],
            ],
        ]));

        $this->table->deleteGlobalSecondaryIndex('email-index');

        $args = $this->mockClient->calls['updateTable'][0][0];
        $this->assertArrayHasKey('Delete', $args['GlobalSecondaryIndexUpdates'][0]);
        $this->assertSame('email-index', $args['GlobalSecondaryIndexUpdates'][0]['Delete']['IndexName']);
    }

    public function testDeleteGlobalSecondaryIndexThrowsIfNotExists()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Global Secondary Index doesn't exist");

        $this->mockClient->queueReturn('describeTable', new Result([
            'Table' => [
                'TableName'          => 'test-table',
                'AttributeDefinitions' => [],
            ],
        ]));

        $this->table->deleteGlobalSecondaryIndex('nonexistent-index');
    }

    // ================================================================
    // 13. getGlobalSecondaryIndices()
    // ================================================================

    public function testGetGlobalSecondaryIndicesWithGSIs()
    {
        $this->mockClient->queueReturn('describeTable', new Result([
            'Table' => [
                'TableName' => 'test-table',
                'AttributeDefinitions' => [
                    ['AttributeName' => 'email', 'AttributeType' => 'S'],
                    ['AttributeName' => 'age', 'AttributeType' => 'N'],
                ],
                'GlobalSecondaryIndexes' => [
                    [
                        'IndexName' => 'email-index',
                        'KeySchema' => [['AttributeName' => 'email', 'KeyType' => 'HASH']],
                        'Projection' => ['ProjectionType' => 'ALL'],
                    ],
                    [
                        'IndexName' => 'age-index',
                        'KeySchema' => [['AttributeName' => 'age', 'KeyType' => 'HASH']],
                        'Projection' => ['ProjectionType' => 'KEYS_ONLY'],
                    ],
                ],
            ],
        ]));

        $gsis = $this->table->getGlobalSecondaryIndices();

        $this->assertCount(2, $gsis);
        $this->assertArrayHasKey('email-index', $gsis);
        $this->assertArrayHasKey('age-index', $gsis);
        $this->assertInstanceOf(DynamoDbIndex::class, $gsis['email-index']);
    }

    public function testGetGlobalSecondaryIndicesWithoutGSIs()
    {
        $this->mockClient->queueReturn('describeTable', new Result([
            'Table' => [
                'TableName'          => 'test-table',
                'AttributeDefinitions' => [],
            ],
        ]));

        $gsis = $this->table->getGlobalSecondaryIndices();

        $this->assertSame([], $gsis);
    }

    public function testGetGlobalSecondaryIndicesWithPatternFilter()
    {
        $this->mockClient->queueReturn('describeTable', new Result([
            'Table' => [
                'TableName' => 'test-table',
                'AttributeDefinitions' => [
                    ['AttributeName' => 'email', 'AttributeType' => 'S'],
                    ['AttributeName' => 'age', 'AttributeType' => 'N'],
                ],
                'GlobalSecondaryIndexes' => [
                    [
                        'IndexName' => 'email-index',
                        'KeySchema' => [['AttributeName' => 'email', 'KeyType' => 'HASH']],
                        'Projection' => ['ProjectionType' => 'ALL'],
                    ],
                    [
                        'IndexName' => 'age-index',
                        'KeySchema' => [['AttributeName' => 'age', 'KeyType' => 'HASH']],
                        'Projection' => ['ProjectionType' => 'KEYS_ONLY'],
                    ],
                ],
            ],
        ]));

        $gsis = $this->table->getGlobalSecondaryIndices('/email/');

        $this->assertCount(1, $gsis);
        $this->assertArrayHasKey('email-index', $gsis);
    }

    // ================================================================
    // 14. enableStream()
    // ================================================================

    public function testEnableStream()
    {
        $this->table->enableStream();

        $args = $this->mockClient->calls['updateTable'][0][0];
        $this->assertSame('test-table', $args['TableName']);
        $this->assertTrue($args['StreamSpecification']['StreamEnabled']);
        $this->assertSame('NEW_AND_OLD_IMAGES', $args['StreamSpecification']['StreamViewType']);
    }

    // ================================================================
    // 15. disableStream()
    // ================================================================

    public function testDisableStream()
    {
        $this->table->disableStream();

        $args = $this->mockClient->calls['updateTable'][0][0];
        $this->assertSame('test-table', $args['TableName']);
        $this->assertFalse($args['StreamSpecification']['StreamEnabled']);
    }

    // ================================================================
    // 16. isStreamEnabled()
    // ================================================================

    public function testIsStreamEnabledReturnsTrue()
    {
        $this->mockClient->queueReturn('describeTable', new Result([
            'Table' => [
                'TableName' => 'test-table',
                'StreamSpecification' => [
                    'StreamEnabled'  => true,
                    'StreamViewType' => 'NEW_AND_OLD_IMAGES',
                ],
            ],
        ]));

        $viewType = null;
        $result   = $this->table->isStreamEnabled($viewType);

        $this->assertTrue($result);
        $this->assertSame('NEW_AND_OLD_IMAGES', $viewType);
    }

    public function testIsStreamEnabledReturnsFalseWhenNoStreamSpec()
    {
        $this->mockClient->queueReturn('describeTable', new Result([
            'Table' => [
                'TableName' => 'test-table',
            ],
        ]));

        $viewType = null;
        $result   = $this->table->isStreamEnabled($viewType);

        $this->assertFalse($result);
        $this->assertNull($viewType);
    }

    // ================================================================
    // 17. queryAndRun() — pagination loop with callback
    // ================================================================

    public function testQueryAndRunCallsCallbackForEachItem()
    {
        $typedItems = [
            ['id' => ['S' => '1'], 'name' => ['S' => 'Alice']],
            ['id' => ['S' => '2'], 'name' => ['S' => 'Bob']],
        ];

        $result = new Result([
            'Items' => $typedItems,
            'Count' => 2,
        ]);

        $this->mockClient->queueReturn('queryAsync', new FulfilledPromise($result));

        $collected = [];
        $this->table->queryAndRun(
            function (array $item) use (&$collected): void {
                $collected[] = $item;
            },
            '#pk = :pk',
            ['#pk' => 'id'],
            [':pk' => '1'],
        );

        $this->assertCount(2, $collected);
        $this->assertSame('Alice', $collected[0]['name']);
        $this->assertSame('Bob', $collected[1]['name']);
    }

    public function testQueryAndRunStopsWhenCallbackReturnsFalse()
    {
        $typedItems = [
            ['id' => ['S' => '1'], 'name' => ['S' => 'Alice']],
            ['id' => ['S' => '2'], 'name' => ['S' => 'Bob']],
        ];

        $result = new Result([
            'Items' => $typedItems,
            'Count' => 2,
        ]);

        $this->mockClient->queueReturn('queryAsync', new FulfilledPromise($result));

        $collected = [];
        $this->table->queryAndRun(
            function (array $item) use (&$collected): bool {
                $collected[] = $item;
                return false; // stop after first item
            },
            '#pk = :pk',
            ['#pk' => 'id'],
            [':pk' => '1'],
        );

        $this->assertCount(1, $collected);
    }

    // ================================================================
    // 18. scanAndRun() — pagination loop with callback
    // ================================================================

    public function testScanAndRunCallsCallbackForEachItem()
    {
        $typedItems = [
            ['id' => ['S' => '1'], 'name' => ['S' => 'Alice']],
        ];

        $result = new Result([
            'Items' => $typedItems,
            'Count' => 1,
        ]);

        $promise = new \GuzzleHttp\Promise\Promise(function () use (&$promise, $result) {
            $promise->resolve($result);
        });

        $this->mockClient->queueReturn('scanAsync', $promise);

        $collected = [];
        $this->table->scanAndRun(
            function (array $item) use (&$collected): void {
                $collected[] = $item;
            },
        );

        $this->assertCount(1, $collected);
        $this->assertSame('Alice', $collected[0]['name']);
    }

    public function testScanAndRunStopsWhenCallbackReturnsFalse()
    {
        $typedItems = [
            ['id' => ['S' => '1'], 'name' => ['S' => 'Alice']],
            ['id' => ['S' => '2'], 'name' => ['S' => 'Bob']],
        ];

        $result = new Result([
            'Items' => $typedItems,
            'Count' => 2,
        ]);

        $promise = new \GuzzleHttp\Promise\Promise(function () use (&$promise, $result) {
            $promise->resolve($result);
        });

        $this->mockClient->queueReturn('scanAsync', $promise);

        $collected = [];
        $this->table->scanAndRun(
            function (array $item) use (&$collected): bool {
                $collected[] = $item;
                return false;
            },
        );

        $this->assertCount(1, $collected);
    }

    // ================================================================
    // 19. queryCount()
    // ================================================================

    public function testQueryCountReturnsCount()
    {
        $result = new Result([
            'Items' => [],
            'Count' => 42,
        ]);

        $this->mockClient->queueReturn('queryAsync', new FulfilledPromise($result));

        $count = $this->table->queryCount(
            '#pk = :pk',
            ['#pk' => 'id'],
            [':pk' => '1'],
        );

        $this->assertSame(42, $count);
    }

    // ================================================================
    // 20. scanCount()
    // ================================================================

    public function testScanCountReturnsCount()
    {
        // ParallelScanCommandWrapper uses ScanAsyncCommandWrapper which calls scanAsync
        // Default parallel=10, so we need 10 promises
        $result = new Result([
            'Items' => [],
            'Count' => 10,
        ]);

        for ($i = 0; $i < 10; $i++) {
            $this->mockClient->queueReturn('scanAsync', new FulfilledPromise($result));
        }

        $count = $this->table->scanCount();

        // 10 segments × 10 count each = 100
        $this->assertSame(100, $count);
    }

    // ================================================================
    // 21. multiQueryAndRun()
    // ================================================================

    public function testMultiQueryAndRunDelegatesToWrapper()
    {
        $result = new Result([
            'Items' => [
                ['id' => ['S' => '1'], 'name' => ['S' => 'Alice']],
            ],
            'Count' => 1,
        ]);

        $this->mockClient->queueReturn('queryAsync', new FulfilledPromise($result));

        $collected = [];
        $this->table->multiQueryAndRun(
            function (array $item) use (&$collected): void {
                $collected[] = $item;
            },
            'id',
            ['1'],
            '#pk = :pk',
            ['#pk' => 'id'],
            [':pk' => '1'],
        );

        $this->assertCount(1, $collected);
    }

    // ================================================================
    // 22. parallelScanAndRun()
    // ================================================================

    public function testParallelScanAndRunDelegatesToWrapper()
    {
        $result = new Result([
            'Items' => [
                ['id' => ['S' => '1'], 'name' => ['S' => 'Alice']],
            ],
            'Count' => 1,
        ]);

        // parallelScanAndRun with parallel=2
        for ($i = 0; $i < 2; $i++) {
            $this->mockClient->queueReturn('scanAsync', new FulfilledPromise($result));
        }

        $collected = [];
        $this->table->parallelScanAndRun(
            2,
            function (array $item) use (&$collected): void {
                $collected[] = $item;
            },
        );

        $this->assertNotEmpty($collected);
    }

    // ================================================================
    // 23. getLocalSecondaryIndices()
    // ================================================================

    public function testGetLocalSecondaryIndicesReturnsLSIs()
    {
        $this->mockClient->queueReturn('describeTable', new Result([
            'Table' => [
                'TableName' => 'test-table',
                'AttributeDefinitions' => [
                    ['AttributeName' => 'id', 'AttributeType' => 'S'],
                    ['AttributeName' => 'created', 'AttributeType' => 'N'],
                ],
                'LocalSecondaryIndexes' => [
                    [
                        'IndexName' => 'created-index',
                        'KeySchema' => [
                            ['AttributeName' => 'id', 'KeyType' => 'HASH'],
                            ['AttributeName' => 'created', 'KeyType' => 'RANGE'],
                        ],
                        'Projection' => ['ProjectionType' => 'ALL'],
                    ],
                ],
            ],
        ]));

        $lsis = $this->table->getLocalSecondaryIndices();

        $this->assertCount(1, $lsis);
        $this->assertArrayHasKey('created-index', $lsis);
        $this->assertInstanceOf(DynamoDbIndex::class, $lsis['created-index']);
    }

    public function testGetLocalSecondaryIndicesReturnsEmptyWhenNoLSIs()
    {
        $this->mockClient->queueReturn('describeTable', new Result([
            'Table' => [
                'TableName'          => 'test-table',
                'AttributeDefinitions' => [],
            ],
        ]));

        $lsis = $this->table->getLocalSecondaryIndices();

        $this->assertSame([], $lsis);
    }

    // ================================================================
    // 24. getPrimaryIndex()
    // ================================================================

    public function testGetPrimaryIndexHashOnly()
    {
        $this->mockClient->queueReturn('describeTable', new Result([
            'Table' => [
                'TableName' => 'test-table',
                'AttributeDefinitions' => [
                    ['AttributeName' => 'id', 'AttributeType' => 'S'],
                ],
                'KeySchema' => [
                    ['AttributeName' => 'id', 'KeyType' => 'HASH'],
                ],
            ],
        ]));

        $index = $this->table->getPrimaryIndex();

        $this->assertInstanceOf(DynamoDbIndex::class, $index);
    }

    public function testGetPrimaryIndexHashAndRange()
    {
        $this->mockClient->queueReturn('describeTable', new Result([
            'Table' => [
                'TableName' => 'test-table',
                'AttributeDefinitions' => [
                    ['AttributeName' => 'pk', 'AttributeType' => 'S'],
                    ['AttributeName' => 'sk', 'AttributeType' => 'N'],
                ],
                'KeySchema' => [
                    ['AttributeName' => 'pk', 'KeyType' => 'HASH'],
                    ['AttributeName' => 'sk', 'KeyType' => 'RANGE'],
                ],
            ],
        ]));

        $index = $this->table->getPrimaryIndex();

        $this->assertInstanceOf(DynamoDbIndex::class, $index);
    }

    // ================================================================
    // 25. getThroughput()
    // ================================================================

    public function testGetThroughputPrimaryIndex()
    {
        // describe() returns $result['Table'], so getThroughput accesses ProvisionedThroughput directly
        $this->mockClient->queueReturn('describeTable', new Result([
            'Table' => [
                'ProvisionedThroughput' => [
                    'ReadCapacityUnits'  => 10,
                    'WriteCapacityUnits' => 5,
                ],
            ],
        ]));

        $result = $this->table->getThroughput();

        $this->assertSame([10, 5], $result);
    }

    public function testGetThroughputGSI()
    {
        // The GSI branch is reached when $indexName != PRIMARY_INDEX (true).
        // Due to loose comparison, only false reaches the else branch.
        // Then $gsi['IndexName'] != false is checked — 'email-index' != false is true,
        // so the GSI is always skipped. This tests the foreach iteration path.
        $this->mockClient->queueReturn('describeTable', new Result([
            'Table' => [
                'GlobalSecondaryIndexes' => [
                    [
                        'IndexName' => false,
                        'ProvisionedThroughput' => [
                            'ReadCapacityUnits'  => 3,
                            'WriteCapacityUnits' => 2,
                        ],
                    ],
                ],
            ],
        ]));

        $result = $this->table->getThroughput(false);

        $this->assertSame([3, 2], $result);
    }

    public function testGetThroughputThrowsForUnknownIndex()
    {
        $this->expectException(\UnexpectedValueException::class);

        $this->mockClient->queueReturn('describeTable', new Result([
            'Table' => [
                'GlobalSecondaryIndexes' => [],
            ],
        ]));

        $this->table->getThroughput(false);
    }

    // ================================================================
    // 26. setThroughput()
    // ================================================================

    // --- ISS-3.0.1-L01 red tests: string GSI name must reach GSI branch ---

    public function testGetThroughputWithStringGSIName()
    {
        $this->mockClient->queueReturn('describeTable', new Result([
            'Table' => [
                'GlobalSecondaryIndexes' => [
                    [
                        'IndexName' => 'email-index',
                        'ProvisionedThroughput' => [
                            'ReadCapacityUnits'  => 20,
                            'WriteCapacityUnits' => 10,
                        ],
                    ],
                ],
            ],
        ]));

        $result = $this->table->getThroughput('email-index');

        $this->assertSame([20, 10], $result);
    }

    public function testSetThroughputWithStringGSIName()
    {
        $this->table->setThroughput(8, 4, 'email-index');

        $args = $this->mockClient->calls['updateTable'][0][0];
        $this->assertArrayHasKey('GlobalSecondaryIndexUpdates', $args);
        $this->assertArrayNotHasKey('ProvisionedThroughput', $args);
        $update = $args['GlobalSecondaryIndexUpdates'][0]['Update'];
        $this->assertSame('email-index', $update['IndexName']);
        $this->assertSame(8, $update['ProvisionedThroughput']['ReadCapacityUnits']);
        $this->assertSame(4, $update['ProvisionedThroughput']['WriteCapacityUnits']);
    }

    public function testSetThroughputPrimaryIndex()
    {
        $this->table->setThroughput(10, 5);

        $args = $this->mockClient->calls['updateTable'][0][0];
        $this->assertSame('test-table', $args['TableName']);
        $this->assertSame(10, $args['ProvisionedThroughput']['ReadCapacityUnits']);
        $this->assertSame(5, $args['ProvisionedThroughput']['WriteCapacityUnits']);
    }

    public function testSetThroughputGSI()
    {
        // Pass false to reach the GSI branch (string values are == true due to loose comparison)
        $this->table->setThroughput(3, 2, false);

        $args = $this->mockClient->calls['updateTable'][0][0];
        $this->assertArrayHasKey('GlobalSecondaryIndexUpdates', $args);
        $update = $args['GlobalSecondaryIndexUpdates'][0]['Update'];
        $this->assertSame(false, $update['IndexName']);
        $this->assertSame(3, $update['ProvisionedThroughput']['ReadCapacityUnits']);
    }

    public function testSetThroughputSwallowsValidationException()
    {
        $exception = $this->createStub(DynamoDbException::class);
        $exception->method('getAwsErrorCode')->willReturn('ValidationException');
        $exception->method('getAwsErrorType')->willReturn('client');
        $exception->method('isConnectionError')->willReturn(false);

        $this->mockClient->queueThrow('updateTable', $exception);

        // Should not throw — swallows ValidationException for identical values
        $this->table->setThroughput(10, 5);
        $this->assertTrue(true); // reached here without exception
    }

    public function testSetThroughputRethrowsOtherDynamoDbException()
    {
        $this->expectException(DynamoDbException::class);

        $exception = $this->createStub(DynamoDbException::class);
        $exception->method('getAwsErrorCode')->willReturn('InternalServerError');
        $exception->method('getAwsErrorType')->willReturn('server');
        $exception->method('isConnectionError')->willReturn(false);

        $this->mockClient->queueThrow('updateTable', $exception);

        $this->table->setThroughput(10, 5);
    }

    // ================================================================
    // 27. set() — re-throw path for non-ConditionalCheckFailed exceptions
    // ================================================================

    public function testSetRethrowsNonConditionalCheckException()
    {
        $this->expectException(DynamoDbException::class);

        $exception = $this->createStub(DynamoDbException::class);
        $exception->method('getAwsErrorCode')->willReturn('InternalServerError');
        $exception->method('getAwsErrorType')->willReturn('server');

        $this->mockClient->queueThrow('putItem', $exception);

        $this->table->set(['id' => '1', 'name' => 'Bob']);
    }

    // ================================================================
    // 28. set() with null checkValues (NULL condition path)
    // ================================================================

    public function testSetWithNullCheckValueBuildsAttributeNotExistsCondition()
    {
        $this->mockClient->queueReturn('putItem', new Result([]));

        $result = $this->table->set(
            ['id' => '1', 'name' => 'Bob'],
            ['version' => null],
        );

        $this->assertTrue($result);
        $args = $this->mockClient->calls['putItem'][0][0];
        $this->assertStringContainsString('attribute_not_exists', $args['ConditionExpression']);
    }

    // ================================================================
    // 29. enableStream() with custom type
    // ================================================================

    public function testEnableStreamWithCustomType()
    {
        $this->table->enableStream('KEYS_ONLY');

        $args = $this->mockClient->calls['updateTable'][0][0];
        $this->assertSame('KEYS_ONLY', $args['StreamSpecification']['StreamViewType']);
    }

    // ================================================================
    // 30. getGlobalSecondaryIndices() with RANGE key and NonKeyAttributes
    // ================================================================

    public function testGetGlobalSecondaryIndicesWithRangeKeyAndProjection()
    {
        $this->mockClient->queueReturn('describeTable', new Result([
            'Table' => [
                'TableName' => 'test-table',
                'AttributeDefinitions' => [
                    ['AttributeName' => 'email', 'AttributeType' => 'S'],
                    ['AttributeName' => 'created', 'AttributeType' => 'N'],
                ],
                'GlobalSecondaryIndexes' => [
                    [
                        'IndexName' => 'email-created-index',
                        'KeySchema' => [
                            ['AttributeName' => 'email', 'KeyType' => 'HASH'],
                            ['AttributeName' => 'created', 'KeyType' => 'RANGE'],
                        ],
                        'Projection' => [
                            'ProjectionType' => 'INCLUDE',
                            'NonKeyAttributes' => ['name', 'status'],
                        ],
                    ],
                ],
            ],
        ]));

        $gsis = $this->table->getGlobalSecondaryIndices();

        $this->assertCount(1, $gsis);
        $this->assertArrayHasKey('email-created-index', $gsis);
    }

    // ================================================================
    // 31. batchGet() with projected fields
    // ================================================================

    public function testBatchGetWithProjectedFields()
    {
        $responseItems = [
            ['id' => ['S' => '1'], 'name' => ['S' => 'Alice']],
        ];

        $result = new Result([
            'Responses' => [
                'test-table' => $responseItems,
            ],
            'UnprocessedKeys' => [],
        ]);

        $this->mockClient->queueReturn('batchGetItemAsync', new FulfilledPromise($result));

        $items = $this->table->batchGet(
            [['id' => '1']],
            false,
            10,
            ['name', 'email'],
        );

        $this->assertCount(1, $items);
        $args = $this->mockClient->calls['batchGetItemAsync'][0][0];
        $this->assertArrayHasKey('ProjectionExpression', $args['RequestItems']['test-table']);
        $this->assertArrayHasKey('ExpressionAttributeNames', $args['RequestItems']['test-table']);
    }

    // ================================================================
    // 32. query() with additional parameters
    // ================================================================

    public function testQueryWithFilterExpressionAndIndex()
    {
        $result = new Result([
            'Items' => [
                ['id' => ['S' => '1'], 'status' => ['S' => 'active']],
            ],
            'Count' => 1,
        ]);

        $this->mockClient->queueReturn('queryAsync', new FulfilledPromise($result));

        $items = $this->table->query(
            '#pk = :pk',
            ['#pk' => 'id', '#st' => 'status'],
            [':pk' => '1', ':st' => 'active'],
            'status-index',
            '#st = :st',
        );

        $this->assertCount(1, $items);
    }

    // ================================================================
    // 33. scan() with filter expression
    // ================================================================

    public function testScanWithFilterExpression()
    {
        $result = new Result([
            'Items' => [
                ['id' => ['S' => '1'], 'status' => ['S' => 'active']],
            ],
            'Count' => 1,
        ]);

        $promise = new \GuzzleHttp\Promise\Promise(function () use (&$promise, $result) {
            $promise->resolve($result);
        });

        $this->mockClient->queueReturn('scanAsync', $promise);

        $items = $this->table->scan(
            '#st = :st',
            ['#st' => 'status'],
            [':st' => 'active'],
        );

        $this->assertCount(1, $items);
    }
}
