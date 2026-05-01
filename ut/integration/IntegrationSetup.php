<?php
/**
 * Lazy, one-time setup for integration test shared resources.
 *
 * Tables and queues are persistent — they are created if missing and reused
 * across runs. No teardown; cleanup of stale resources happens at next setup.
 */

namespace Oasis\Mlib\AwsWrappers\Test\Integration;

use Oasis\Mlib\AwsWrappers\DynamoDbIndex;
use Oasis\Mlib\AwsWrappers\DynamoDbItem;
use Oasis\Mlib\AwsWrappers\DynamoDbManager;
use Oasis\Mlib\AwsWrappers\DynamoDbTable;
use Oasis\Mlib\AwsWrappers\SqsQueue;
use Oasis\Mlib\AwsWrappers\Test\UTConfig;

class IntegrationSetup
{
    private const DDB_TABLE_SUFFIX = 'shared';
    private const SQS_QUEUE_SUFFIX = 'shared';

    private static bool $ddbReady = false;
    private static bool $sqsReady = false;

    /** Ensure the shared DynamoDB table exists and has seed data. */
    public static function ensureDynamoDb(): void
    {
        if (self::$ddbReady) return;
        self::$ddbReady = true;

        $prefix    = UTConfig::$dynamodbConfig['table-prefix'];
        $tableName = $prefix . self::DDB_TABLE_SUFFIX;
        UTConfig::$sharedTableName = $tableName;

        $manager = new DynamoDbManager(UTConfig::$awsConfig);

        // Check if table already exists
        $existing = $manager->listTables('#^' . preg_quote($tableName) . '$#');
        if (in_array($tableName, $existing, true)) {
            // Table exists — ensure seed data is present
            $table = new DynamoDbTable(UTConfig::$awsConfig, $tableName);
            if ($table->get(['id' => 10]) !== null) {
                return; // seed data already there
            }
            self::seedData($table);
            return;
        }

        // Table doesn't exist — create it
        $manager->createTable(
            $tableName,
            new DynamoDbIndex("id", DynamoDbItem::ATTRIBUTE_TYPE_NUMBER),
            [],
            [
                new DynamoDbIndex(
                    "city", DynamoDbItem::ATTRIBUTE_TYPE_STRING,
                    "code", DynamoDbItem::ATTRIBUTE_TYPE_NUMBER
                ),
            ]
        );
        $manager->waitForTableCreation($tableName, 60, 1);

        $table = new DynamoDbTable(UTConfig::$awsConfig, $tableName);
        self::seedData($table);

        // Busy-wait until GSI data is queryable
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $result = $table->query(
                "#city = :city", ["#city" => "city"], [":city" => "shanghai"],
                "city-code-index"
            );
            if (count($result) >= 5) break;
            usleep(200_000);
        }
    }

    /** Ensure the shared SQS queue exists. */
    public static function ensureSqs(): void
    {
        if (self::$sqsReady) return;
        self::$sqsReady = true;

        $queueName = UTConfig::$sqsConfig['prefix'] . self::SQS_QUEUE_SUFFIX;
        UTConfig::$sharedQueueName = $queueName;

        $sqs = new SqsQueue(UTConfig::$awsConfig, $queueName);
        if ($sqs->exists()) return;

        $sqs->createQueue([
            SqsQueue::VISIBILITY_TIMEOUT => '20',
            SqsQueue::DELAY_SECONDS      => '0',
        ]);
    }

    private static function seedData(DynamoDbTable $table): void
    {
        $writes = [];
        for ($i = 0; $i < 10; ++$i) {
            $writes[10 + $i] = [
                "id"    => 10 + $i,
                "city"  => ($i % 2) ? "beijing" : "shanghai",
                "code"  => 100 + $i,
                "mayor" => (($i % 3) == 0) ? "wang" : ((($i % 3) == 1) ? "ye" : "lee"),
            ];
        }
        $table->batchPut($writes);
    }
}
