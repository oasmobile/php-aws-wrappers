<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2017-01-05
 * Time: 16:29
 */

namespace Oasis\Mlib\AwsWrappers\Test;

use Aws\DynamoDb\Exception\DynamoDbException;
use Oasis\Mlib\AwsWrappers\DynamoDbIndex;
use Oasis\Mlib\AwsWrappers\DynamoDbItem;
use Oasis\Mlib\AwsWrappers\DynamoDbManager;
use Oasis\Mlib\AwsWrappers\DynamoDbTable;

class DynamoDbTableTest extends \PHPUnit_Framework_TestCase
{
    const DEBUG = 0;
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
            $promise = $manager->waitForTableCreation(self::$tableName, 60, 1, false);
            \GuzzleHttp\Promise\all([$promise])->wait();
        }
    }
    
    public static function tearDownAfterClass()
    {
        if (!self::DEBUG) {
            $manager = new DynamoDbManager(UTConfig::$awsConfig);
            $manager->deleteTable(self::$tableName);
            $manager->waitForTableDeletion(self::$tableName);
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
        $result = $this->table->get(['id' => 1], true, ['name']);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayNotHasKey('id', $result);
    }
    
    public function testBacthDelete()
    {
        $writes = [];
        for ($i = 0; $i < 10; ++$i) {
            $obj                = [
                "id"   => 100 + $i,
                "city" => ($i % 2) ? "dallas" : "houston",
                "code" => 300 + $i,
            ];
            $writes[$obj["id"]] = $obj;
        }
        $this->table->batchPut($writes);
        $keys = [];
        foreach ($writes as $k => $v) {
            $key    = ["id" => intval($k)];
            $keys[] = $key;
        }
        $this->table->batchDelete($keys);
        
        $this->assertEquals(null, $this->table->get(['id' => 101]));
    }
    
    public function testBatchPut()
    {
        $writes = [];
        for ($i = 0; $i < 10; ++$i) {
            $obj                = [
                "id"    => 10 + $i,
                "city"  => ($i % 2) ? "beijing" : "shanghai",
                "code"  => 100 + $i,
                "mayor" => (($i % 3) == 0) ? "wang" : ((($i % 3) == 1) ? "ye" : "lee"),
            ];
            $writes[$obj["id"]] = $obj;
        }
        $this->table->batchPut($writes);
        $keys = [];
        foreach ($writes as $k => $v) {
            $key    = ["id" => intval($k)];
            $keys[] = $key;
            $this->table->get($key);
        }
        
        return $keys;
    }
    
    /**
     * @depends testBatchPut
     *
     * @param $keys
     */
    public function testBatchGet($keys)
    {
        $result = $this->table->batchGet($keys);
        $this->assertTrue(is_array($result));
        $this->assertEquals(10, count($result));
    }
    
    public function testProjectedBatchGet()
    {
        $result = $this->table->batchGet([['id' => 10], ['id' => 11]], true, 10, ['city']);
        foreach ($result as $item) {
            $this->assertArrayHasKey('city', $item);
            $this->assertArrayNotHasKey('id', $item);
        }
    }
    
    /**
     * @depends testBatchPut
     */
    public function testQuery()
    {
        // query primary index
        $result = $this->table->query("#id = :id", ["#id" => "id"], [":id" => 13]);
        $this->assertTrue(is_array($result));
        $this->assertTrue(count($result) > 0);
        $obj = current($result);
        $this->assertEquals("beijing", $obj['city']);
        
        // query GSI
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
    
    /**
     * @depends testBatchPut
     */
    public function testQueryWithFilterExpression()
    {
        $result = $this->table->query(
            "#city = :city AND (#code BETWEEN :min AND :max)",
            ["#city" => "city", "#code" => "code", "#mayor" => "mayor"],
            [":city" => "shanghai", ":min" => 100, ":max" => 105, ":notAllowed" => "lee"],
            "city-code-index",
            "#mayor <> :notAllowed"
        );
        $this->assertTrue(is_array($result));
        $this->assertEquals(2, count($result));
        $obj = current($result);
        next($result);
        $this->assertEquals(100, $obj['code']);
        $this->assertEquals("wang", $obj['mayor']);
        $obj = current($result);
        next($result);
        $this->assertEquals(104, $obj['code']);
        $this->assertEquals("ye", $obj['mayor']);
    }
    
    /**
     * @depends testBatchPut
     */
    public function testQueryAndRun()
    {
        $result = [];
        $this->table->queryAndRun(
            function ($item) use (&$result) {
                $this->assertTrue(is_array($item));
                $this->assertArrayHasKey('mayor', $item);
                $result[] = $item['mayor'];
            },
            "#city = :city AND (#code BETWEEN :min AND :max)",
            ["#city" => "city", "#code" => "code", "#mayor" => "mayor"],
            [":city" => "shanghai", ":min" => 100, ":max" => 105, ":notAllowed" => "lee"],
            "city-code-index",
            "#mayor <> :notAllowed"
        );
        $this->assertEquals(["wang", "ye"], $result);
    }
    
    /**
     * @depends testBatchPut
     */
    public function testMultiQuery()
    {
        // test on index without range condition
        $result = [];
        $this->table->multiQueryAndRun(
            function ($item) use (&$result) {
                $this->assertTrue(is_array($item));
                $this->assertArrayHasKey('id', $item);
                $this->assertArrayHasKey('mayor', $item);
                $result[$item['id']] = $item['mayor'];
            },
            "city",
            ['shanghai', 'beijing'],
            "",
            [],
            [],
            "city-code-index"
        );
        $this->assertEquals(10, count($result));
        
        $result = [];
        $this->table->multiQueryAndRun(
            function ($item) use (&$result) {
                $this->assertTrue(is_array($item));
                $this->assertArrayHasKey('id', $item);
                $this->assertArrayHasKey('mayor', $item);
                $result[$item['id']] = $item['mayor'];
            },
            "city",
            ['shanghai', 'beijing'],
            "#code BETWEEN :min AND :max",
            ["#code" => "code", "#mayor" => "mayor"],
            [":min" => 100, ":max" => 105, ":notAllowed" => "lee"],
            "city-code-index",
            "#mayor <> :notAllowed"
        );
        ksort($result);
        //var_dump($result);
        $this->assertEquals([10 => "wang", 11 => "ye", 13 => "wang", 14 => "ye"], $result);
        
        $this->expectException(DynamoDbException::class);
        $this->table->multiQueryAndRun(
            function ($item) use (&$result) {
                $this->assertTrue(is_array($item));
                $this->assertArrayHasKey('id', $item);
                $this->assertArrayHasKey('mayor', $item);
                $result[$item['id']] = $item['mayor'];
            },
            "city",
            ['shanghai', 'beijing'],
            "#code BETWEEN :min AND :max2",
            ["#code" => "code", "#mayor" => "mayor"],
            [":min" => 100, ":max" => 105, ":notAllowed" => "lee"],
            "city-code-index",
            "#mayor <> :notAllowed"
        );
        
    }
    
    /**
     * @depends testBatchPut
     */
    public function testQueryCount()
    {
        $restul = $this->table->queryCount(
            "#city = :city AND (#code BETWEEN :min AND :max)",
            ["#city" => "city", "#code" => "code", "#mayor" => "mayor"],
            [":city" => "shanghai", ":min" => 100, ":max" => 105, ":notAllowed" => "lee"],
            "city-code-index",
            "#mayor <> :notAllowed"
        );
        $this->assertEquals(2, $restul);
    }
    
    /**
     * @depends testBatchPut
     */
    public function testScan()
    {
        $result = $this->table->scan(
            "#city = :city AND #code > :min",
            ["#city" => "city", "#code" => "code"],
            [":city" => "shanghai", ":min" => 103]
        );
        $this->assertEquals(3, count($result));
    }
    
    /**
     * @depends testBatchPut
     */
    public function testScanCount()
    {
        $result = $this->table->scanCount(
            "#city = :city AND #code > :min",
            ["#city" => "city", "#code" => "code"],
            [":city" => "shanghai", ":min" => 103]
        );
        $this->assertEquals(3, $result);
    }
    
    /**
     * @depends testBatchPut
     */
    public function testScanAndRun()
    {
        $expectedMayors = [
            "ye",
            "lee",
            "wang",
        ];
        $this->table->scanAndRun(
            function ($item) use (&$expectedMayors) {
                $expectedMayor = array_shift($expectedMayors);
                $this->assertEquals($expectedMayor, $item['mayor']);
            },
            "#city = :city AND #code > :min",
            ["#city" => "city", "#code" => "code"],
            [":city" => "shanghai", ":min" => 103]
        );
        $this->assertEquals(0, count($expectedMayors));
        $expectedMayors = [
            "ye",
            "lee",
            "wang",
        ];
        $this->table->scanAndRun(
            function ($item) use (&$expectedMayors) {
                $expectedMayor = array_shift($expectedMayors);
                $this->assertEquals($expectedMayor, $item['mayor']);
                if ($item['mayor'] == "lee") {
                    return false;
                }
                
                return true;
            },
            "#city = :city AND #code > :min",
            ["#city" => "city", "#code" => "code"],
            [":city" => "shanghai", ":min" => 103]
        );
        $this->assertEquals(1, count($expectedMayors));
        
    }
    
    /**
     * @depends testBatchPut
     */
    public function testParallelScanAndRun()
    {
        $expectedMayors = [
            "wangy",
            "lee",
            "ye",
            "wang",
            "lee",
        ];
        $this->table->parallelScanAndRun(
            3,
            function ($item) use (&$expectedMayors) {
                $expectedMayor = array_shift($expectedMayors);
                $this->assertEquals($expectedMayor, $item['mayor']);
            },
            "#city = :city AND (#code BETWEEN :min AND :max)",
            ["#city" => "city", "#code" => "code"],
            [":city" => "shanghai", ":min" => 100, ":max" => 108],
            "city-code-index"
        );
        
        $this->table->parallelScanAndRun(
            3,
            function () use (&$visited) {
                $visited++;
                
                return false;
            },
            "#city = :city AND (#code BETWEEN :min AND :max)",
            ["#city" => "city", "#code" => "code"],
            [":city" => "shanghai", ":min" => 100, ":max" => 108],
            "city-code-index"
        );
        $this->assertEquals(1, $visited);
    }
    
    /**
     * @depends testBatchPut
     */
    public function testProjectedFieldsScan()
    {
        $result = $this->table->scan(
            '#id > :id',
            ["#id" => "id"],
            [":id" => 10],
            DynamoDbIndex::PRIMARY_INDEX,
            $lastKey,
            30,
            true,
            true,
            ["id", "city"]
        );
        foreach ($result as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('city', $item, \GuzzleHttp\json_encode($item));
            $this->assertArrayNotHasKey('code', $item);
        }
        $this->table->scanAndRun(
            function ($item) {
                $this->assertArrayHasKey('id', $item);
                $this->assertArrayHasKey('city', $item, \GuzzleHttp\json_encode($item));
                $this->assertArrayNotHasKey('code', $item);
            },
            '#id > :id',
            ["#id" => "id", "#city" => "city"],
            [":id" => 10],
            DynamoDbIndex::PRIMARY_INDEX,
            true,
            true,
            ["id", "city"]
        );
    }
    
    /**
     * @depends testBatchPut
     */
    public function testProjectedFieldsQuery()
    {
        $result = $this->table->query(
            '#id = :id',
            [],
            [":id" => 10],
            DynamoDbIndex::PRIMARY_INDEX,
            '',
            $lastKey,
            30,
            true,
            true,
            ["id", "city"]
        );
        foreach ($result as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('city', $item, \GuzzleHttp\json_encode($item));
            $this->assertArrayNotHasKey('code', $item);
        }
        $this->table->queryAndRun(
            function ($item) {
                $this->assertArrayHasKey('id', $item);
                $this->assertArrayHasKey('city', $item, \GuzzleHttp\json_encode($item));
                $this->assertArrayNotHasKey('code', $item);
            },
            '#id = :id',
            [],
            [":id" => 10],
            DynamoDbIndex::PRIMARY_INDEX,
            '',
            true,
            true,
            ["id", "city"]
        );
    }
    
}
