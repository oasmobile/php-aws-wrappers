#! /usr/local/bin/php
<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-04
 * Time: 17:16
 */

use Oasis\Mlib\AwsWrappers\S3Client;

require_once __DIR__ . "/vendor/autoload.php";

$s3 = new S3Client(
    [
        'profile' => 'dmp-user',
        "region"  => 'us-east-1',
    ]
);

$uri = $s3->getPresignedUri('s3://brotsoft-dmp/speedtest.txt');

var_dump($uri);


