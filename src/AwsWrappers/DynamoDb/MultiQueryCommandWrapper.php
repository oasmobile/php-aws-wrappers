<?php

namespace Oasis\Mlib\AwsWrappers\DynamoDb;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\Result;
use Oasis\Mlib\AwsWrappers\DynamoDbItem;

class MultiQueryCommandWrapper
{
    public function __invoke(DynamoDbClient $dbClient,
                             string $tableName,
                             callable $callback,
                             string $hashKeyName,
                             array $hashKeyValues,
                             ?string $rangeKeyConditions,
                             array $fieldsMapping,
                             array $paramsMapping,
                             string|bool $indexName,
                             ?string $filterExpression,
                             ?int $evaluationLimit,
                             bool $isConsistentRead,
                             bool $isAscendingOrder,
                             int $concurrency,
                             array $projectedFields
    ): void
    {
        $fieldsMapping["#" . $hashKeyName] = $hashKeyName;
        $keyConditions                     = sprintf(
            "#%s = :%s",
            $hashKeyName,
            $hashKeyName
        );
        if ($rangeKeyConditions) {
            $keyConditions .= " AND " . $rangeKeyConditions;
        }
        $concurrency = min($concurrency, count($hashKeyValues));
        
        $queue = new \SplQueue();
        foreach ($hashKeyValues as $hashKeyValue) {
            $queue->push([$hashKeyValue, false]);
        }
        
        $stopped = false;
        
        $generator = function () use (
            &$stopped,
            $dbClient,
            $tableName,
            $callback,
            $queue,
            $hashKeyName,
            $keyConditions,
            $fieldsMapping,
            $paramsMapping,
            $indexName,
            $filterExpression,
            $evaluationLimit,
            $isConsistentRead,
            $isAscendingOrder,
            $projectedFields
        ): \Generator {
            while (!$stopped && !$queue->isEmpty()) {
                list($hashKeyValue, $lastKey) = $queue->shift();
                if ($lastKey === null) {
                    continue;
                }
                $paramsMapping[":" . $hashKeyName] = $hashKeyValue;
                $asyncWrapper                      = new QueryAsyncCommandWrapper();
                $promise                           = $asyncWrapper(
                    $dbClient,
                    $tableName,
                    $keyConditions,
                    $fieldsMapping,
                    $paramsMapping,
                    $indexName,
                    $filterExpression,
                    $lastKey,
                    $evaluationLimit,
                    $isConsistentRead,
                    $isAscendingOrder,
                    false,
                    $projectedFields
                );
                yield $hashKeyValue => $promise;
            }
        };
        
        while (!$stopped && !$queue->isEmpty()) {
            /** @noinspection PhpUnusedParameterInspection */
            \GuzzleHttp\Promise\Each::ofLimit(
                $generator(),
                $concurrency,
                function (Result $result, $hashKeyValue) use ($callback, $queue, &$stopped): void {
                    $lastKey = isset($result['LastEvaluatedKey']) ? $result['LastEvaluatedKey'] : null;
                    $items   = isset($result['Items']) ? $result['Items'] : [];
                    foreach ($items as $typedItem) {
                        $item = DynamoDbItem::createFromTypedArray($typedItem);
                        if (false === call_user_func($callback, $item->toArray())) {
                            $stopped = true;
                            break;
                        }
                    }
                    $queue->push([$hashKeyValue, $lastKey]);
                }
                ,
                function (DynamoDbException $reason,
                    /** @noinspection PhpUnusedParameterInspection */
                          $hashKeyValue): void {
                    throw $reason;
                }
            )->wait();
        }
    }
}
