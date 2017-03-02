#! /usr/local/bin/php
<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-04
 * Time: 17:16
 */
use Oasis\Mlib\AwsWrappers\DynamoDbTable;
use Oasis\Mlib\AwsWrappers\SqsQueue;
use Oasis\Mlib\Logging\ConsoleHandler;

require_once __DIR__ . "/vendor/autoload.php";

(new ConsoleHandler())->install();
$aws = [
    'profile' => 'beijing-minhao',
    'region'  => 'cn-north-1',
];

$table = new DynamoDbTable($aws, 'memory_leak_test');

//$table = new Aws\DynamoDb\DynamoDbClient(
//    $aws + [
//        'version' => "2012-08-10",
//    ]
//);
$sqs   = new SqsQueue($aws, 'watch-ut-alert-queue');

$memory = memory_get_peak_usage();
for ($i = 0; $i < 100000; ++$i) {
    $result  = $table->get(['id' => 'abc']);
    //$table->getItem(
    //    [
    //        "TableName" => 'memory_leak_test',
    //        "Key"       => ['id' => ['S' => 'abc']],
    //    ]
    //);
    //$sqs->receiveMessage(null);
    //$sqs = new SqsQueue($aws, 'watch-ut-alert-queue');
    $current = memory_get_peak_usage();
    echo sprintf("Delta = %d, max = %dM\n", $current - $memory, memory_get_peak_usage() / 1024 / 1024);
    $memory = $current;
}
