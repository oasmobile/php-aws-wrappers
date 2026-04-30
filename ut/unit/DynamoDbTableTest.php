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
}
