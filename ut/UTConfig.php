<?php

namespace Oasis\Mlib\AwsWrappers\Test;

use Symfony\Component\Yaml\Yaml;

/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2016-09-09
 * Time: 10:33
 */
class UTConfig
{
    public static $awsConfig   = [];
    public static $awsApConfig = [];
    /** @var string[] */
    public static $dynamodbConfig = [];
    public static $sqsConfig      = [];
    
    public static function load()
    {
        $file = __DIR__ . "/ut.yml";
        $yml  = Yaml::parse(file_get_contents($file));
        
        self::$awsConfig      = $yml['aws'];
        self::$awsApConfig    = $yml['aws-ap'];
        self::$dynamodbConfig = $yml['dynamodb'];
        self::$sqsConfig      = $yml['sqs'];
    }
}
