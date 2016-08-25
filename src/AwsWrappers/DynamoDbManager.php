<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2016-08-25
 * Time: 20:17
 */

namespace Oasis\Mlib\AwsWrappers;

use Aws\DynamoDb\DynamoDbClient;
use Oasis\Mlib\Utils\ArrayDataProvider;

class DynamoDbManager
{
    /** @var array */
    protected $config;
    /** @var  DynamoDbClient */
    protected $db;
    
    public function __construct(array $awsConfig)
    {
        $dp           = new ArrayDataProvider($awsConfig);
        $this->config = [
            'version' => "2012-08-10",
            "profile" => $dp->getMandatory('profile'),
            "region"  => $dp->getMandatory('region'),
        ];
        $this->db     = new DynamoDbClient($this->config);
    }
    
    public function listTables()
    {
        $tables                 = [];
        $lastEvaluatedTableName = null;
        do {
            $args = [
                "Limit" => 2,
            ];
            if ($lastEvaluatedTableName) {
                $args['ExclusiveStartTableName'] = $lastEvaluatedTableName;
            }
            $cmd    = $this->db->getCommand(
                'ListTables',
                $args
            );
            $result = $this->db->execute($cmd);
            if (isset($result['LastEvaluatedTableName'])) {
                $lastEvaluatedTableName = $result['LastEvaluatedTableName'];
            }
            else {
                $lastEvaluatedTableName = null;
            }
            
            $tables = array_merge($tables, $result['TableNames']);
        } while ($lastEvaluatedTableName != null);
        
        return $tables;
    }
    
    public function createTable($tableName,
                                $hashKeyName,
                                $hashKeyType,
                                $rangeKeyName = null,
                                $rangeKeyType = null,
                                $readCapacity = 5,
                                $writeCapacity = 5
    )
    {
        $attrDef   = [
            [
                "AttributeName" => $hashKeyName,
                "AttributeType" => $hashKeyType,
            ],
        ];
        $keySchema = [
            [
                "AttributeName" => $hashKeyName,
                "KeyType"       => "HASH",
            ],
        ];
        if ($rangeKeyName) {
            $attrDef[]   = [
                "AttributeName" => $rangeKeyName,
                "AttributeType" => $rangeKeyType,
            ];
            $keySchema[] = [
                "AttributeName" => $rangeKeyName,
                "KeyType"       => "RANGE",
            ];
        }
        
        $args = [
            "TableName"             => $tableName,
            "ProvisionedThroughput" => [
                "ReadCapacityUnits"  => $readCapacity,
                "WriteCapacityUnits" => $writeCapacity,
            ],
            "AttributeDefinitions"  => $attrDef,
            "KeySchema"             => $keySchema,
        ];
        
        $result = $this->db->createTable($args);
        
        if (isset($result['TableDescription']) && $result['TableDescription']) {
            return true;
        }
        else {
            return false;
        }
    }
    
    public function deleteTable($tablename)
    {
        $args   = [
            'TableName' => $tablename,
        ];
        $result = $this->db->deleteTable($args);
        
        if (isset($result['TableDescription']) && $result['TableDescription']) {
            return true;
        }
        else {
            return false;
        }
    }
}
