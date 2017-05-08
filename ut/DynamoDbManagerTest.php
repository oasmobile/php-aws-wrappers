<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2017-05-08
 * Time: 15:25
 */

namespace Oasis\Mlib\AwsWrappers\Test;

use Aws\DynamoDb\Exception\DynamoDbException;
use Oasis\Mlib\AwsWrappers\DynamoDbIndex;
use Oasis\Mlib\AwsWrappers\DynamoDbManager;

class DynamoDbManagerTest extends \PHPUnit_Framework_TestCase
{
    protected static $tableName;
    
    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        
        self::$tableName = 'ut-ddbm-' . time();
    }
    
    public static function tearDownAfterClass()
    {
        
        $dbm = new DynamoDbManager(UTConfig::$awsConfig);
        try {
            $dbm->deleteTable(self::$tableName);
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() == 'ResourceNotFoundException') {
                // do nothing
            }
            else {
                throw $e;
            }
        }
        parent::tearDownAfterClass();
    }
    
    public function testCreationAndList()
    {
        $dbm = new DynamoDbManager(UTConfig::$awsConfig);
        $dbm->createTable(self::$tableName, new DynamoDbIndex('id'));
        $dbm->waitForTableCreation(self::$tableName);
        
        $listed = $dbm->listTables(sprintf('/%s/', self::$tableName));
        $this->assertTrue(in_array(self::$tableName, $listed));
    }
    
    public function testDelete()
    {
        $dbm = new DynamoDbManager(UTConfig::$awsConfig);
        $dbm->deleteTable(self::$tableName);
        $dbm->waitForTableDeletion(self::$tableName);
        
        $listed = $dbm->listTables(sprintf('/%s/', self::$tableName));
        $this->assertFalse(in_array(self::$tableName, $listed));
    }
}
