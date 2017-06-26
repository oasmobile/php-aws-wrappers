#! /usr/local/bin/php
<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-04
 * Time: 17:16
 */
use Oasis\Mlib\AwsWrappers\SnsPublisher;
use Oasis\Mlib\Logging\AwsSnsHandler;
use Oasis\Mlib\Logging\ConsoleHandler;
use Oasis\Mlib\Logging\ShutdownFallbackHandler;

require_once __DIR__ . "/vendor/autoload.php";

(new ConsoleHandler())->install();
$aws = [
    'profile' => 'beijing-minhao',
    'region'  => 'cn-north-1',
];

$publisher       = new SnsPublisher($aws, 'arn:aws-cn:sns:cn-north-1:341381255897:minhao-to-receive');
$handler         = new AwsSnsHandler($publisher, 'something went wrong!');
$fallbackHandler = new ShutdownFallbackHandler($handler);
$fallbackHandler->install();

mdebug("ahaa");
$a = function() {
    mdebug("jjj");
};
$a();

$b->call();
//malert('nonono');

