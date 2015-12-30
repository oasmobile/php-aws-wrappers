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
use Oasis\Mlib\Utils\ArrayDataProvider;

class DynamoDbTable
{
    const PRIMARY_INDEX = false;
    const NO_INDEX      = null;

    /** @var DynamoDbClient */
    protected $db_client;

    protected $config;

    protected $table_name;
    protected $cas_field       = '';
    protected $attribute_types = [];

    function __construct(array $aws_config, $table_name, $attribute_types = [], $cas_field = '')
    {
        $dp                    = new ArrayDataProvider($aws_config);
        $this->config          = [
            'version' => "2012-08-10",
            "profile" => $dp->getMandatory('profile'),
            "region"  => $dp->getMandatory('region'),
        ];
        $this->db_client       = new DynamoDbClient($this->config);
        $this->table_name      = $table_name;
        $this->attribute_types = $attribute_types;
        $this->cas_field       = $cas_field;
    }

    public function describe()
    {
        $requestArgs = [
            "TableName" => $this->table_name,
        ];
        $result      = $this->db_client->describeTable($requestArgs);

        return $result;
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
                    "Value" => $this->table_name,
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

    public function setThroughput($read, $write, $indexName = self::PRIMARY_INDEX)
    {
        $requestArgs  = [
            "TableName" => $this->table_name,
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
            $this->db_client->updateTable($requestArgs);
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

    public function setAttributeType($name, $type)
    {
        $this->attribute_types[$name] = $type;

        return $this;
    }

    public function get(array $keys, $is_consistent_read = false)
    {
        $keyItem     = DynamoDbItem::createFromArray($keys, $this->attribute_types);
        $requestArgs = [
            "TableName" => $this->table_name,
            "Key"       => $keyItem->getData(),
        ];
        if ($is_consistent_read) {
            $requestArgs["ConsistentRead"] = true;
        }

        $result = $this->db_client->getItem($requestArgs);
        if ($result['Item']) {
            $item = DynamoDbItem::createFromTypedArray((array)$result['Item']);

            return $item->toArray();
        }
        else {
            return null;
        }
    }

    public function set(array $obj, $cas = false)
    {
        $requestArgs = [
            "TableName" => $this->table_name,
        ];

        if ($this->cas_field) {
            $old_cas               = $obj[$this->cas_field];
            $obj[$this->cas_field] = time();

            if ($old_cas && $cas) {
                $requestArgs['ConditionExpression']       = "#CAS = :cas_val";
                $requestArgs['ExpressionAttributeNames']  = ["#CAS" => $this->cas_field];
                $requestArgs['ExpressionAttributeValues'] = [":cas_val" => ["N" => strval(intval($old_cas))]];
            }
        }
        $item                = DynamoDbItem::createFromArray($obj, $this->attribute_types);
        $requestArgs['Item'] = $item->getData();

        try {
            $this->db_client->putItem($requestArgs);
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

    public function delete($keys)
    {
        $keyItem = DynamoDbItem::createFromArray($keys, $this->attribute_types);

        $requestArgs = [
            "TableName" => $this->table_name,
            "Key"       => $keyItem->getData(),
        ];

        $this->db_client->deleteItem($requestArgs);
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
            "TableName" => $this->table_name,
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
            $result   = call_user_func([$this->db_client, $command], $requestArgs);
            $last_key = $result['LastEvaluatedKey'];
            $count += intval($result['Count']);
            $scanned += intval($result['ScannedCount']);
        } while ($last_key != null);

        mdebug("Count = $count from total scanned $scanned");

        return $count;
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
            "TableName" => $this->table_name,
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
        if ($last_key) {
            $requestArgs['ExclusiveStartKey'] = $last_key;
        }
        if ($page_limit) {
            $requestArgs['Limit'] = $page_limit;
        }

        $result   = call_user_func([$this->db_client, $command], $requestArgs);
        $last_key = $result['LastEvaluatedKey'];
        $items    = $result['Items'];

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
        $last_key = null;
        do {
            $items = $this->query($conditions, $fields, $params, $index_name, $last_key, 30, $consistent_read);
            foreach ($items as $item) {
                call_user_func($callback, $item);
            }
        } while ($last_key != null);
    }

    public function scan($conditions = '',
                         array $fields = [],
                         array $params = [],
                         &$last_key = null,
                         $page_limit = 30)
    {
        return $this->query($conditions, $fields, $params, self::NO_INDEX, $last_key, $page_limit);
    }

    public function scanAndRun(callable $callback,
                               $conditions = '',
                               array $fields = [],
                               array $params = [])
    {
        $this->queryAndRun($callback, $conditions, $fields, $params, self::NO_INDEX);
    }

    /**
     * @return string
     */
    public function getCasField()
    {
        return $this->cas_field;
    }

    /**
     * @param string $cas_field
     */
    public function setCasField($cas_field)
    {
        $this->cas_field = $cas_field;
    }

    /**
     * @return DynamoDbClient
     */
    public function getDbClient()
    {
        return $this->db_client;
    }
}
