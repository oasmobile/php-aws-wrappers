<?php

namespace Oasis\Mlib\AwsWrappers\Test\Integration;

use Aws\DynamoDb\Exception\DynamoDbException;
use Oasis\Mlib\AwsWrappers\DynamoDbIndex;
use Oasis\Mlib\AwsWrappers\DynamoDbItem;
use Oasis\Mlib\AwsWrappers\DynamoDbManager;
use Oasis\Mlib\AwsWrappers\DynamoDbTable;
use Oasis\Mlib\AwsWrappers\Test\UTConfig;
use PHPUnit\Framework\TestCase;

/**
 * Uses the shared table created by IntegrationSetup (via bootstrap extension).
 * No setUpBeforeClass / tearDownAfterClass — resource lifecycle is suite-level.
 */
class DynamoDbTableIntegrationTest extends TestCase
{
    protected DynamoDbTable $table;

    protected function setUp(): void
    {
        parent::setUp();
        IntegrationSetup::ensureDynamoDb();
        $this->table = new DynamoDbTable(UTConfig::$awsConfig, UTConfig::$sharedTableName);
    }

    /** Busy-wait until table status is ACTIVE (max 15 s, 200 ms poll). */
    private static function waitForTableActive(): void
    {
        $table    = new DynamoDbTable(UTConfig::$awsConfig, UTConfig::$sharedTableName);
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $desc = $table->describe();
            if ($desc['TableStatus'] === 'ACTIVE') return;
            usleep(200_000);
        }
    }

    // ── CRUD ────────────────────────────────────────────────────

    public function testSetAndGet(): void
    {
        $obj = ['id' => 1, 'name' => 'Alice'];
        $this->table->set($obj);
        $this->assertEquals($obj, $this->table->get(['id' => 1]));
        $result = $this->table->get(['id' => 1], true, ['name']);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayNotHasKey('id', $result);
    }

    public function testBatchDelete(): void
    {
        $writes = [];
        for ($i = 0; $i < 10; ++$i) {
            $writes[100 + $i] = ["id" => 100 + $i, "city" => "dallas", "code" => 300 + $i];
        }
        $this->table->batchPut($writes);
        $keys = array_map(fn($k) => ["id" => (int)$k], array_keys($writes));
        $this->table->batchDelete($keys);
        $this->assertNull($this->table->get(['id' => 101]));
    }

    public function testBatchPutAndGet(): void
    {
        // Seeded data from IntegrationSetup
        $keys = [];
        for ($i = 0; $i < 10; ++$i) {
            $keys[] = ["id" => 10 + $i];
        }
        foreach ($keys as $key) {
            $this->assertNotNull($this->table->get($key), "Missing id={$key['id']}");
        }
        $result = $this->table->batchGet($keys);
        $this->assertCount(10, $result);
    }

    public function testProjectedBatchGet(): void
    {
        $result = $this->table->batchGet([['id' => 10], ['id' => 11]], true, 10, ['city']);
        foreach ($result as $item) {
            $this->assertArrayHasKey('city', $item);
            $this->assertArrayNotHasKey('id', $item);
        }
    }

    public function testSetWithCheckValues(): void
    {
        $this->table->set(['id' => 888, 'name' => 'CheckTest', 'version' => 1]);
        $this->assertTrue($this->table->set(['id' => 888, 'name' => 'Updated', 'version' => 2], ['version' => 1]));
        $this->assertEquals('Updated', $this->table->get(['id' => 888])['name']);
        $this->table->delete(['id' => 888]);
    }

    public function testDeleteSingleItem(): void
    {
        $this->table->set(['id' => 999, 'name' => 'ToDelete']);
        $this->assertNotNull($this->table->get(['id' => 999]));
        $this->table->delete(['id' => 999]);
        $this->assertNull($this->table->get(['id' => 999]));
    }

    // ── Query / Scan (use seeded data) ──────────────────────────

    public function testQuery(): void
    {
        $result = $this->table->query("#id = :id", ["#id" => "id"], [":id" => 13]);
        $this->assertNotEmpty($result);
        $this->assertEquals("beijing", current($result)['city']);

        $result = $this->table->query(
            "#city = :city AND (#code BETWEEN :min AND :max)",
            ["#city" => "city", "#code" => "code"],
            [":city" => "shanghai", ":min" => 100, ":max" => 105],
            "city-code-index"
        );
        $this->assertCount(3, $result);
        $codes = array_column($result, 'code');
        sort($codes);
        $this->assertEquals([100, 102, 104], $codes);
    }

    public function testQueryWithFilterExpression(): void
    {
        $result = $this->table->query(
            "#city = :city AND (#code BETWEEN :min AND :max)",
            ["#city" => "city", "#code" => "code", "#mayor" => "mayor"],
            [":city" => "shanghai", ":min" => 100, ":max" => 105, ":notAllowed" => "lee"],
            "city-code-index",
            "#mayor <> :notAllowed"
        );
        $this->assertCount(2, $result);
    }

    public function testQueryAndRun(): void
    {
        $mayors = [];
        $this->table->queryAndRun(
            function ($item) use (&$mayors) {
                $mayors[] = $item['mayor'];
            },
            "#city = :city AND (#code BETWEEN :min AND :max)",
            ["#city" => "city", "#code" => "code", "#mayor" => "mayor"],
            [":city" => "shanghai", ":min" => 100, ":max" => 105, ":notAllowed" => "lee"],
            "city-code-index",
            "#mayor <> :notAllowed"
        );
        $this->assertCount(2, $mayors);
    }

    public function testMultiQuery(): void
    {
        $result = [];
        $this->table->multiQueryAndRun(
            function ($item) use (&$result) { $result[$item['id']] = $item['mayor']; },
            "city", ['shanghai', 'beijing'], "", [], [], "city-code-index"
        );
        $this->assertCount(10, $result);

        $result = [];
        $this->table->multiQueryAndRun(
            function ($item) use (&$result) { $result[$item['id']] = $item['mayor']; },
            "city", ['shanghai', 'beijing'],
            "#code BETWEEN :min AND :max",
            ["#code" => "code", "#mayor" => "mayor"],
            [":min" => 100, ":max" => 105, ":notAllowed" => "lee"],
            "city-code-index",
            "#mayor <> :notAllowed"
        );
        ksort($result);
        $this->assertEquals([10 => "wang", 11 => "ye", 13 => "wang", 14 => "ye"], $result);

        $this->expectException(DynamoDbException::class);
        $this->table->multiQueryAndRun(
            function () {},
            "city", ['shanghai', 'beijing'],
            "#code BETWEEN :min AND :max2",
            ["#code" => "code", "#mayor" => "mayor"],
            [":min" => 100, ":max" => 105, ":notAllowed" => "lee"],
            "city-code-index", "#mayor <> :notAllowed"
        );
    }

    public function testQueryCount(): void
    {
        $this->assertEquals(2, $this->table->queryCount(
            "#city = :city AND (#code BETWEEN :min AND :max)",
            ["#city" => "city", "#code" => "code", "#mayor" => "mayor"],
            [":city" => "shanghai", ":min" => 100, ":max" => 105, ":notAllowed" => "lee"],
            "city-code-index", "#mayor <> :notAllowed"
        ));
    }

    public function testScan(): void
    {
        $this->assertCount(3, $this->table->scan(
            "#city = :city AND #code > :min",
            ["#city" => "city", "#code" => "code"],
            [":city" => "shanghai", ":min" => 103]
        ));
    }

    public function testScanCount(): void
    {
        $this->assertEquals(3, $this->table->scanCount(
            "#city = :city AND #code > :min",
            ["#city" => "city", "#code" => "code"],
            [":city" => "shanghai", ":min" => 103]
        ));
    }

    public function testScanAndRun(): void
    {
        $collected = [];
        $this->table->scanAndRun(
            function ($item) use (&$collected) { $collected[] = $item['mayor']; },
            "#city = :city AND #code > :min",
            ["#city" => "city", "#code" => "code"],
            [":city" => "shanghai", ":min" => 103]
        );
        $this->assertCount(3, $collected);

        $visited = 0;
        $this->table->scanAndRun(
            function () use (&$visited) { $visited++; return ($visited < 2); },
            "#city = :city AND #code > :min",
            ["#city" => "city", "#code" => "code"],
            [":city" => "shanghai", ":min" => 103]
        );
        $this->assertEquals(2, $visited);
    }

    public function testParallelScanAndRun(): void
    {
        $items = [];
        $this->table->parallelScanAndRun(
            3,
            function ($item) use (&$items) { $items[] = $item; },
            "#city = :city AND (#code BETWEEN :min AND :max)",
            ["#city" => "city", "#code" => "code"],
            [":city" => "shanghai", ":min" => 100, ":max" => 108],
            "city-code-index"
        );
        $this->assertGreaterThan(0, count($items));

        $visited = 0;
        $this->table->parallelScanAndRun(
            3,
            function () use (&$visited) { $visited++; return false; },
            "#city = :city AND (#code BETWEEN :min AND :max)",
            ["#city" => "city", "#code" => "code"],
            [":city" => "shanghai", ":min" => 100, ":max" => 108],
            "city-code-index"
        );
        $this->assertEquals(1, $visited);
    }

    public function testProjectedFieldsScan(): void
    {
        $result = $this->table->scan(
            '#id > :id', ["#id" => "id"], [":id" => 10],
            DynamoDbIndex::PRIMARY_INDEX, $lastKey, 30, true, true, ["id", "city"]
        );
        foreach ($result as $item) {
            $this->assertArrayHasKey('city', $item);
            $this->assertArrayNotHasKey('code', $item);
        }
    }

    public function testProjectedFieldsQuery(): void
    {
        $result = $this->table->query(
            '#id = :id', [], [":id" => 10],
            DynamoDbIndex::PRIMARY_INDEX, '', $lastKey, 30, true, true, ["id", "city"]
        );
        foreach ($result as $item) {
            $this->assertArrayHasKey('city', $item);
            $this->assertArrayNotHasKey('code', $item);
        }
    }

    // ── Metadata ────────────────────────────────────────────────

    public function testDescribe(): void
    {
        $desc = $this->table->describe();
        $this->assertEquals(UTConfig::$sharedTableName, $desc['TableName']);
        $this->assertArrayHasKey('KeySchema', $desc);
    }

    public function testGetTableName(): void
    {
        $this->assertEquals(UTConfig::$sharedTableName, $this->table->getTableName());
    }

    public function testGetDbClient(): void
    {
        $this->assertInstanceOf(\Aws\DynamoDb\DynamoDbClient::class, $this->table->getDbClient());
    }

    public function testGetPrimaryIndex(): void
    {
        $p = $this->table->getPrimaryIndex();
        $this->assertEquals('id', $p->getHashKey());
        $this->assertEquals(DynamoDbItem::ATTRIBUTE_TYPE_NUMBER, $p->getHashKeyType());
        $this->assertNull($p->getRangeKey());
        $this->assertTrue($p->equals($this->table->getPrimaryIndex()));
    }

    public function testGetGlobalSecondaryIndices(): void
    {
        $gsis = $this->table->getGlobalSecondaryIndices();
        $this->assertArrayHasKey('city-code-index', $gsis);
        $gsi = $gsis['city-code-index'];
        $this->assertEquals('city', $gsi->getHashKey());
        $this->assertEquals('code', $gsi->getRangeKey());
        $this->assertEquals('city-code-index', $gsi->getName());
        $this->assertIsString($gsi->getProjectionType());
        $this->assertIsArray($gsi->getProjectedAttributes());
        $this->assertArrayHasKey('ProjectionType', $gsi->getProjection());
    }

    public function testGetLocalSecondaryIndices(): void
    {
        $this->assertEmpty($this->table->getLocalSecondaryIndices());
    }

    public function testGetThroughput(): void
    {
        $tp = $this->table->getThroughput();
        $this->assertCount(2, $tp);
        $this->assertGreaterThan(0, $tp[0]);
    }

    public function testSetAttributeType(): void
    {
        $this->assertSame($this->table, $this->table->setAttributeType('x', DynamoDbItem::ATTRIBUTE_TYPE_STRING));
    }

    // ── Table-mutation tests ────────────────────────────────────

    public function testSetThroughput(): void
    {
        $current = $this->table->getThroughput();
        $this->table->setThroughput((int)$current[0], (int)$current[1]);
        $this->assertEquals($current, $this->table->getThroughput());

        $gsiTp = $this->table->getThroughput('city-code-index');
        $this->assertCount(2, $gsiTp);
        $this->table->setThroughput((int)$gsiTp[0], (int)$gsiTp[1], 'city-code-index');
    }

    #[\PHPUnit\Framework\Attributes\Group('slow')]
    public function testStreamOperations(): void
    {
        $this->table->enableStream('NEW_AND_OLD_IMAGES');
        self::waitForTableActive();

        $viewType = null;
        $this->assertTrue($this->table->isStreamEnabled($viewType));
        $this->assertEquals('NEW_AND_OLD_IMAGES', $viewType);

        $this->table->disableStream();
        self::waitForTableActive();
        $this->assertFalse($this->table->isStreamEnabled($viewType));
    }

    #[\PHPUnit\Framework\Attributes\Group('slow')]
    public function testAddAndDeleteGSI(): void
    {
        $gsi = new DynamoDbIndex('mayor', DynamoDbItem::ATTRIBUTE_TYPE_STRING);
        $gsi->setName('mayor-index');

        $this->table->addGlobalSecondaryIndex($gsi, 5, 5);
        $manager = new DynamoDbManager(UTConfig::$awsConfig);
        $manager->waitForTablesToBeFullyReady(UTConfig::$sharedTableName, 120, 1);
        $this->assertArrayHasKey('mayor-index', $this->table->getGlobalSecondaryIndices('/mayor-index/'));

        $this->table->deleteGlobalSecondaryIndex('mayor-index');
        $manager->waitForTablesToBeFullyReady(UTConfig::$sharedTableName, 120, 1);
        $this->assertEmpty($this->table->getGlobalSecondaryIndices('/mayor-index/'));
    }
}
