#! /usr/local/bin/php
<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-04
 * Time: 17:16
 */

use Oasis\Mlib\AwsWrappers\DrdStreamReader;
use Oasis\Mlib\AwsWrappers\RedshiftHelper;

require_once __DIR__ . "/vendor/autoload.php";

$objects = [
    [
        "name" => "Josh",
        "age"  => 13,
        "job"  => "The o'looka police",
    ],
    [
        "name" => "Martin",
        "age"  => 3,
    ],
    [
        "name" => "O'riel",
        "age"  => 21,
        "job"  => "pub | priv",
    ],
    [
        "name" => "Nanting",
        "job"  => "glad to\nwin",
    ],
    [
        "age" => 0,
        "job"  => "not yet born",
    ],
    []
];
$fields  = [
    "name",
    "age",
    "job",
];

$file = fopen('/tmp/aaa', 'w');
foreach ($objects as $obj) {
    $line = RedshiftHelper::formatToRedshiftLine($obj, $fields);
    fwrite($file, $line . PHP_EOL);
    fwrite($file, PHP_EOL);
}
fclose($file);

$fh = fopen('/tmp/aaa', 'r');
$reader = new DrdStreamReader($fh, $fields);
while ($row = $reader->readRecord()) {
    var_dump($row);
}
fclose($fh);
