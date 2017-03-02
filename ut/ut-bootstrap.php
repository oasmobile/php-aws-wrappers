<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2017-01-05
 * Time: 16:23
 */
use Oasis\Mlib\AwsWrappers\Test\UTConfig;
use Monolog\Logger;
use Oasis\Mlib\Logging\ConsoleHandler;
use Oasis\Mlib\Logging\MLogging;

require_once __DIR__ . "/../vendor/autoload.php";

UTConfig::load();

(new ConsoleHandler())->install();
if (!in_array('-v', $_SERVER['argv'])
    && !in_array('--verbose', $_SERVER['argv'])
) {
    MLogging::setMinLogLevel(Logger::CRITICAL);
}
