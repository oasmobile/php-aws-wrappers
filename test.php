#! /usr/local/bin/php
<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-04
 * Time: 17:16
 */

use Aws\DynamoDb\Exception\DynamoDbException;
use Oasis\Mlib\AwsWrappers\DynamoDbIndex;
use Oasis\Mlib\AwsWrappers\DynamoDbItem;
use Oasis\Mlib\AwsWrappers\DynamoDbManager;
use Oasis\Mlib\AwsWrappers\DynamoDbTable;
use Oasis\Mlib\Logging\ConsoleHandler;

require_once __DIR__ . "/vendor/autoload.php";

(new ConsoleHandler())->install();
$aws   = [
    'profile' => 'oasis-minhao',
    'region'  => 'ap-northeast-1',
];
$table = new DynamoDbTable($aws, "watch-test-values");

$tnames = [
    'watch-test-values3',
    'watch-test-values4',
];
foreach ($tnames as $tname) {
    $manager = new DynamoDbManager($aws);
    try {
        $manager->deleteTable($tname);
    } catch (DynamoDbException $e) {
        if ($e->getAwsErrorCode() != "ResourceNotFoundException") {
            throw $e;
        }
    }
    $manager->waitForTableDeletion($tname);
    $manager->createTable(
        $tname,
        new DynamoDbIndex("id", DynamoDbItem::ATTRIBUTE_TYPE_STRING, "time", DynamoDbItem::ATTRIBUTE_TYPE_NUMBER),
        [],
        [
            new DynamoDbIndex("app", DynamoDbItem::ATTRIBUTE_TYPE_STRING, "time", DynamoDbItem::ATTRIBUTE_TYPE_NUMBER),
        ]
    );
}
$manager->waitForTablesToBeFullyReady($tnames);
$table = new DynamoDbTable($aws, $tname);
$table->addGlobalSecondaryIndex(
    new DynamoDbIndex("class", DynamoDbItem::ATTRIBUTE_TYPE_STRING, "time", DynamoDbItem::ATTRIBUTE_TYPE_NUMBER)
);
$manager->waitForTablesToBeFullyReady($tnames, 10);
