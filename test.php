#! /usr/local/bin/php
<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-04
 * Time: 17:16
 */

use Oasis\Mlib\AwsWrappers\DynamoDbItem;
use Oasis\Mlib\AwsWrappers\DynamoDbManager;

require_once __DIR__ . "/vendor/autoload.php";
$aws = [
    'profile' => 'oasis-minhao',
    'region'  => 'ap-northeast-1',
];

$man = new DynamoDbManager($aws);
//$man->createTable('test2', 'id', DynamoDbItem::ATTRIBUTE_TYPE_STRING, "age", DynamoDbItem::ATTRIBUTE_TYPE_NUMBER);
$man->deleteTable('test2');
$tables = $man->listTables();
var_dump($tables);
