<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\Result;
use GuzzleHttp\Promise\FulfilledPromise;
use Oasis\Mlib\AwsWrappers\DynamoDbIndex;
use Oasis\Mlib\AwsWrappers\DynamoDbItem;
use Oasis\Mlib\AwsWrappers\DynamoDbTable;

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

class DynamoDbTableTest extends \PHPUnit_Framework_TestCase
{
    /** @var DynamoDbClient|\PHPUnit_Framework_MockObject_MockObject */
    private $mockClient;

    /** @var TestableDynamoDbTable */
    private $table;

    function setUp()
    {
        $this->mockClient = $this->getMockBuilder(DynamoDbClient::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getItem',
                'putItem',
                'deleteItem',
                'batchGetItemAsync',
                'batchWriteItemAsync',
                'queryAsync',
                'scanAsync',
                'describeTable',
                'updateTable',
            ])
            ->getMock();

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

        $this->mockClient->expects($this->once())
            ->method('getItem')
            ->with($this->callback(function ($args) {
                return $args['TableName'] === 'test-table'
                    && isset($args['Key']['id']);
            }))
            ->willReturn(new Result(['Item' => $typedItem]));

        $result = $this->table->get(['id' => '123']);

        $this->assertSame('123', $result['id']);
        $this->assertSame('Alice', $result['name']);
    }

    public function testGetReturnsNullWhenItemNotFound()
    {
        $this->mockClient->expects($this->once())
            ->method('getItem')
            ->willReturn(new Result(['Item' => null]));

        $result = $this->table->get(['id' => '999']);

        $this->assertNull($result);
    }

    public function testGetWithConsistentRead()
    {
        $this->mockClient->expects($this->once())
            ->method('getItem')
            ->with($this->callback(function ($args) {
                return isset($args['ConsistentRead']) && $args['ConsistentRead'] === true;
            }))
            ->willReturn(new Result(['Item' => ['id' => ['S' => '1']]]));

        $this->table->get(['id' => '1'], true);
    }

    public function testGetWithProjectedFields()
    {
        $this->mockClient->expects($this->once())
            ->method('getItem')
            ->with($this->callback(function ($args) {
                // Verify projection-related keys exist in the request
                return isset($args['ExpressionAttributeNames']);
            }))
            ->willReturn(new Result(['Item' => ['id' => ['S' => '1']]]));

        // Note: the source code uses deprecated implode() parameter order on PHP 7.4,
        // which triggers a deprecation warning but still works.
        @$this->table->get(['id' => '1'], false, ['name', 'email']);
    }

    // ================================================================
    // 3. set()
    // ================================================================

    public function testSetReturnsTrueOnSuccess()
    {
        $this->mockClient->expects($this->once())
            ->method('putItem')
            ->with($this->callback(function ($args) {
                return $args['TableName'] === 'test-table'
                    && isset($args['Item']);
            }))
            ->willReturn(new Result([]));

        $result = $this->table->set(['id' => '1', 'name' => 'Bob']);

        $this->assertTrue($result);
    }

    public function testSetWithCheckValuesBuildsConditionExpression()
    {
        $this->mockClient->expects($this->once())
            ->method('putItem')
            ->with($this->callback(function ($args) {
                return isset($args['ConditionExpression'])
                    && isset($args['ExpressionAttributeNames'])
                    && isset($args['ExpressionAttributeValues']);
            }))
            ->willReturn(new Result([]));

        $result = $this->table->set(
            ['id' => '1', 'name' => 'Bob'],
            ['version' => 1]
        );

        $this->assertTrue($result);
    }

    public function testSetReturnsFalseOnConditionalCheckFailedException()
    {
        $exception = $this->getMockBuilder(DynamoDbException::class)
            ->disableOriginalConstructor()
            ->setMethods(['getAwsErrorCode', 'getAwsErrorType'])
            ->getMock();
        $exception->method('getAwsErrorCode')->willReturn('ConditionalCheckFailedException');

        $this->mockClient->expects($this->once())
            ->method('putItem')
            ->willThrowException($exception);

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
        $this->mockClient->expects($this->once())
            ->method('deleteItem')
            ->with($this->callback(function ($args) {
                return $args['TableName'] === 'test-table'
                    && isset($args['Key']['id']);
            }));

        $this->table->delete(['id' => '1']);
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

        $this->mockClient->expects($this->once())
            ->method('batchGetItemAsync')
            ->willReturn(new FulfilledPromise($result));

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

        $this->mockClient->expects($this->once())
            ->method('batchWriteItemAsync')
            ->with($this->callback(function ($args) {
                $items = $args['RequestItems']['test-table'];
                // batchPut uses PutRequest
                return isset($items[0]['PutRequest']);
            }))
            ->willReturn(new FulfilledPromise($result));

        $this->table->batchPut([
            ['id' => '1', 'name' => 'Alice'],
            ['id' => '2', 'name' => 'Bob'],
        ]);
    }

    // ================================================================
    // 7. batchDelete()
    // ================================================================

    public function testBatchDelete()
    {
        $result = new Result([
            'UnprocessedItems' => [],
        ]);

        $this->mockClient->expects($this->once())
            ->method('batchWriteItemAsync')
            ->with($this->callback(function ($args) {
                $items = $args['RequestItems']['test-table'];
                // batchDelete uses DeleteRequest
                return isset($items[0]['DeleteRequest']);
            }))
            ->willReturn(new FulfilledPromise($result));

        $this->table->batchDelete([
            ['id' => '1'],
            ['id' => '2'],
        ]);
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

        $this->mockClient->expects($this->once())
            ->method('queryAsync')
            ->willReturn(new FulfilledPromise($result));

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

        $this->mockClient->expects($this->once())
            ->method('scanAsync')
            ->willReturn($promise);

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

        $this->mockClient->expects($this->once())
            ->method('describeTable')
            ->with(['TableName' => 'test-table'])
            ->willReturn(new Result(['Table' => $tableDesc]));

        $result = $this->table->describe();

        $this->assertSame('test-table', $result['TableName']);
        $this->assertSame('ACTIVE', $result['TableStatus']);
    }

    // ================================================================
    // 11. addGlobalSecondaryIndex()
    // ================================================================

    public function testAddGlobalSecondaryIndex()
    {
        // describe returns no existing GSIs
        $this->mockClient->expects($this->once())
            ->method('describeTable')
            ->willReturn(new Result([
                'Table' => [
                    'TableName'          => 'test-table',
                    'AttributeDefinitions' => [],
                ],
            ]));

        $this->mockClient->expects($this->once())
            ->method('updateTable')
            ->with($this->callback(function ($args) {
                return isset($args['GlobalSecondaryIndexUpdates'][0]['Create']);
            }));

        $gsi = new DynamoDbIndex('email', DynamoDbItem::ATTRIBUTE_TYPE_STRING);
        $gsi->setName('email-index');

        $this->table->addGlobalSecondaryIndex($gsi);
    }

    public function testAddGlobalSecondaryIndexThrowsIfAlreadyExists()
    {
        $this->setExpectedException(\RuntimeException::class, 'Global Secondary Index exists');

        // describe returns existing GSI with matching name
        $this->mockClient->expects($this->once())
            ->method('describeTable')
            ->willReturn(new Result([
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
        $this->mockClient->expects($this->once())
            ->method('describeTable')
            ->willReturn(new Result([
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

        $this->mockClient->expects($this->once())
            ->method('updateTable')
            ->with($this->callback(function ($args) {
                return isset($args['GlobalSecondaryIndexUpdates'][0]['Delete'])
                    && $args['GlobalSecondaryIndexUpdates'][0]['Delete']['IndexName'] === 'email-index';
            }));

        $this->table->deleteGlobalSecondaryIndex('email-index');
    }

    public function testDeleteGlobalSecondaryIndexThrowsIfNotExists()
    {
        $this->setExpectedException(\RuntimeException::class, "Global Secondary Index doesn't exist");

        $this->mockClient->expects($this->once())
            ->method('describeTable')
            ->willReturn(new Result([
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
        $this->mockClient->expects($this->once())
            ->method('describeTable')
            ->willReturn(new Result([
                'Table' => [
                    'TableName' => 'test-table',
                    'AttributeDefinitions' => [
                        ['AttributeName' => 'email', 'AttributeType' => 'S'],
                        ['AttributeName' => 'age', 'AttributeType' => 'N'],
                    ],
                    'GlobalSecondaryIndexes' => [
                        [
                            'IndexName' => 'email-index',
                            'KeySchema' => [
                                ['AttributeName' => 'email', 'KeyType' => 'HASH'],
                            ],
                            'Projection' => ['ProjectionType' => 'ALL'],
                        ],
                        [
                            'IndexName' => 'age-index',
                            'KeySchema' => [
                                ['AttributeName' => 'age', 'KeyType' => 'HASH'],
                            ],
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
        $this->mockClient->expects($this->once())
            ->method('describeTable')
            ->willReturn(new Result([
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
        $this->mockClient->expects($this->once())
            ->method('describeTable')
            ->willReturn(new Result([
                'Table' => [
                    'TableName' => 'test-table',
                    'AttributeDefinitions' => [
                        ['AttributeName' => 'email', 'AttributeType' => 'S'],
                        ['AttributeName' => 'age', 'AttributeType' => 'N'],
                    ],
                    'GlobalSecondaryIndexes' => [
                        [
                            'IndexName' => 'email-index',
                            'KeySchema' => [
                                ['AttributeName' => 'email', 'KeyType' => 'HASH'],
                            ],
                            'Projection' => ['ProjectionType' => 'ALL'],
                        ],
                        [
                            'IndexName' => 'age-index',
                            'KeySchema' => [
                                ['AttributeName' => 'age', 'KeyType' => 'HASH'],
                            ],
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
        $this->mockClient->expects($this->once())
            ->method('updateTable')
            ->with($this->callback(function ($args) {
                return $args['TableName'] === 'test-table'
                    && $args['StreamSpecification']['StreamEnabled'] === true
                    && $args['StreamSpecification']['StreamViewType'] === 'NEW_AND_OLD_IMAGES';
            }));

        $this->table->enableStream();
    }

    // ================================================================
    // 15. disableStream()
    // ================================================================

    public function testDisableStream()
    {
        $this->mockClient->expects($this->once())
            ->method('updateTable')
            ->with($this->callback(function ($args) {
                return $args['TableName'] === 'test-table'
                    && $args['StreamSpecification']['StreamEnabled'] === false;
            }));

        $this->table->disableStream();
    }

    // ================================================================
    // 16. isStreamEnabled()
    // ================================================================

    public function testIsStreamEnabledReturnsTrue()
    {
        $this->mockClient->expects($this->once())
            ->method('describeTable')
            ->willReturn(new Result([
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
        $this->mockClient->expects($this->once())
            ->method('describeTable')
            ->willReturn(new Result([
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
