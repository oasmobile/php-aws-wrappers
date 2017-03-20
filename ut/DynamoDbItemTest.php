<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2017-03-20
 * Time: 17:30
 */

namespace Oasis\Mlib\AwsWrappers\Test;

use Oasis\Mlib\AwsWrappers\DynamoDbItem;

class DynamoDbItemTest extends \PHPUnit_Framework_TestCase
{
    public function testCreation()
    {
        $untyped = [
            "name"  => "John",
            "age"   => 17,
            "speed" => 9.5,
            "null"  => null,
            "male"  => true,
            "list"  => [
                "apple",
                "orange",
            ],
            "map"   => [
                "a" => "apple",
                "b" => "banana",
            ],
        ];
        $typed   = [
            "name"  => ["S" => "John"],
            "age"   => ["N" => "17"],
            "speed" => ["N" => "9.5"],
            "null"  => ["NULL" => true],
            "male"  => ["BOOL" => true],
            "list"  => [
                "L" => [
                    ["S" => "apple"],
                    ["S" => "orange"],
                ],
            ],
            "map"   => [
                "M" => [
                    "a" => ["S" => "apple"],
                    "b" => ["S" => "banana"],
                ],
            ],
        ];
        $item    = DynamoDbItem::createFromArray($untyped);
        $this->assertEquals($untyped, $item->toArray());
        $this->assertEquals($typed, $item->getData());
        
        $item2 = DynamoDbItem::createFromTypedArray($typed);
        $this->assertEquals($untyped, $item2->toArray());
        $this->assertEquals($typed, $item2->getData());
        
        return $item;
    }
    
    /**
     * @depends testCreation
     *
     * @param DynamoDbItem $item
     */
    public function testArrayAccess(DynamoDbItem $item)
    {
        $this->assertArrayHasKey('name', $item);
        $this->assertArrayHasKey('age', $item);
        $this->assertArrayHasKey('speed', $item);
        $this->assertArrayHasKey('null', $item);
        $this->assertArrayHasKey('male', $item);
        $this->assertArrayHasKey('list', $item);
        $this->assertArrayHasKey('map', $item);
        
        $this->assertArrayNotHasKey('dummy', $item);
        
        $this->assertSame('John', $item['name']);
        $this->assertSame(17, $item['age']);
        $this->assertSame(9.5, $item['speed']);
        $this->assertSame(null, $item['null']);
        $this->assertSame(true, $item['male']);
        $this->assertSame(["apple", "orange"], $item['list']);
        $this->assertSame(["a" => "apple", "b" => "banana"], $item['map']);
        
        unset($item['speed']);
        $this->assertArrayNotHasKey('speed', $item);
        
        $item['speed'] = 11.5;
        $this->assertArrayHasKey('speed', $item);
        $this->assertSame(11.5, $item['speed']);
    }
}
