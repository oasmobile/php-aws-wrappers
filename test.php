#! /usr/local/bin/php
<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-04
 * Time: 17:16
 */

use Oasis\Mlib\AwsWrappers\DynamoDbTable;
use Oasis\Mlib\Logging\ConsoleHandler;

require_once __DIR__ . "/vendor/autoload.php";

(new ConsoleHandler())->install();
$aws   = [
    'profile' => 'oasis-minhao',
    'region'  => 'ap-northeast-1',
];
$table = new DynamoDbTable($aws, $tableName = "watch-test-values");
$table->multiQueryAndRun(
    function ($item) {
        mdebug("Got item: %s", json_encode($item));
    },
    'appHash',
    ['abc', 'dvd', 'xyz'],
    "#timestamp <= :ts",
    ["#timestamp" => "timestamp"],
    [":ts" => 105]
);
