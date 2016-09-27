#! /usr/local/bin/php
<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-04
 * Time: 17:16
 */

use Oasis\Mlib\AwsWrappers\SqsQueue;

require_once __DIR__ . "/vendor/autoload.php";
$aws = [
    'profile' => 'oasis-minhao',
    'region'  => 'ap-northeast-1',
];
$sqs = new SqsQueue($aws, 'sqs-test-queue');

$sqs->createQueue([SqsQueue::DELAY_SECONDS => 60]);
var_dump($sqs->exists());
var_dump($sqs->getAttribute(SqsQueue::DELAY_SECONDS));
//$sqs->deleteQueue();
//var_dump($sqs->exists());
//
//var_dump($sqs->getAttributes(SqsQueue::ALL_ATTRIBUTES));
//var_dump(
//    $sqs->setAttributes(
//        [
//            SqsQueue::VISIBILITY_TIMEOUT => 3600,
//        ]
//    )
//);
//var_dump($sqs->getAttributes(SqsQueue::ALL_ATTRIBUTES));
