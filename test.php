#! /usr/local/bin/php
<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-04
 * Time: 17:16
 */

use Oasis\Mlib\AwsWrappers\DynamoDbIndex;
use Oasis\Mlib\AwsWrappers\DynamoDbTable;

require_once __DIR__ . "/vendor/autoload.php";
$aws = [
    'profile' => 'oasis-minhao',
    'region'  => 'ap-northeast-1',
];

//$man = new DynamoDbManager($aws);
//$man->createTable(
//    "test3",
//    new DynamoDbIndex("hometown", DynamoDbItem::ATTRIBUTE_TYPE_STRING, "id", DynamoDbItem::ATTRIBUTE_TYPE_NUMBER),
//    [
//        new DynamoDbIndex("hometown2", DynamoDbItem::ATTRIBUTE_TYPE_STRING, "age", DynamoDbItem::ATTRIBUTE_TYPE_NUMBER),
//    ],
//    [
//        new DynamoDbIndex("class", DynamoDbItem::ATTRIBUTE_TYPE_STRING, "age", DynamoDbItem::ATTRIBUTE_TYPE_NUMBER),
//    ]
//);

$table = new DynamoDbTable($aws, 'export-test-items');
$enabled = $table->isStreamEnabled($type);
var_dump($enabled);
var_dump($type);
$table->enableStream();
$enabled = $table->isStreamEnabled($type);
var_dump($enabled);
var_dump($type);
