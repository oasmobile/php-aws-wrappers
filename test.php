#! /usr/local/bin/php
<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-04
 * Time: 17:16
 */

require_once __DIR__ . "/vendor/autoload.php";

$sns = new \Oasis\Mlib\AwsWrappers\SnsPublisher(
    [
        "profile" => "minhao",
        "region"  => "us-east-1",
    ],
    "arn:aws:sns:us-east-1:315771499375:alert-log"
);
$sns->publish(
    "[Announcement] We launched SNSPublisher",
    "Great news indeed! we <brotsoft> have lauched the \"SnsPublisher\" class!"
);


