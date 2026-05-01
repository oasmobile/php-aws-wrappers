<?php

namespace Oasis\Mlib\AwsWrappers;

use Aws\CloudWatch\CloudWatchClient;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\Result;
use Oasis\Mlib\AwsWrappers\DynamoDb\MultiQueryCommandWrapper;
use Oasis\Mlib\AwsWrappers\DynamoDb\ParallelScanCommandWrapper;
use Oasis\Mlib\AwsWrappers\DynamoDb\QueryCommandWrapper;
use Oasis\Mlib\AwsWrappers\DynamoDb\ScanCommandWrapper;

class DynamoDbTable
{
    protected DynamoDbClient $dbClient;
    
    protected array $config;
    
    protected readonly string $tableName;
    protected array $attributeTypes = [];
    
    public function __construct(array $awsConfig, string $tableName, array $attributeTypes = [])
    {
        $dp                   = new AwsConfigDataProvider($awsConfig, '2012-08-10');
        $this->dbClient       = new DynamoDbClient($dp->getConfig());
        $this->tableName      = $tableName;
        $this->attributeTypes = $attributeTypes;
    }
    
    public function addGlobalSecondaryIndex(DynamoDbIndex $gsi, int $readCapacity = 5, int $writeCapacity = 5): void
    {
        if ($this->getGlobalSecondaryIndices(sprintf("/%s/", preg_quote($gsi->getName(), "/")))) {
            throw new \RuntimeException("Global Secondary Index exists, name = " . $gsi->getName());
        }
        $args = [
            'AttributeDefinitions'        => $gsi->getAttributeDefinitions(false),
            'GlobalSecondaryIndexUpdates' => [
                [
                    'Create' => [
                        'IndexName'             => $gsi->getName(),
                        'KeySchema'             => $gsi->getKeySchema(),
                        'Projection'            => $gsi->getProjection(),
                        'ProvisionedThroughput' => [
                            'ReadCapacityUnits'  => $readCapacity,
                            'WriteCapacityUnits' => $writeCapacity,
                        ],
                    ],
                ],
            ],
            'TableName'                   => $this->tableName,
        ];
        $this->dbClient->updateTable($args);
    }
    
    public function batchDelete(array $objs,
                                int $concurrency = 10,
                                int $maxDelay = 15000): void
    {
        $this->doBatchWrite(false, $objs, $concurrency, false, 0, $maxDelay);
    }
    
    public function batchGet(array $keys,
                             bool $isConsistentRead = false,
                             int $concurrency = 10,
                             array $projectedFields = [],
                             bool $keyIsTyped = false,
                             int $retryDelay = 0,
                             int $maxDelay = 15000
    ): array
    {
        $mappingArgs = [];
        if ($projectedFields) {
            $fieldsMapping = [];
            foreach ($projectedFields as $idx => $field) {
                $projectedFields[$idx]   = $escaped = '#' . $field;
                $fieldsMapping[$escaped] = $field;
            }
            $mappingArgs['ProjectionExpression']     = \implode(', ', $projectedFields);
            $mappingArgs['ExpressionAttributeNames'] = $fieldsMapping;
        }
        
        $returnSet     = [];
        $promises      = [];
        $reads         = [];
        $unprocessed   = [];
        $flushCallback = function (int $limit = 100) use (
            &$mappingArgs,
            &$promises,
            &$reads,
            &$unprocessed,
            $isConsistentRead,
            &$returnSet
        ): void {
            if (count($reads) >= $limit) {
                $reqArgs    = [
                    "RequestItems" => [
                        $this->tableName => \array_merge(
                            $mappingArgs,
                            [
                                "Keys"           => $reads,
                                "ConsistentRead" => $isConsistentRead,
                            ]
                        ),
                    ],
                ];
                $promise    = $this->dbClient->batchGetItemAsync($reqArgs);
                $promises[] = $promise;
                $reads      = [];
            }
        };
        foreach ($keys as $key) {
            $keyItem = $keyIsTyped ? DynamoDbItem::createFromTypedArray($key) :
                DynamoDbItem::createFromArray($key, $this->attributeTypes);
            $req     = $keyItem->getData();
            $reads[] = $req;
            call_user_func($flushCallback);
        }
        call_user_func($flushCallback, 1);

        \GuzzleHttp\Promise\Each::ofLimit(
            $promises,
            $concurrency,
            function (Result $result) use (&$unprocessed, &$returnSet): void {
                $unprocessedKeys = $result['UnprocessedKeys'];
                if (isset($unprocessedKeys[$this->tableName]["Keys"])) {
                    $currentUnprocessed = $unprocessedKeys[$this->tableName]["Keys"];
                    mdebug("Unprocessed = %d", count($currentUnprocessed));
                    foreach ($currentUnprocessed as $action) {
                        $unprocessed[] = $action;
                    }
                }
                if (isset($result['Responses'][$this->tableName])) {
                    foreach ($result['Responses'][$this->tableName] as $item) {
                        $item        = DynamoDbItem::createFromTypedArray($item);
                        $returnSet[] = $item->toArray();
                    }
                }
            },
            function ($e): void {
                merror("Exception got: %s!", get_class($e));
                if ($e instanceof DynamoDbException) {
                    mtrace(
                        $e,
                        "Exception while batch updating dynamo db item, aws code = "
                        . $e->getAwsErrorCode()
                        . ", type = "
                        . $e->getAwsErrorType()
                    );
                }
                throw $e;
            }
        )->wait();
        
        if ($unprocessed) {
            $retryDelay = $retryDelay ? : 925;
            mdebug("sleeping $retryDelay ms");
            usleep($retryDelay * 1000);
            $nextRetry = $retryDelay * 1.2;
            if ($nextRetry > $maxDelay) {
                $nextRetry = $maxDelay;
            }
            $returnSet = array_merge(
                $returnSet,
                $this->batchGet($unprocessed, $isConsistentRead, $concurrency, $projectedFields, true, (int)$nextRetry)
            );
        }
        
        return $returnSet;
    }
    
    public function batchPut(array $objs,
                             int $concurrency = 10,
                             int $maxDelay = 15000): void
    {
        $this->doBatchWrite(true, $objs, $concurrency, false, 0, $maxDelay);
    }
    
    public function delete(array $keys): void
    {
        $keyItem = DynamoDbItem::createFromArray($keys, $this->attributeTypes);
        
        $requestArgs = [
            "TableName" => $this->tableName,
            "Key"       => $keyItem->getData(),
        ];
        
        $this->dbClient->deleteItem($requestArgs);
    }
    
    public function deleteGlobalSecondaryIndex(string $indexName): void
    {
        if (!$this->getGlobalSecondaryIndices(sprintf("/%s/", preg_quote($indexName, "/")))) {
            throw new \RuntimeException("Global Secondary Index doesn't exist, name = $indexName");
        }
        
        $args = [
            'GlobalSecondaryIndexUpdates' => [
                [
                    'Delete' => [
                        'IndexName' => $indexName,
                    ],
                ],
            ],
            'TableName'                   => $this->tableName,
        ];
        $this->dbClient->updateTable($args);
    }
    
    public function describe(): array
    {
        $requestArgs = [
            "TableName" => $this->tableName,
        ];
        $result      = $this->dbClient->describeTable($requestArgs);
        
        return $result['Table'];
    }
    
    public function disableStream(): void
    {
        $args = [
            "TableName"           => $this->tableName,
            "StreamSpecification" => [
                'StreamEnabled' => false,
            ],
        ];
        $this->dbClient->updateTable($args);
    }
    
    public function enableStream(string $type = "NEW_AND_OLD_IMAGES"): void
    {
        $args = [
            "TableName"           => $this->tableName,
            "StreamSpecification" => [
                'StreamEnabled'  => true,
                'StreamViewType' => $type,
            ],
        ];
        $this->dbClient->updateTable($args);
    }
    
    public function get(array $keys, bool $is_consistent_read = false, array $projectedFields = []): ?array
    {
        $keyItem     = DynamoDbItem::createFromArray($keys, $this->attributeTypes);
        $requestArgs = [
            "TableName" => $this->tableName,
            "Key"       => $keyItem->getData(),
        ];
        if ($projectedFields) {
            $fieldsMapping = [];
            foreach ($projectedFields as $idx => $field) {
                $projectedFields[$idx]   = $escaped = '#' . $field;
                $fieldsMapping[$escaped] = $field;
            }
            $requestArgs['ProjectionExpression']     = \implode(', ', $projectedFields);
            $requestArgs['ExpressionAttributeNames'] = $fieldsMapping;
        }
        if ($is_consistent_read) {
            $requestArgs["ConsistentRead"] = true;
        }
        
        $result = $this->dbClient->getItem($requestArgs);
        if ($result['Item']) {
            $item = DynamoDbItem::createFromTypedArray((array)$result['Item']);
            
            return $item->toArray();
        }
        else {
            return null;
        }
    }
    
    public function isStreamEnabled(?string &$streamViewType = null): bool
    {
        $streamViewType = null;
        $description    = $this->describe();
        
        if (!isset($description['StreamSpecification'])) {
            return false;
        }
        
        $isEnabled      = $description['StreamSpecification']['StreamEnabled'];
        $streamViewType = $description['StreamSpecification']['StreamViewType'];
        
        return $isEnabled;
    }
    
    public function multiQueryAndRun(callable $callback,
                                     string $hashKeyName,
                                     array $hashKeyValues,
                                     string $rangeKeyConditions,
                                     array $fieldsMapping,
                                     array $paramsMapping,
                                     string|bool $indexName = DynamoDbIndex::PRIMARY_INDEX,
                                     string $filterExpression = '',
                                     int $evaluationLimit = 30,
                                     bool $isConsistentRead = false,
                                     bool $isAscendingOrder = true,
                                     int $concurrency = 10,
                                     array $projectedFields = []
    ): void
    {
        $wrapper = new MultiQueryCommandWrapper();
        $wrapper(
            $this->dbClient,
            $this->tableName,
            $callback,
            $hashKeyName,
            $hashKeyValues,
            $rangeKeyConditions,
            $fieldsMapping,
            $paramsMapping,
            $indexName,
            $filterExpression,
            $evaluationLimit,
            $isConsistentRead,
            $isAscendingOrder,
            $concurrency,
            $projectedFields
        );
    }
    
    public function parallelScanAndRun(int $parallel,
                                       callable $callback,
                                       string $filterExpression = '',
                                       array $fieldsMapping = [],
                                       array $paramsMapping = [],
                                       string|bool $indexName = DynamoDbIndex::PRIMARY_INDEX,
                                       bool $isConsistentRead = false,
                                       bool $isAscendingOrder = true,
                                       array $projectedFields = []): void
    {
        $wrapper = new ParallelScanCommandWrapper();
        
        $wrapper(
            $this->dbClient,
            $this->tableName,
            $callback,
            $filterExpression,
            $fieldsMapping,
            $paramsMapping,
            $indexName,
            1000,
            $isConsistentRead,
            $isAscendingOrder,
            $parallel,
            false,
            $projectedFields
        );
    }
    
    public function query(string $keyConditions,
                          array $fieldsMapping,
                          array $paramsMapping,
                          string|bool $indexName = DynamoDbIndex::PRIMARY_INDEX,
                          string $filterExpression = '',
                          mixed &$lastKey = null,
                          int $evaluationLimit = 30,
                          bool $isConsistentRead = false,
                          bool $isAscendingOrder = true,
                          array $projectedFields = []
    ): array
    {
        $wrapper = new QueryCommandWrapper();
        
        $ret = [];
        $wrapper(
            $this->dbClient,
            $this->tableName,
            function (array $item) use (&$ret): void {
                $ret[] = $item;
            },
            $keyConditions,
            $fieldsMapping,
            $paramsMapping,
            $indexName,
            $filterExpression,
            $lastKey,
            $evaluationLimit,
            $isConsistentRead,
            $isAscendingOrder,
            false,
            $projectedFields
        );
        
        return $ret;
    }
    
    public function queryAndRun(callable $callback,
                                string $keyConditions,
                                array $fieldsMapping,
                                array $paramsMapping,
                                string|bool $indexName = DynamoDbIndex::PRIMARY_INDEX,
                                string $filterExpression = '',
                                bool $isConsistentRead = false,
                                bool $isAscendingOrder = true,
                                array $projectedFields = []): void
    {
        $lastKey           = null;
        $stoppedByCallback = false;
        $wrapper           = new QueryCommandWrapper();
        
        do {
            $wrapper(
                $this->dbClient,
                $this->tableName,
                function (array $item) use (&$stoppedByCallback, $callback): void {
                    if ($stoppedByCallback) {
                        return;
                    }
                    
                    $ret = call_user_func($callback, $item);
                    if ($ret === false) {
                        $stoppedByCallback = true;
                    }
                },
                $keyConditions,
                $fieldsMapping,
                $paramsMapping,
                $indexName,
                $filterExpression,
                $lastKey,
                1000,
                $isConsistentRead,
                $isAscendingOrder,
                false,
                $projectedFields
            );
        } while ($lastKey != null && !$stoppedByCallback);
    }
    
    public function queryCount(string $keyConditions,
                               array $fieldsMapping,
                               array $paramsMapping,
                               string|bool $indexName = DynamoDbIndex::PRIMARY_INDEX,
                               string $filterExpression = '',
                               bool $isConsistentRead = false,
                               bool $isAscendingOrder = true
    ): int
    {
        $ret     = 0;
        $lastKey = null;
        $wrapper = new QueryCommandWrapper();
        do {
            $ret += $wrapper(
                $this->dbClient,
                $this->tableName,
                function (): void {
                },
                $keyConditions,
                $fieldsMapping,
                $paramsMapping,
                $indexName,
                $filterExpression,
                $lastKey,
                10000,
                $isConsistentRead,
                $isAscendingOrder,
                true,
                []
            );
        } while ($lastKey != null);
        
        return $ret;
    }
    
    public function scan(string $filterExpression = '',
                         array $fieldsMapping = [],
                         array $paramsMapping = [],
                         string|bool $indexName = DynamoDbIndex::PRIMARY_INDEX,
                         mixed &$lastKey = null,
                         int $evaluationLimit = 30,
                         bool $isConsistentRead = false,
                         bool $isAscendingOrder = true,
                         array $projectedFields = []
    ): array
    {
        $wrapper = new ScanCommandWrapper();
        
        $ret = [];
        $wrapper(
            $this->dbClient,
            $this->tableName,
            function (array $item) use (&$ret): void {
                $ret[] = $item;
            },
            $filterExpression,
            $fieldsMapping,
            $paramsMapping,
            $indexName,
            $lastKey,
            $evaluationLimit,
            $isConsistentRead,
            $isAscendingOrder,
            false,
            $projectedFields
        );
        
        return $ret;
    }
    
    public function scanAndRun(callable $callback,
                               string $filterExpression = '',
                               array $fieldsMapping = [],
                               array $paramsMapping = [],
                               string|bool $indexName = DynamoDbIndex::PRIMARY_INDEX,
                               bool $isConsistentRead = false,
                               bool $isAscendingOrder = true,
                               array $projectedFields = []): void
    {
        $lastKey           = null;
        $stoppedByCallback = false;
        $wrapper           = new ScanCommandWrapper();
        
        do {
            $wrapper(
                $this->dbClient,
                $this->tableName,
                function (array $item) use (&$stoppedByCallback, $callback): void {
                    if ($stoppedByCallback) {
                        return;
                    }
                    
                    $ret = call_user_func($callback, $item);
                    if ($ret === false) {
                        $stoppedByCallback = true;
                    }
                },
                $filterExpression,
                $fieldsMapping,
                $paramsMapping,
                $indexName,
                $lastKey,
                1000,
                $isConsistentRead,
                $isAscendingOrder,
                false,
                $projectedFields
            );
        } while ($lastKey != null && !$stoppedByCallback);
    }
    
    public function scanCount(string $filterExpression = '',
                              array $fieldsMapping = [],
                              array $paramsMapping = [],
                              string|bool $indexName = DynamoDbIndex::PRIMARY_INDEX,
                              bool $isConsistentRead = false,
                              int $parallel = 10
    ): int
    {
        $lastKey = null;
        $wrapper = new ParallelScanCommandWrapper();
        
        return $wrapper(
            $this->dbClient,
            $this->tableName,
            function (): void {
            },
            $filterExpression,
            $fieldsMapping,
            $paramsMapping,
            $indexName,
            10000,
            $isConsistentRead,
            true,
            $parallel,
            true,
            []
        );
    }
    
    public function set(array $obj, array $checkValues = []): bool
    {
        $requestArgs = [
            "TableName" => $this->tableName,
        ];
        
        if ($checkValues) {
            $conditionExpressions      = [];
            $expressionAttributeNames  = [];
            $expressionAttributeValues = [];
            
            $typedCheckValues = DynamoDbItem::createFromArray($checkValues)->getData();
            $casCounter       = 0;
            foreach ($typedCheckValues as $field => $checkValue) {
                $casCounter++;
                $fieldPlaceholder = "#field$casCounter";
                $valuePlaceholder = ":val$casCounter";
                if (isset($checkValue['NULL'])) {
                    $conditionExpressions[] = "(attribute_not_exists($fieldPlaceholder) OR $fieldPlaceholder = $valuePlaceholder)";
                }
                else {
                    $conditionExpressions[] = "$fieldPlaceholder = $valuePlaceholder";
                }
                $expressionAttributeNames[$fieldPlaceholder]  = $field;
                $expressionAttributeValues[$valuePlaceholder] = $checkValue;
            }
            
            $requestArgs['ConditionExpression']       = implode(" AND ", $conditionExpressions);
            $requestArgs['ExpressionAttributeNames']  = $expressionAttributeNames;
            $requestArgs['ExpressionAttributeValues'] = $expressionAttributeValues;
        }
        $item                = DynamoDbItem::createFromArray($obj, $this->attributeTypes);
        $requestArgs['Item'] = $item->getData();
        
        try {
            $this->dbClient->putItem($requestArgs);
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() == "ConditionalCheckFailedException") {
                return false;
            }
            mtrace(
                $e,
                "Exception while setting dynamo db item, aws code = "
                . $e->getAwsErrorCode()
                . ", type = "
                . $e->getAwsErrorType()
            );
            throw $e;
        }
        
        return true;
    }

    public function getConsumedCapacity(string|bool $indexName = DynamoDbIndex::PRIMARY_INDEX,
                                        int $period = 60,
                                        int $num_of_period = 5,
                                        int $timeshift = -300): array
    {
        $cloudwatch = new CloudWatchClient(
            [
                "profile" => $this->config['profile'],
                "region"  => $this->config['region'],
                "version" => "2010-08-01",
            ]
        );
        
        $end   = time() + $timeshift;
        $end   -= $end % $period;
        $start = $end - $num_of_period * $period;
        
        $requestArgs = [
            "Namespace"  => "AWS/DynamoDB",
            "Dimensions" => [
                [
                    "Name"  => "TableName",
                    "Value" => $this->tableName,
                ],
            ],
            "MetricName" => "ConsumedReadCapacityUnits",
            "StartTime"  => date('c', $start),
            "EndTime"    => date('c', $end),
            "Period"     => 60,
            "Statistics" => ["Sum"],
        ];
        if ($indexName != DynamoDbIndex::PRIMARY_INDEX) {
            $requestArgs['Dimensions'][] = [
                "Name"  => "GlobalSecondaryIndexName",
                "Value" => $indexName,
            ];
        }
        
        $result      = $cloudwatch->getMetricStatistics($requestArgs);
        $total_read  = 0;
        $total_count = 0;
        foreach ($result['Datapoints'] as $data) {
            $total_count++;
            $total_read += $data['Sum'];
        }
        $readUsed = $total_count ? ($total_read / $total_count / 60) : 0;
        
        $requestArgs['MetricName'] = 'ConsumedWriteCapacityUnits';
        $result                    = $cloudwatch->getMetricStatistics($requestArgs);
        $total_write               = 0;
        $total_count               = 0;
        foreach ($result['Datapoints'] as $data) {
            $total_count++;
            $total_write += $data['Sum'];
        }
        $writeUsed = $total_count ? ($total_write / $total_count / 60) : 0;
        
        return [
            $readUsed,
            $writeUsed,
        ];
    }
    
    public function getDbClient(): DynamoDbClient
    {
        return $this->dbClient;
    }
    
    public function getGlobalSecondaryIndices(string $namePattern = "/.*/"):  array
    {
        $description = $this->describe();
        $gsiDefs     = isset($description['GlobalSecondaryIndexes']) ? $description['GlobalSecondaryIndexes'] : null;
        if (!$gsiDefs) {
            return [];
        }
        $attrDefs = [];
        foreach ($description['AttributeDefinitions'] as $attributeDefinition) {
            $attrDefs[$attributeDefinition['AttributeName']] = $attributeDefinition['AttributeType'];
        }
        
        $gsis = [];
        foreach ($gsiDefs as $gsiDef) {
            $indexName = $gsiDef['IndexName'];
            if (!preg_match($namePattern, $indexName)) {
                continue;
            }
            $hashKey      = null;
            $hashKeyType  = null;
            $rangeKey     = null;
            $rangeKeyType = null;
            foreach ($gsiDef['KeySchema'] as $keySchema) {
                switch ($keySchema['KeyType']) {
                    case "HASH":
                        $hashKey     = $keySchema['AttributeName'];
                        $hashKeyType = $attrDefs[$hashKey];
                        break;
                    case "RANGE":
                        $rangeKey     = $keySchema['AttributeName'];
                        $rangeKeyType = $attrDefs[$rangeKey];
                        break;
                }
            }
            $projectionType      = $gsiDef['Projection']['ProjectionType'];
            $projectedAttributes = isset($gsiDef['Projection']['NonKeyAttributes']) ?
                $gsiDef['Projection']['NonKeyAttributes'] : [];
            $gsi                 = new DynamoDbIndex(
                $hashKey,
                $hashKeyType,
                $rangeKey,
                $rangeKeyType,
                $projectionType,
                $projectedAttributes
            );
            $gsi->setName($indexName);
            $gsis[$indexName] = $gsi;
        }
        
        return $gsis;
    }
    
    public function getLocalSecondaryIndices(): array
    {
        $description = $this->describe();
        $lsiDefs     = isset($description['LocalSecondaryIndexes']) ? $description['LocalSecondaryIndexes'] : null;
        if (!$lsiDefs) {
            return [];
        }
        $attrDefs = [];
        foreach ($description['AttributeDefinitions'] as $attributeDefinition) {
            $attrDefs[$attributeDefinition['AttributeName']] = $attributeDefinition['AttributeType'];
        }
        
        $lsis = [];
        foreach ($lsiDefs as $lsiDef) {
            $hashKey      = null;
            $hashKeyType  = null;
            $rangeKey     = null;
            $rangeKeyType = null;
            foreach ($lsiDef['KeySchema'] as $keySchema) {
                switch ($keySchema['KeyType']) {
                    case "HASH":
                        $hashKey     = $keySchema['AttributeName'];
                        $hashKeyType = $attrDefs[$hashKey];
                        break;
                    case "RANGE":
                        $rangeKey     = $keySchema['AttributeName'];
                        $rangeKeyType = $attrDefs[$rangeKey];
                        break;
                }
            }
            $projectionType      = $lsiDef['Projection']['ProjectionType'];
            $projectedAttributes = isset($lsiDef['Projection']['NonKeyAttributes']) ?
                $lsiDef['Projection']['NonKeyAttributes'] : [];
            $lsi                 = new DynamoDbIndex(
                $hashKey,
                $hashKeyType,
                $rangeKey,
                $rangeKeyType,
                $projectionType,
                $projectedAttributes
            );
            $lsi->setName($lsiDef['IndexName']);
            $lsis[$lsi->getName()] = $lsi;
        }
        
        return $lsis;
    }
    
    public function getPrimaryIndex(): DynamoDbIndex
    {
        $description = $this->describe();
        $attrDefs    = [];
        foreach ($description['AttributeDefinitions'] as $attributeDefinition) {
            $attrDefs[$attributeDefinition['AttributeName']] = $attributeDefinition['AttributeType'];
        }
        
        $hashKey      = null;
        $hashKeyType  = null;
        $rangeKey     = null;
        $rangeKeyType = null;
        $keySchemas   = $description['KeySchema'];
        foreach ($keySchemas as $keySchema) {
            switch ($keySchema['KeyType']) {
                case "HASH":
                    $hashKey     = $keySchema['AttributeName'];
                    $hashKeyType = $attrDefs[$hashKey];
                    break;
                case "RANGE":
                    $rangeKey     = $keySchema['AttributeName'];
                    $rangeKeyType = $attrDefs[$rangeKey];
                    break;
            }
        }
        $primaryIndex = new DynamoDbIndex(
            $hashKey,
            $hashKeyType,
            $rangeKey,
            $rangeKeyType
        );
        
        return $primaryIndex;
    }
    
    public function getTableName(): string
    {
        return $this->tableName;
    }
    
    public function getThroughput(string|bool $indexName = DynamoDbIndex::PRIMARY_INDEX): array
    {
        $result = $this->describe();
        if ($indexName == DynamoDbIndex::PRIMARY_INDEX) {
            return [
                $result['Table']['ProvisionedThroughput']['ReadCapacityUnits'],
                $result['Table']['ProvisionedThroughput']['WriteCapacityUnits'],
            ];
        }
        else {
            foreach ($result['Table']['GlobalSecondaryIndexes'] as $gsi) {
                if ($gsi['IndexName'] != $indexName) {
                    continue;
                }
                
                return [
                    $gsi['ProvisionedThroughput']['ReadCapacityUnits'],
                    $gsi['ProvisionedThroughput']['WriteCapacityUnits'],
                ];
            }
        }
        
        throw new \UnexpectedValueException("Cannot find index named $indexName");
    }
    
    public function setAttributeType(string $name, string $type): static
    {
        $this->attributeTypes[$name] = $type;
        
        return $this;
    }
    
    public function setThroughput(int $read, int $write, string|bool $indexName = DynamoDbIndex::PRIMARY_INDEX): void
    {
        $requestArgs  = [
            "TableName" => $this->tableName,
        ];
        $updateObject = [
            'ReadCapacityUnits'  => $read,
            'WriteCapacityUnits' => $write,
        ];
        if ($indexName == DynamoDbIndex::PRIMARY_INDEX) {
            $requestArgs['ProvisionedThroughput'] = $updateObject;
        }
        else {
            $requestArgs['GlobalSecondaryIndexUpdates'] = [
                [
                    'Update' => [
                        'IndexName'             => $indexName,
                        'ProvisionedThroughput' => $updateObject,
                    ],
                ],
            ];
        }
        
        try {
            $this->dbClient->updateTable($requestArgs);
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() == "ValidationException"
                && $e->getAwsErrorType() == "client"
                && !$e->isConnectionError()
            ) {
                mwarning("Throughput not updated, because new value is identical to old value!");
            }
            else {
                throw $e;
            }
        }
    }
    
    protected function doBatchWrite(bool $isPut,
                                    array $objs,
                                    int $concurrency = 10,
                                    bool $objIsTyped = false,
                                    int $retryDelay = 0,
                                    int $maxDelay = 15000): void
    {
        $promises    = [];
        $writes      = [];
        $unprocessed = [];
        
        $flushCallback = function (int $limit = 25) use (&$promises, &$writes, &$unprocessed): void {
            if (count($writes) >= $limit) {
                $reqArgs = [
                    "RequestItems" => [
                        $this->tableName => $writes,
                    ],
                ];
                $promise    = $this->dbClient->batchWriteItemAsync($reqArgs);
                $promises[] = $promise;
                $writes     = [];
            }
        };
        foreach ($objs as $obj) {
            $item = $objIsTyped ? DynamoDbItem::createFromTypedArray($obj) :
                DynamoDbItem::createFromArray($obj, $this->attributeTypes);
            if ($isPut) {
                $req = [
                    "PutRequest" => [
                        "Item" => $item->getData(),
                    ],
                ];
            }
            else {
                $req = [
                    "DeleteRequest" => [
                        "Key" => $item->getData(),
                    ],
                ];
            }
            $writes[] = $req;
            call_user_func($flushCallback);
        }
        call_user_func($flushCallback, 1);
        
        \GuzzleHttp\Promise\Each::ofLimit(
            $promises,
            $concurrency,
            function (Result $result) use ($isPut, &$unprocessed): void {
                $unprocessedItems = $result['UnprocessedItems'];
                if (isset($unprocessedItems[$this->tableName])) {
                    $currentUnprocessed = $unprocessedItems[$this->tableName];
                    mdebug("Unprocessed = %d", count($currentUnprocessed));
                    foreach ($currentUnprocessed as $action) {
                        if ($isPut) {
                            $unprocessed[] = $action['PutRequest']['Item'];
                        }
                        else {
                            $unprocessed[] = $action['DeleteRequest']['Key'];
                        }
                    }
                }
            },
            function ($e): void {
                merror("Exception got: %s!", get_class($e));
                if ($e instanceof DynamoDbException) {
                    mtrace(
                        $e,
                        "Exception while batch updating dynamo db item, aws code = "
                        . $e->getAwsErrorCode()
                        . ", type = "
                        . $e->getAwsErrorType()
                    );
                }
                throw $e;
            }
        )->wait();
        
        if ($unprocessed) {
            $retryDelay = $retryDelay ? : 925;
            $nextRetry  = $retryDelay * 1.2;
            if ($nextRetry > $maxDelay) {
                $nextRetry = $maxDelay;
            }
            mdebug("sleeping $retryDelay ms");
            usleep($retryDelay * 1000);
            $this->doBatchWrite($isPut, $unprocessed, $concurrency, true, (int)$nextRetry);
        }
    }
}
