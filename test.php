#! /usr/local/bin/php
<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-04
 * Time: 17:16
 */

use Aws\DynamoDb\Exception\DynamoDbException;
use Oasis\Mlib\AwsWrappers\DynamoDbIndex;
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
try {
    $manager->deleteTable($tableName);
    $manager->waitForTableDeletion($tableName);
} catch (DynamoDbException $e) {
}
$manager->createTable($tableName, new DynamoDbIndex("id"));
$manager->waitForTableCreation($tableName);

$table = new DynamoDbTable($aws, $tableName);
//$table->multiQueryAndRun(
//    function ($item) {
//        mdebug("Got item: %s", json_encode($item));
//    },
//    'appHash',
//    ['abc', 'dvd', 'xyz'],
//    "#timestamp <= :ts",
//    ["#timestamp" => "timestamp"],
//    [":ts" => 105]
//);

$data = [];
$ids  = [];
for ($i = 0; $i < 1000; ++$i) {
    $data[] = [
        "id"  => strval($i),
        "num" => mt_rand(0, 10),
    ];
    $ids[]  = [
        "id" => strval($i),
    ];
}
mdebug("putting");
$table->batchPut($data, 5);
mdebug("getting");
$ret = $table->batchGet($ids, false, 5);
var_dump(count($ret));
mdebug("counting");
var_dump($table->scanCount());
