#! /usr/local/bin/php
<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-04
 * Time: 17:16
 */
use Oasis\Mlib\AwsWrappers\SnsPublisher;
use Oasis\Mlib\AwsWrappers\SqsQueue;
use Oasis\Mlib\Logging\ConsoleHandler;

require_once __DIR__ . "/vendor/autoload.php";

(new ConsoleHandler())->install();
$aws = [
    'profile' => 'beijing-minhao',
    'region'  => 'cn-north-1',
];

$obj       = new stdClass();
$obj->name = 'jank';
var_dump($obj);

$sns = new SnsPublisher($aws, 'arn:aws-cn:sns:cn-north-1:341381255897:dynamodb-manager-modset-ready');
$sns->publishToSubscribedSQS($obj);

$sqs = new SqsQueue($aws, 'dynamodb-manager-on-modset-ready');
while ($msg = $sqs->receiveMessage()) {
    var_dump($msg->getBody());
    $sqs->deleteMessage($msg);
}

