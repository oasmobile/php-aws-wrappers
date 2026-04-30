<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit\DynamoDb;

use GuzzleHttp\Promise\FulfilledPromise;
use Oasis\Mlib\AwsWrappers\DynamoDb\ScanAsyncCommandWrapper;
use Oasis\Mlib\AwsWrappers\DynamoDbIndex;
use PHPUnit\Framework\TestCase;

class ScanAsyncCommandWrapperTest extends TestCase
{
    /** @var StubDynamoDbClient */
    private $stub;

    /** @var ScanAsyncCommandWrapper */
    private $wrapper;

    protected function setUp(): void
    {
        $this->stub    = new StubDynamoDbClient();
        $this->wrapper = new ScanAsyncCommandWrapper();
    }

    // ── Basic scan ───────────────────────────────────────────────

    public function testBasicScanBuildsCorrectArgs()
    {
        $expectedPromise = new FulfilledPromise('ok');
        $this->stub->scanAsyncResults = [$expectedPromise];

        $lastKey = null;
        $result  = call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            null,       // filterExpression
            [],         // fieldsMapping
            [],         // paramsMapping
            DynamoDbIndex::PRIMARY_INDEX,
            &$lastKey,
            null,       // evaluationLimit
            true,       // isConsistentRead
            true,       // isAscendingOrder
            0,          // segment
            1,          // totalSegments
            false,      // countOnly
            [],         // projectedFields
        ]);

        $this->assertSame($expectedPromise, $result);
        $this->assertCount(1, $this->stub->scanAsyncCalls);

        $args = $this->stub->scanAsyncCalls[0];
        $this->assertSame('my-table', $args['TableName']);
        $this->assertTrue($args['ConsistentRead']);
        $this->assertTrue($args['ScanIndexForward']);
        $this->assertArrayNotHasKey('FilterExpression', $args);
        $this->assertArrayNotHasKey('ExpressionAttributeNames', $args);
        $this->assertArrayNotHasKey('ExpressionAttributeValues', $args);
        $this->assertArrayNotHasKey('IndexName', $args);
        $this->assertArrayNotHasKey('ExclusiveStartKey', $args);
        $this->assertArrayNotHasKey('Limit', $args);
        $this->assertArrayNotHasKey('Select', $args);
        // totalSegments=1 → no Segment/TotalSegments
        $this->assertArrayNotHasKey('Segment', $args);
        $this->assertArrayNotHasKey('TotalSegments', $args);
    }

    // ── With filter expression ───────────────────────────────────

    public function testScanWithFilterExpression()
    {
        $this->stub->scanAsyncResults = [new FulfilledPromise('ok')];

        $lastKey = null;
        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            '#status = :st',
            ['#status' => 'status'],
            [':st' => 'active'],
            DynamoDbIndex::PRIMARY_INDEX,
            &$lastKey,
            null,
            false,
            true,
            0,
            1,
            false,
            [],
        ]);

        $args = $this->stub->scanAsyncCalls[0];
        $this->assertSame('#status = :st', $args['FilterExpression']);
        $this->assertSame(['#status' => 'status'], $args['ExpressionAttributeNames']);
        $this->assertArrayHasKey('ExpressionAttributeValues', $args);
    }

    // ── With segment/totalSegments (parallel scan) ───────────────

    public function testScanWithSegmentAndTotalSegments()
    {
        $this->stub->scanAsyncResults = [new FulfilledPromise('ok')];

        $lastKey = null;
        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            null,
            [],
            [],
            DynamoDbIndex::PRIMARY_INDEX,
            &$lastKey,
            null,
            false,
            true,
            2,          // segment
            4,          // totalSegments > 1
            false,
            [],
        ]);

        $args = $this->stub->scanAsyncCalls[0];
        $this->assertSame(2, $args['Segment']);
        $this->assertSame(4, $args['TotalSegments']);
    }

    // ── countOnly ────────────────────────────────────────────────

    public function testScanCountOnly()
    {
        $this->stub->scanAsyncResults = [new FulfilledPromise('ok')];

        $lastKey = null;
        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            null,
            [],
            [],
            DynamoDbIndex::PRIMARY_INDEX,
            &$lastKey,
            null,
            false,
            true,
            0,
            1,
            true,       // countOnly
            [],
        ]);

        $args = $this->stub->scanAsyncCalls[0];
        $this->assertSame('COUNT', $args['Select']);
        $this->assertArrayNotHasKey('ProjectionExpression', $args);
    }

    // ── projectedFields ──────────────────────────────────────────

    public function testScanWithProjectedFields()
    {
        $this->stub->scanAsyncResults = [new FulfilledPromise('ok')];

        $lastKey = null;
        // Suppress deprecated implode() parameter order warning in source code
        @call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            null,
            [],
            [],
            DynamoDbIndex::PRIMARY_INDEX,
            &$lastKey,
            null,
            false,
            true,
            0,
            1,
            false,
            ['name', 'email'],
        ]);

        $args = $this->stub->scanAsyncCalls[0];
        $this->assertSame('SPECIFIC_ATTRIBUTES', $args['Select']);
        $this->assertContains('#name', explode(', ', $args['ProjectionExpression']));
        $this->assertContains('#email', explode(', ', $args['ProjectionExpression']));
        $this->assertSame('name', $args['ExpressionAttributeNames']['#name']);
        $this->assertSame('email', $args['ExpressionAttributeNames']['#email']);
    }

    // ── projectedFields conflict exception ───────────────────────

    public function testScanProjectedFieldsConflictThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->stub->scanAsyncResults = [new FulfilledPromise('ok')];

        $lastKey = null;
        @call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            null,
            ['#name' => 'different_field'],
            [],
            DynamoDbIndex::PRIMARY_INDEX,
            &$lastKey,
            null,
            false,
            true,
            0,
            1,
            false,
            ['name'],
        ]);
    }

    // ── With index name ──────────────────────────────────────────

    public function testScanWithIndexName()
    {
        $this->stub->scanAsyncResults = [new FulfilledPromise('ok')];

        $lastKey = null;
        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            null,
            [],
            [],
            'my-gsi-index',
            &$lastKey,
            null,
            false,
            true,
            0,
            1,
            false,
            [],
        ]);

        $args = $this->stub->scanAsyncCalls[0];
        $this->assertSame('my-gsi-index', $args['IndexName']);
    }

    // ── With lastKey and evaluationLimit ──────────────────────────

    public function testScanWithLastKeyAndLimit()
    {
        $this->stub->scanAsyncResults = [new FulfilledPromise('ok')];

        $lastKey = ['id' => ['S' => 'cursor']];
        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            null,
            [],
            [],
            DynamoDbIndex::PRIMARY_INDEX,
            &$lastKey,
            50,
            false,
            true,
            0,
            1,
            false,
            [],
        ]);

        $args = $this->stub->scanAsyncCalls[0];
        $this->assertSame(['id' => ['S' => 'cursor']], $args['ExclusiveStartKey']);
        $this->assertSame(50, $args['Limit']);
    }
}
