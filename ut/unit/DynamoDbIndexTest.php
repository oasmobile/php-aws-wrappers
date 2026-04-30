<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit;

use Oasis\Mlib\AwsWrappers\DynamoDbIndex;
use Oasis\Mlib\AwsWrappers\DynamoDbItem;

class DynamoDbIndexTest extends \PHPUnit_Framework_TestCase
{
    // ── Constructor ──────────────────────────────────────────────

    public function testConstructorWithDefaults()
    {
        $index = new DynamoDbIndex('id');

        $this->assertSame('id', $index->getHashKey());
        $this->assertSame(DynamoDbItem::ATTRIBUTE_TYPE_STRING, $index->getHashKeyType());
        $this->assertNull($index->getRangeKey());
        $this->assertSame(DynamoDbItem::ATTRIBUTE_TYPE_STRING, $index->getRangeKeyType());
        $this->assertSame(DynamoDbIndex::PROJECTION_TYPE_ALL, $index->getProjectionType());
        $this->assertSame([], $index->getProjectedAttributes());
    }

    public function testConstructorWithAllParameters()
    {
        $index = new DynamoDbIndex(
            'userId',
            DynamoDbItem::ATTRIBUTE_TYPE_NUMBER,
            'createdAt',
            DynamoDbItem::ATTRIBUTE_TYPE_STRING,
            DynamoDbIndex::PROJECTION_TYPE_INCLUDE,
            ['email', 'name']
        );

        $this->assertSame('userId', $index->getHashKey());
        $this->assertSame(DynamoDbItem::ATTRIBUTE_TYPE_NUMBER, $index->getHashKeyType());
        $this->assertSame('createdAt', $index->getRangeKey());
        $this->assertSame(DynamoDbItem::ATTRIBUTE_TYPE_STRING, $index->getRangeKeyType());
        $this->assertSame(DynamoDbIndex::PROJECTION_TYPE_INCLUDE, $index->getProjectionType());
        $this->assertSame(['email', 'name'], $index->getProjectedAttributes());
    }

    // ── getName / setName ────────────────────────────────────────

    public function testGetNameAutoGeneratesCamelCaseConversion()
    {
        $index = new DynamoDbIndex('userId', DynamoDbItem::ATTRIBUTE_TYPE_STRING, 'createdAt');

        $this->assertSame('user_id-created_at-index', $index->getName());
    }

    public function testGetNameAutoGeneratesSimpleKeys()
    {
        $index = new DynamoDbIndex('id');

        // rangeKey is null → "id--index"
        $this->assertSame('id--index', $index->getName());
    }

    public function testSetNameReturnsCustomName()
    {
        $index = new DynamoDbIndex('userId');
        $result = $index->setName('my-custom-index');

        // setName returns $this for chaining
        $this->assertSame($index, $result);
        $this->assertSame('my-custom-index', $index->getName());
    }

    public function testGetNameCachesResult()
    {
        $index = new DynamoDbIndex('userId', DynamoDbItem::ATTRIBUTE_TYPE_STRING, 'createdAt');

        $first  = $index->getName();
        $second = $index->getName();

        $this->assertSame($first, $second);
    }

    // ── equals ───────────────────────────────────────────────────

    public function testEqualsIdenticalIndices()
    {
        $a = new DynamoDbIndex('pk', DynamoDbItem::ATTRIBUTE_TYPE_STRING, 'sk');
        $b = new DynamoDbIndex('pk', DynamoDbItem::ATTRIBUTE_TYPE_STRING, 'sk');

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsDifferentProjectionType()
    {
        $a = new DynamoDbIndex('pk', DynamoDbItem::ATTRIBUTE_TYPE_STRING, null, DynamoDbItem::ATTRIBUTE_TYPE_STRING, DynamoDbIndex::PROJECTION_TYPE_ALL);
        $b = new DynamoDbIndex('pk', DynamoDbItem::ATTRIBUTE_TYPE_STRING, null, DynamoDbItem::ATTRIBUTE_TYPE_STRING, DynamoDbIndex::PROJECTION_TYPE_KEYS_ONLY);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsSameIncludeButDifferentProjectedAttributes()
    {
        $a = new DynamoDbIndex('pk', DynamoDbItem::ATTRIBUTE_TYPE_STRING, null, DynamoDbItem::ATTRIBUTE_TYPE_STRING, DynamoDbIndex::PROJECTION_TYPE_INCLUDE, ['email']);
        $b = new DynamoDbIndex('pk', DynamoDbItem::ATTRIBUTE_TYPE_STRING, null, DynamoDbItem::ATTRIBUTE_TYPE_STRING, DynamoDbIndex::PROJECTION_TYPE_INCLUDE, ['name']);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsDifferentHashKey()
    {
        $a = new DynamoDbIndex('pk1');
        $b = new DynamoDbIndex('pk2');

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsDifferentHashKeyType()
    {
        $a = new DynamoDbIndex('pk', DynamoDbItem::ATTRIBUTE_TYPE_STRING);
        $b = new DynamoDbIndex('pk', DynamoDbItem::ATTRIBUTE_TYPE_NUMBER);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsOneHasRangeKeyOtherDoesNot()
    {
        $a = new DynamoDbIndex('pk', DynamoDbItem::ATTRIBUTE_TYPE_STRING, 'sk');
        $b = new DynamoDbIndex('pk', DynamoDbItem::ATTRIBUTE_TYPE_STRING, null);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsDifferentRangeKey()
    {
        $a = new DynamoDbIndex('pk', DynamoDbItem::ATTRIBUTE_TYPE_STRING, 'sk1');
        $b = new DynamoDbIndex('pk', DynamoDbItem::ATTRIBUTE_TYPE_STRING, 'sk2');

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsDifferentRangeKeyType()
    {
        $a = new DynamoDbIndex('pk', DynamoDbItem::ATTRIBUTE_TYPE_STRING, 'sk', DynamoDbItem::ATTRIBUTE_TYPE_STRING);
        $b = new DynamoDbIndex('pk', DynamoDbItem::ATTRIBUTE_TYPE_STRING, 'sk', DynamoDbItem::ATTRIBUTE_TYPE_NUMBER);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsBothNoRangeKey()
    {
        $a = new DynamoDbIndex('pk');
        $b = new DynamoDbIndex('pk');

        $this->assertTrue($a->equals($b));
    }

    // ── getKeySchema ─────────────────────────────────────────────

    public function testGetKeySchemaHashOnly()
    {
        $index = new DynamoDbIndex('pk');

        $expected = [
            ['AttributeName' => 'pk', 'KeyType' => 'HASH'],
        ];

        $this->assertSame($expected, $index->getKeySchema());
    }

    public function testGetKeySchemaHashAndRange()
    {
        $index = new DynamoDbIndex('pk', DynamoDbItem::ATTRIBUTE_TYPE_STRING, 'sk');

        $expected = [
            ['AttributeName' => 'pk', 'KeyType' => 'HASH'],
            ['AttributeName' => 'sk', 'KeyType' => 'RANGE'],
        ];

        $this->assertSame($expected, $index->getKeySchema());
    }

    // ── getProjection ────────────────────────────────────────────

    public function testGetProjectionAll()
    {
        $index = new DynamoDbIndex('pk');

        $this->assertSame(['ProjectionType' => 'ALL'], $index->getProjection());
    }

    public function testGetProjectionInclude()
    {
        $index = new DynamoDbIndex(
            'pk', DynamoDbItem::ATTRIBUTE_TYPE_STRING,
            null, DynamoDbItem::ATTRIBUTE_TYPE_STRING,
            DynamoDbIndex::PROJECTION_TYPE_INCLUDE,
            ['email', 'name']
        );

        $expected = [
            'ProjectionType'   => 'INCLUDE',
            'NonKeyAttributes' => ['email', 'name'],
        ];

        $this->assertSame($expected, $index->getProjection());
    }

    public function testGetProjectionKeysOnly()
    {
        $index = new DynamoDbIndex(
            'pk', DynamoDbItem::ATTRIBUTE_TYPE_STRING,
            null, DynamoDbItem::ATTRIBUTE_TYPE_STRING,
            DynamoDbIndex::PROJECTION_TYPE_KEYS_ONLY
        );

        $this->assertSame(['ProjectionType' => 'KEYS_ONLY'], $index->getProjection());
    }

    // ── getAttributeDefinitions ──────────────────────────────────

    public function testGetAttributeDefinitionsKeyAsNameTrue()
    {
        $index = new DynamoDbIndex('pk', DynamoDbItem::ATTRIBUTE_TYPE_STRING, 'sk', DynamoDbItem::ATTRIBUTE_TYPE_NUMBER);

        $result = $index->getAttributeDefinitions(true);

        $expected = [
            'pk' => ['AttributeName' => 'pk', 'AttributeType' => 'S'],
            'sk' => ['AttributeName' => 'sk', 'AttributeType' => 'N'],
        ];

        $this->assertSame($expected, $result);
    }

    public function testGetAttributeDefinitionsKeyAsNameFalse()
    {
        $index = new DynamoDbIndex('pk', DynamoDbItem::ATTRIBUTE_TYPE_STRING, 'sk', DynamoDbItem::ATTRIBUTE_TYPE_NUMBER);

        $result = $index->getAttributeDefinitions(false);

        $expected = [
            ['AttributeName' => 'pk', 'AttributeType' => 'S'],
            ['AttributeName' => 'sk', 'AttributeType' => 'N'],
        ];

        $this->assertSame($expected, $result);
    }

    public function testGetAttributeDefinitionsHashOnly()
    {
        $index = new DynamoDbIndex('pk');

        $result = $index->getAttributeDefinitions(true);

        $expected = [
            'pk' => ['AttributeName' => 'pk', 'AttributeType' => 'S'],
        ];

        $this->assertSame($expected, $result);
    }
}
