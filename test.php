#! /usr/local/bin/php
<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-04
 * Time: 17:16
 */

use Oasis\Mlib\AwsWrappers\StsClient;

require_once __DIR__ . "/vendor/autoload.php";

//$sns = new SnsPublisher(
//    [
//        'profile' => 'dmp-user',
//        "region"  => 'us-east-1',
//    ],
//    "arn-name"
//);
//$sns->publish('subject', 'body', [SnsPublisher::CHANNEL_EMAIL]);
//
$sts = new StsClient(
    [
        'profile' => 'oasis-minhao',
        'region'  => 'ap-northeast-1',
    ]
);
var_dump($sts->getTemporaryCredential());
