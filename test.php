#! /usr/local/bin/php
<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-04
 * Time: 17:16
 */
use Oasis\Mlib\AwsWrappers\SqsQueue;
use Oasis\Mlib\Logging\ConsoleHandler;

require_once __DIR__ . "/vendor/autoload.php";

(new ConsoleHandler())->install();
$aws = [
    'profile' => 'beijing-minhao',
    'region'  => 'cn-north-1',
];

$sqs = new SqsQueue($aws, 'sqs-test');
//$sqs->createQueue();

$payrolls = [];
for ($i = 1; $i < 30; ++$i) {
    $payrolls[] = json_encode(
        [
            "id"  => $i,
            "val" => md5($i),
        ]
    );
}

$sqs->sendMessages($payrolls);
