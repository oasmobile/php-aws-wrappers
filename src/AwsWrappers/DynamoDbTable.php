<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-10-29
 * Time: 14:07
 */

namespace Oasis\Mlib\AwsWrappers;

use Aws\CloudWatch\CloudWatchClient;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\Result;
use Oasis\Mlib\Utils\ArrayDataProvider;

class DynamoDbTable
{
    const PRIMARY_INDEX = false;
    const NO_INDEX      = null;
    
    /** @var DynamoDbClient */
    protected $dbClient;
    
    protected $config;
    
    protected $tableName;
    protected $attributeTypes = [];
    
    function __construct(array $awsConfig, $tableName, $attributeTypes = [])
    {
        $dp                   = new ArrayDataProvider($awsConfig);
        $this->config         = [
            'version' => "2012-08-10",
            "profile" => $dp->getMandatory('profile'),
            "region"  => $dp->getMandatory('region'),
        ];
        $this->dbClient       = new DynamoDbClient($this->config);
        $this->tableName      = $tableName;
        $this->attributeTypes = $attributeTypes;
    }
    
    public function addGlobalSecondaryIndex(DynamoDbIndex $gsi, $readCapacity = 5, $writeCapacity = 5)
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
    
    public function batchPut(array $objs, $objIsTyped = false)
    {
        $promises    = [];
        $writes      = [];
        $unprocessed = [];
        
        $flushCallback = function ($limit = 25) use (&$promises, &$writes, &$unprocessed) {
            if (count($writes) >= $limit) {
                $reqArgs = [
                    "RequestItems" => [
                        $this->tableName => $writes,
                    ],
                ];
                //$reqArgs['ReturnConsumedCapacity'] = "TOTAL";
                $promise = $this->dbClient->batchWriteItemAsync($reqArgs);
                $promise->then(
                    function (Result $result) use (&$unprocessed) {
                        $unprocessedItems = $result['UnprocessedItems'];
                        if (isset($unprocessedItems[$this->tableName])) {
                            $currentUnprocessed = $unprocessedItems[$this->tableName];
                            mdebug("Unprocessed = %d", count($currentUnprocessed));
                            foreach ($currentUnprocessed as $action) {
                                $unprocessed[] = $action['PutRequest']['Item'];
                            }
                        }
                    },
                    function ($e) {
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
                        
                    }
                );
                $promises[] = $promise;
                $writes     = [];
            }
        };
        foreach ($objs as $obj) {
            $item     = $objIsTyped ? DynamoDbItem::createFromTypedArray($obj) : DynamoDbItem::createFromArray($obj);
            $req      = [
                "PutRequest" => [
                    "Item" => $item->getData(),
                ],
            ];
            $writes[] = $req;
            call_user_func($flushCallback);
        }
        call_user_func($flushCallback, 1);
        
        \GuzzleHttp\Promise\all($promises)->wait();
        
        if ($unprocessed) {
            $this->batchPut($unprocessed, true);
        }
    }
    
    public function count($conditions,
                          array $fields,
                          array $params,
                          $index_name = self::NO_INDEX,
                          $consistent_read = false)
    {
        $usingScan   = ($index_name === self::NO_INDEX);
        $command     = $usingScan ? "scan" : "query";
        $requestArgs = [
            "TableName" => $this->tableName,
            "Select"    => "COUNT",
        ];
        if ($conditions) {
            $conditionKey               = $usingScan ? "FilterExpression" : "KeyConditionExpression";
            $requestArgs[$conditionKey] = $conditions;
            
            if ($fields) {
                $requestArgs['ExpressionAttributeNames'] = $fields;
            }
            if ($params) {
                $paramsItem                               = DynamoDbItem::createFromArray($params);
                $requestArgs['ExpressionAttributeValues'] = $paramsItem->getData();
            }
        }
        if (!$usingScan) {
            $requestArgs['ConsistentRead'] = $consistent_read;
            if ($index_name !== self::PRIMARY_INDEX) {
                $requestArgs['IndexName'] = $index_name;
            }
        }
        
        $count   = 0;
        $scanned = 0;
        
        $last_key = null;
        do {
            if ($last_key) {
                $requestArgs['ExclusiveStartKey'] = $last_key;
            }
            $result   = call_user_func([$this->dbClient, $command], $requestArgs);
            $last_key = isset($result['LastEvaluatedKey']) ? $result['LastEvaluatedKey'] : null;
            $count += intval($result['Count']);
            $scanned += intval($result['ScannedCount']);
        } while ($last_key != null);
        
        mdebug("Count = $count from total scanned $scanned");
        
        return $count;
    }
    
    public function delete($keys)
    {
        $keyItem = DynamoDbItem::createFromArray($keys, $this->attributeTypes);
        
        $requestArgs = [
            "TableName" => $this->tableName,
            "Key"       => $keyItem->getData(),
        ];
        
        $this->dbClient->deleteItem($requestArgs);
    }
    
    public function deleteGlobalSecondaryIndex($indexName)
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
    
    public function describe()
    {
        $requestArgs = [
            "TableName" => $this->tableName,
        ];
        $result      = $this->dbClient->describeTable($requestArgs);
        
        return $result['Table'];
    }
    
    public function disableStream()
    {
        $args = [
            "TableName"           => $this->tableName,
            "StreamSpecification" => [
                'StreamEnabled' => false,
            ],
        ];
        $this->dbClient->updateTable($args);
    }
    
    public function enableStream($type = "NEW_AND_OLD_IMAGES")
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
    
    public function get(array $keys, $is_consistent_read = false)
    {
        $keyItem     = DynamoDbItem::createFromArray($keys, $this->attributeTypes);
        $requestArgs = [
            "TableName" => $this->tableName,
            "Key"       => $keyItem->getData(),
        ];
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
    
    public function isStreamEnabled(&$streamViewType = null)
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
    
    public function query($conditions,
                          array $fields,
                          array $params,
                          $index_name = self::PRIMARY_INDEX,
                          &$last_key = null,
                          $page_limit = 30,
                          $consistent_read = false)
    {
        $usingScan   = ($index_name === self::NO_INDEX);
        $command     = $usingScan ? "scan" : "query";
        $requestArgs = [
            "TableName"      => $this->tableName,
            'ConsistentRead' => $consistent_read,
        ];
        if ($conditions) {
            $conditionKey               = $usingScan ? "FilterExpression" : "KeyConditionExpression";
            $requestArgs[$conditionKey] = $conditions;
            
            if ($fields) {
                $requestArgs['ExpressionAttributeNames'] = $fields;
            }
            if ($params) {
                $paramsItem                               = DynamoDbItem::createFromArray($params);
                $requestArgs['ExpressionAttributeValues'] = $paramsItem->getData();
            }
        }
        if (!$usingScan) {
            if ($index_name !== self::PRIMARY_INDEX) {
                $requestArgs['IndexName'] = $index_name;
            }
        }
        if ($last_key) {
            $requestArgs['ExclusiveStartKey'] = $last_key;
        }
        if ($page_limit) {
            $requestArgs['Limit'] = $page_limit;
        }
        
        $result   = call_user_func([$this->dbClient, $command], $requestArgs);
        $last_key = isset($result['LastEvaluatedKey']) ? $result['LastEvaluatedKey'] : null;
        $items    = isset($result['Items']) ? $result['Items'] : [];
        
        $ret = [];
        foreach ($items as $itemArray) {
            $item  = DynamoDbItem::createFromTypedArray($itemArray);
            $ret[] = $item->toArray();
        }
        
        return $ret;
    }
    
    public function queryAndRun(callable $callback,
                                $conditions,
                                array $fields,
                                array $params,
                                $index_name = self::PRIMARY_INDEX,
                                $consistent_read = false)
    {
        $last_key          = null;
        $stoppedByCallback = false;
        do {
            $items = $this->query($conditions, $fields, $params, $index_name, $last_key, 30, $consistent_read);
            foreach ($items as $item) {
                if (call_user_func($callback, $item) === false) {
                    $stoppedByCallback = true;
                    break;
                }
            }
        } while ($last_key != null && !$stoppedByCallback);
    }
    
    public function scan($conditions = '',
                         array $fields = [],
                         array $params = [],
                         &$last_key = null,
                         $page_limit = 30,
                         $consistent_read = false)
    {
        return $this->query($conditions, $fields, $params, self::NO_INDEX, $last_key, $page_limit, $consistent_read);
    }
    
    public function scanAndRun(callable $callback,
                               $conditions = '',
                               array $fields = [],
                               array $params = [],
                               $consistent_read = false)
    {
        $this->queryAndRun($callback, $conditions, $fields, $params, self::NO_INDEX, $consistent_read);
    }
    
    public function set(array $obj, $checkValues = [])
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
    
    public function getConsumedCapacity($indexName = self::PRIMARY_INDEX,
                                        $period = 60,
                                        $num_of_period = 5,
                                        $timeshift = -300)
    {
        $cloudwatch = new CloudWatchClient(
            [
                "profile" => $this->config['profile'],
                "region"  => $this->config['region'],
                "version" => "2010-08-01",
            ]
        );
        
        $end = time() + $timeshift;
        $end -= $end % $period;
        $start = $end - $num_of_period * $period;
        
        $requestArgs = [
            "Namespace"  => "AWS/DynamoDB",
            "Dimensions" => [
                [
                    "Name"  => "TableName",
                    "Value" => $this->tableName,
                ],
                //[
                //    "Name"  => "Operation",
                //    "Value" => "GetItem",
                //],
            ],
            "MetricName" => "ConsumedReadCapacityUnits",
            "StartTime"  => date('c', $start),
            "EndTime"    => date('c', $end),
            "Period"     => 60,
            "Statistics" => ["Sum"],
        ];
        if ($indexName != self::PRIMARY_INDEX) {
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
    
    /**
     * @return DynamoDbClient
     */
    public function getDbClient()
    {
        return $this->dbClient;
    }
    
    public function getGlobalSecondaryIndices($namePattern = "/.*/")
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
    
    public function getLocalSecondaryIndices()
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
    
    public function getPrimaryIndex()
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
    
    /**
     * @return mixed
     */
    public function getTableName()
    {
        return $this->tableName;
    }
    
    public function getThroughput($indexName = self::PRIMARY_INDEX)
    {
        $result = $this->describe();
        if ($indexName == self::PRIMARY_INDEX) {
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
    
    public function setAttributeType($name, $type)
    {
        $this->attributeTypes[$name] = $type;
        
        return $this;
    }
    
    public function setThroughput($read, $write, $indexName = self::PRIMARY_INDEX)
    {
        $requestArgs  = [
            "TableName" => $this->tableName,
        ];
        $updateObject = [
            'ReadCapacityUnits'  => $read,
            'WriteCapacityUnits' => $write,
        ];
        if ($indexName == self::PRIMARY_INDEX) {
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
}
