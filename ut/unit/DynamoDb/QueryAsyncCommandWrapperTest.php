<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit\DynamoDb;

use GuzzleHttp\Promise\FulfilledPromise;
use Oasis\Mlib\AwsWrappers\DynamoDb\QueryAsyncCommandWrapper;
use Oasis\Mlib\AwsWrappers\DynamoDbIndex;
use PHPUnit\Framework\TestCase;

class QueryAsyncCommandWrapperTest extends TestCase
{
    /** @var StubDynamoDbClient */
    private $stub;

    /** @var QueryAsyncCommandWrapper */
    private $wrapper;

    protected function setUp(): void
    {
        $this->stub    = new StubDynamoDbClient();
        $this->wrapper = new QueryAsyncCommandWrapper();
    }

    // ── Basic query ──────────────────────────────────────────────

    public function testBasicQueryBuildsCorrectArgs()
    {
        $expectedPromise = new FulfilledPromise('ok');
        $this->stub->queryAsyncResults = [$expectedPromise];

        $lastKey = null;
        $result  = call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            '#pk = :pk',
            ['#pk' => 'id'],
            [':pk' => 'val1'],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            &$lastKey,
            null,
            true,
            true,
            false,
            [],
        ]);

        $this->assertSame($expectedPromise, $result);
        $this->assertCount(1, $this->stub->queryAsyncCalls);

        $args = $this->stub->queryAsyncCalls[0];
        $this->assertSame('my-table', $args['TableName']);
        $this->assertTrue($args['ConsistentRead']);
        $this->assertTrue($args['ScanIndexForward']);
        $this->assertSame('#pk = :pk', $args['KeyConditionExpression']);
        $this->assertSame(['#pk' => 'id'], $args['ExpressionAttributeNames']);
        $this->assertArrayHasKey('ExpressionAttributeValues', $args);
        $this->assertArrayNotHasKey('IndexName', $args);
        $this->assertArrayNotHasKey('ExclusiveStartKey', $args);
        $this->assertArrayNotHasKey('Limit', $args);
        $this->assertArrayNotHasKey('Select', $args);
    }

    // ── With filter expression ───────────────────────────────────

    public function testQueryWithFilterExpression()
    {
        $this->stub->queryAsyncResults = [new FulfilledPromise('ok')];

        $lastKey = null;
        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            '#pk = :pk',
            ['#pk' => 'id', '#status' => 'status'],
            [':pk' => 'val1', ':st' => 'active'],
            DynamoDbIndex::PRIMARY_INDEX,
            '#status = :st',
            &$lastKey,
            null,
            false,
            true,
            false,
            [],
        ]);

        $args = $this->stub->queryAsyncCalls[0];
        $this->assertSame('#status = :st', $args['FilterExpression']);
        $this->assertSame('#pk = :pk', $args['KeyConditionExpression']);
        $this->assertArrayHasKey('ExpressionAttributeNames', $args);
        $this->assertArrayHasKey('ExpressionAttributeValues', $args);
    }

    // ── With index name ──────────────────────────────────────────

    public function testQueryWithIndexName()
    {
        $this->stub->queryAsyncResults = [new FulfilledPromise('ok')];

        $lastKey = null;
        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            '#pk = :pk',
            ['#pk' => 'email'],
            [':pk' => 'test@example.com'],
            'email-index',
            null,
            &$lastKey,
            null,
            false,
            true,
            false,
            [],
        ]);

        $args = $this->stub->queryAsyncCalls[0];
        $this->assertSame('email-index', $args['IndexName']);
    }

    // ── With lastKey (pagination) ────────────────────────────────

    public function testQueryWithLastKey()
    {
        $this->stub->queryAsyncResults = [new FulfilledPromise('ok')];

        $lastKey = ['id' => ['S' => 'abc']];
        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            '#pk = :pk',
            ['#pk' => 'id'],
            [':pk' => 'val1'],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            &$lastKey,
            null,
            false,
            true,
            false,
            [],
        ]);

        $args = $this->stub->queryAsyncCalls[0];
        $this->assertSame(['id' => ['S' => 'abc']], $args['ExclusiveStartKey']);
    }

    // ── With evaluation limit ────────────────────────────────────

    public function testQueryWithEvaluationLimit()
    {
        $this->stub->queryAsyncResults = [new FulfilledPromise('ok')];

        $lastKey = null;
        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            '#pk = :pk',
            ['#pk' => 'id'],
            [':pk' => 'val1'],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            &$lastKey,
            25,
            false,
            true,
            false,
            [],
        ]);

        $args = $this->stub->queryAsyncCalls[0];
        $this->assertSame(25, $args['Limit']);
    }

    // ── countOnly ────────────────────────────────────────────────

    public function testQueryCountOnly()
    {
        $this->stub->queryAsyncResults = [new FulfilledPromise('ok')];

        $lastKey = null;
        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            '#pk = :pk',
            ['#pk' => 'id'],
            [':pk' => 'val1'],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            &$lastKey,
            null,
            false,
            true,
            true,
            [],
        ]);

        $args = $this->stub->queryAsyncCalls[0];
        $this->assertSame('COUNT', $args['Select']);
        $this->assertArrayNotHasKey('ProjectionExpression', $args);
    }

    // ── projectedFields ──────────────────────────────────────────

    public function testQueryWithProjectedFields()
    {
        $this->stub->queryAsyncResults = [new FulfilledPromise('ok')];

        $lastKey = null;
        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            '#pk = :pk',
            ['#pk' => 'id'],
            [':pk' => 'val1'],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            &$lastKey,
            null,
            false,
            true,
            false,
            ['name', 'email'],
        ]);

        $args = $this->stub->queryAsyncCalls[0];
        $this->assertSame('SPECIFIC_ATTRIBUTES', $args['Select']);
        $this->assertContains('#name', explode(', ', $args['ProjectionExpression']));
        $this->assertContains('#email', explode(', ', $args['ProjectionExpression']));
        // fieldsMapping should include the projected fields
        $this->assertSame('name', $args['ExpressionAttributeNames']['#name']);
        $this->assertSame('email', $args['ExpressionAttributeNames']['#email']);
    }

    // ── projectedFields conflict exception ───────────────────────

    public function testQueryProjectedFieldsConflictThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->stub->queryAsyncResults = [new FulfilledPromise('ok')];

        $lastKey = null;
        // '#name' already maps to 'different_field' in fieldsMapping,
        // but projectedFields wants '#name' → 'name'
        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            '#pk = :pk',
            ['#pk' => 'id', '#name' => 'different_field'],
            [':pk' => 'val1'],
            DynamoDbIndex::PRIMARY_INDEX,
            null,
            &$lastKey,
            null,
            false,
            true,
            false,
            ['name'],
        ]);
    }

    // ── No ExpressionAttributeNames/Values when no conditions ────

    public function testQueryWithoutConditionsOmitsExpressionAttributes()
    {
        $this->stub->queryAsyncResults = [new FulfilledPromise('ok')];

        $lastKey = null;
        call_user_func_array($this->wrapper, [
            $this->stub,
            'my-table',
            null,       // no keyConditions
            ['#f' => 'field'],
            [':v' => 'val'],
            DynamoDbIndex::PRIMARY_INDEX,
            null,       // no filterExpression
            &$lastKey,
            null,
            false,
            true,
            false,
            [],
        ]);

        $args = $this->stub->queryAsyncCalls[0];
        // When neither keyConditions nor filterExpression is set,
        // ExpressionAttributeNames/Values should NOT be included
        $this->assertArrayNotHasKey('ExpressionAttributeNames', $args);
        $this->assertArrayNotHasKey('ExpressionAttributeValues', $args);
    }
}
