<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2017-01-05
 * Time: 16:29
 */

namespace Oasis\Mlib\AwsWrappers\Test;

use Oasis\Mlib\AwsWrappers\DynamoDbIndex;
use Oasis\Mlib\AwsWrappers\DynamoDbItem;
use Oasis\Mlib\AwsWrappers\DynamoDbManager;
use Oasis\Mlib\AwsWrappers\DynamoDbTable;

class DynamoDbTableTest extends \PHPUnit_Framework_TestCase
{
    const DEBUG = 1;
    protected static $tableName;
    
    /** @var  DynamoDbTable */
    protected $table;
    
    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        
        $manager = new DynamoDbManager(UTConfig::$awsConfig);
        $prefix  = UTConfig::$dynamodbConfig['table-prefix'];
        if (self::DEBUG) {
            self::$tableName = $prefix . "table";
        }
        else {
            self::$tableName = $prefix . date('Ymd-His');
            
            $existing = $manager->listTables("#^" . preg_quote($prefix) . "#");
            foreach ($existing as $oldTable) {
                $manager->deleteTable($oldTable);
            }
            $manager->createTable(
                self::$tableName,
                new DynamoDbIndex(
                    "id",
                    DynamoDbItem::ATTRIBUTE_TYPE_NUMBER
                ),
                [],
                [
                    new DynamoDbIndex(
                        "city", DynamoDbItem::ATTRIBUTE_TYPE_STRING, "code", DynamoDbItem::ATTRIBUTE_TYPE_NUMBER
                    ),
                ]
            );
            $table = new DynamoDbTable(UTConfig::$awsConfig, self::$tableName);
            while ($info = $table->describe()) {
                if ($info['TableStatus'] != 'ACTIVE') {
                    sleep(1);
                }
                else {
                    break;
                }
            }
        }
    }
    
    public static function tearDownAfterClass()
    {
        if (!self::DEBUG) {
            $manager = new DynamoDbManager(UTConfig::$awsConfig);
            $manager->deleteTable(self::$tableName);
        }
        
        parent::tearDownAfterClass();
    }
    
    protected function setUp()
    {
        parent::setUp();
        
        $this->table = new DynamoDbTable(UTConfig::$awsConfig, self::$tableName);
    }
    
    public function testSetAndGet()
    {
        $obj = ['id' => 1, 'name' => 'Alice'];
        $this->table->set($obj);
        $result = $this->table->get(['id' => 1]);
        $this->assertEquals($obj, $result);
    }
    
    public function testBatchPut()
    {
        $writes = [];
        for ($i = 0; $i < 10; ++$i) {
            $obj                = [
                "id"   => 10 + $i,
                "city" => ($i % 2) ? "beijing" : "shanghai",
                "code" => 100 + $i,
            ];
            $writes[$obj["id"]] = $obj;
        }
        $this->table->batchPut($writes);
        foreach ($writes as $k => $v) {
            $this->table->get(["id" => intval($k)]);
        }
    }
    
    /**
     * @depends testBatchPut
     */
    public function testQuery()
    {
        $result = $this->table->query("#id = :id", ["#id" => "id"], [":id" => 13]);
        $this->assertTrue(is_array($result));
        $this->assertTrue(count($result) > 0);
        $obj = current($result);
        $this->assertEquals("beijing", $obj['city']);
        
        $result = $this->table->query(
            "#city = :city AND (#code BETWEEN :min AND :max)",
            ["#city" => "city", "#code" => "code"],
            [":city" => "shanghai", ":min" => 100, ":max" => 105],
            "city-code-index"
        );
        $this->assertTrue(is_array($result));
        $this->assertEquals(3, count($result));
        $obj = current($result);
        next($result);
        $this->assertEquals(100, $obj['code']);
        $obj = current($result);
        next($result);
        $this->assertEquals(102, $obj['code']);
        $obj = current($result);
        next($result);
        $this->assertEquals(104, $obj['code']);
    }
    
}
