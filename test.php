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

$table = new DynamoDbTable($aws, "watch-test-values");
$old   = $table->get(["appHash" => "abc", "timestamp" => 123]);
//var_dump($old);
$ret = $table->set(
    [
        "appHash"   => "abc",
        "timestamp" => 123,
        "age"       => 12,
        "gender"    => "f",
        //"code"      => null,
    ],
    ["age" => 12, "gender" => "f", "code" => 54]
);

var_dump($ret);
