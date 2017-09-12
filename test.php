#! /usr/local/bin/php
<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-04
 * Time: 17:16
 */
use Oasis\Mlib\AwsWrappers\DynamoDbTable;
use Oasis\Mlib\Logging\ConsoleHandler;

require_once __DIR__ . "/vendor/autoload.php";

(new ConsoleHandler())->install();
$aws = [
    'profile' => 'beijing-minhao',
    'region'  => 'cn-north-1',
];

$table  = new DynamoDbTable($aws, 'watch-test-values');
$result = [];
$count  = 0;
$table->parallelScanAndRun(
    5,
    function ($item, $i) use (&$result, &$count) {
        $result[$i] = (isset($result[$i]) ? $result[$i] : 0) + 1;
        
        if (++$count > 50) {
            mdebug("stop!");
            
            return false;
        }
    }
);
var_dump($result);
