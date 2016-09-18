#! /usr/local/bin/php
<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-04
 * Time: 17:16
 */

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

$table = new DynamoDbTable($aws, 'test2');
//$gsis  = $table->getGlobalSecondaryIndices();
//var_dump($gsis);
//$lsis  = $table->getLocalSecondaryIndices();
//var_dump($lsis);

//$table->addGlobalSecondaryIndices(
//    new DynamoDbIndex(
//        'type',
//        DynamoDbItem::ATTRIBUTE_TYPE_STRING,
//        'age',
//        DynamoDbItem::ATTRIBUTE_TYPE_NUMBER,
//        DynamoDbIndex::PROJECTION_TYPE_KEYS_ONLY
//    )
//);
$table->deleteGlobalSecondaryIndex('type-age-index');
//
//$table->set(
//    [
//        'hometown' => 'beijing',
//        'id'       => 2,
//        'type'     => 'student',
//        'age'      => 18,
//        'class'    => 'A',
//    ]
//);
//$table->scanAndRun(
//    function ($item) {
//        var_dump($item);
//    },
//    '#type = :type',
//    ['#type' => 'type'],
//    [':type' => 'student'],
//    true
//);
