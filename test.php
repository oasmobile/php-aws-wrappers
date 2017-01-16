#! /usr/local/bin/php
<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-04
 * Time: 17:16
 */

use Oasis\Mlib\AwsWrappers\DynamoDbManager;
use Oasis\Mlib\AwsWrappers\DynamoDbTable;
use Oasis\Mlib\Logging\ConsoleHandler;

require_once __DIR__ . "/vendor/autoload.php";

(new ConsoleHandler())->install();
$aws = [
    'profile' => 'oasis-minhao',
    'region'  => 'ap-northeast-1',
];

$tableName = "aws-wrappers-test1";
$manager   = new DynamoDbManager($aws);
//try {
//    $manager->deleteTable($tableName);
//    $manager->waitForTableDeletion($tableName);
//} catch (DynamoDbException $e) {
//}
//$manager->createTable(
//    $tableName,
//    new DynamoDbIndex("id"),
//    [],
//    [
//        new DynamoDbIndex("app", DynamoDbItem::ATTRIBUTE_TYPE_STRING, "ts", DynamoDbItem::ATTRIBUTE_TYPE_NUMBER),
//    ]
//);
//$manager->waitForTableCreation($tableName);

$table = new DynamoDbTable($aws, $tableName);
$table->multiQueryAndRun(
    function ($item) {
        mdebug("Got item: %s", json_encode($item));
    },
    'app',
    ['app-1', 'app-0', 'app-2'],
    "#ts between :ts and :ts2",
    ["#ts" => "ts"],
    [":ts" => 5, ":ts2" => 10],
    "app-ts-index",
    '',
    1000,
    false,
    true,
    50
);

//$data = [];
//$ids  = [];
//for ($i = 0; $i < 100; ++$i) {
//    $data[] = [
//        "id"  => strval($i),
//        "app" => sprintf("app-%d", mt_rand(0, 4)),
//        "ts"  => mt_rand(0, 10),
//    ];
//    $ids[]  = [
//        "id" => strval($i),
//    ];
//}
//mdebug("putting");
//$table->batchPut($data, 5);
//mdebug("getting");
//$ret = $table->batchGet($ids, false, 5);
//var_dump(count($ret));
//mdebug("counting");
//var_dump($table->scanCount());
