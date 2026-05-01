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
    public static array $awsConfig   = [];
    public static array $awsApConfig = [];
    /** @var string[] */
    public static array $dynamodbConfig = [];
    public static array $sqsConfig      = [];
    public static array $s3Config       = [];

    /** Shared resources created once per suite run (set by bootstrap). */
    public static string $sharedTableName = '';
    public static string $sharedQueueName = '';

    public static function load(): void
    {
        $file = __DIR__ . "/ut.yml";
        $yml  = Yaml::parse(file_get_contents($file));
        
        self::$awsConfig      = $yml['aws'];
        self::$awsApConfig    = $yml['aws-ap'];
        self::$dynamodbConfig = $yml['dynamodb'];
        self::$sqsConfig      = $yml['sqs'];
        self::$s3Config       = $yml['s3'] ?? [];
    }
}
