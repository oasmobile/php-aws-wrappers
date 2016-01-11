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

$sts = new StsClient([
    "profile" => "minhao",
    "region" => 'us-east-1',
]);
$result = $sts->getTemporaryCredential(3600);
var_dump($result);
