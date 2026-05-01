<?php

namespace Oasis\Mlib\AwsWrappers;

use Aws\DynamoDb\DynamoDbClient;
use Aws\Result;

class DynamoDbManager
{
    protected array $config;
    /** @var DynamoDbClient */
    protected mixed $db;
    
    public function __construct(array $awsConfig)
    {
        $dp       = new AwsConfigDataProvider($awsConfig, '2012-08-10');
        $this->db = new DynamoDbClient($dp->getConfig());
    }
    
    public function listTables(string $pattern = '/.*/'): array
    {
        $tables                 = [];
        $lastEvaluatedTableName = null;
        do {
            $args = [
                "Limit" => 30,
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
            
            foreach ($result['TableNames'] as $tableName) {
                if (preg_match($pattern, $tableName)) {
                    $tables[] = $tableName;
                }
            }
        } while ($lastEvaluatedTableName != null);
        
        return $tables;
    }
    
    public function createTable(string $tableName,
                                DynamoDbIndex $primaryIndex,
                                array $localSecondaryIndices = [],
                                array $globalSecondaryIndices = [],
                                int $readCapacity = 5,
                                int $writeCapacity = 5
    ): bool
    {
        $attrDef = $primaryIndex->getAttributeDefinitions();
        foreach ($globalSecondaryIndices as $gsi) {
            $gsiDef  = $gsi->getAttributeDefinitions();
            $attrDef = array_merge($attrDef, $gsiDef);
        }
        foreach ($localSecondaryIndices as $lsi) {
            $lsiDef  = $lsi->getAttributeDefinitions();
            $attrDef = array_merge($attrDef, $lsiDef);
        }
        
        $attrDef = array_values($attrDef);
        
        $keySchema = $primaryIndex->getKeySchema();
        
        $gsiDef = [];
        foreach ($globalSecondaryIndices as $globalSecondaryIndex) {
            $gsiDef[] = [
                "IndexName"             => $globalSecondaryIndex->getName(),
                "KeySchema"             => $globalSecondaryIndex->getKeySchema(),
                "Projection"            => $globalSecondaryIndex->getProjection(),
                "ProvisionedThroughput" => [
                    "ReadCapacityUnits"  => $readCapacity,
                    "WriteCapacityUnits" => $writeCapacity,
                ],
            ];
        }
        
        $lsiDef = [];
        foreach ($localSecondaryIndices as $localSecondaryIndex) {
            $lsiDef[] = [
                "IndexName"  => $localSecondaryIndex->getName(),
                "KeySchema"  => $localSecondaryIndex->getKeySchema(),
                "Projection" => $localSecondaryIndex->getProjection(),
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
        if ($gsiDef) {
            $args["GlobalSecondaryIndexes"] = $gsiDef;
        }
        if ($lsiDef) {
            $args["LocalSecondaryIndexes"] = $lsiDef;
        }
        
        $result = $this->db->createTable($args);
        
        if (isset($result['TableDescription']) && $result['TableDescription']) {
            return true;
        }
        else {
            return false;
        }
    }
    
    public function deleteTable(string $tableName): bool
    {
        $args   = [
            'TableName' => $tableName,
        ];
        $result = $this->db->deleteTable($args);
        
        if (isset($result['TableDescription']) && $result['TableDescription']) {
            return true;
        }
        else {
            return false;
        }
    }
    
    public function waitForTableCreation(string $tableName, int $timeout = 60, int $pollInterval = 1, bool $blocking = true): mixed
    {
        $args = [
            'TableName' => $tableName,
            '@waiter'   => [
                'delay'       => $pollInterval,
                'maxAttempts' => ceil($timeout / $pollInterval),
            ],
        ];
        
        $promise = $this->db->getWaiter('TableExists', $args)->promise();
        
        if ($blocking) {
            $promise->wait();
            
            return true;
        }
        else {
            return $promise;
        }
    }
    
    public function waitForTableDeletion(string $tableName, int $timeout = 60, int $pollInterval = 1, bool $blocking = true): mixed
    {
        $args = [
            'TableName' => $tableName,
            '@waiter'   => [
                'delay'       => $pollInterval,
                'maxAttempts' => ceil($timeout / $pollInterval),
            ],
        ];
        
        $promise = $this->db->getWaiter('TableNotExists', $args)->promise();
        
        if ($blocking) {
            $promise->wait();
            
            return true;
        }
        else {
            return $promise;
        }
    }
    
    public function waitForTablesToBeFullyReady(string|array $tableNames, int $timeout = 60, int $interval = 2): bool
    {
        $started = time();
        if (is_string($tableNames)) {
            $tableNames = [$tableNames];
        }
        while ($tableNames) {
            $promises = [];
            foreach ($tableNames as $tableName) {
                $args    = [
                    "TableName" => $tableName,
                ];
                $promise = $this->db->describeTableAsync($args);
                $promise->then(
                    function (Result $result) use (&$tableNames, $tableName) {
                        if ($result['Table']['TableStatus'] == "ACTIVE") {
                            if (isset($result['Table']['GlobalSecondaryIndexes'])
                                && $result['Table']['GlobalSecondaryIndexes']
                            ) {
                                foreach ($result['Table']['GlobalSecondaryIndexes'] as $gsi) {
                                    if ($gsi['IndexStatus'] != "ACTIVE") {
                                        return;
                                    }
                                }
                            }
                            
                            $k = array_search($tableName, $tableNames);
                            array_splice($tableNames, $k, 1);
                        }
                    }
                );
                $promises[] = $promise;
            }
            
            \GuzzleHttp\Promise\Utils::all($promises)->wait();
            if ($tableNames) {
                if (time() - $started > $timeout) {
                    mwarning("Timed out, some tables are still in unready state: %s", implode(",", $tableNames));
                    
                    return false;
                }
                sleep($interval);
            }
        }
        
        return true;
    }
}
