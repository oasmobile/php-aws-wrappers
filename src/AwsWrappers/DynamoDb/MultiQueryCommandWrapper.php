<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2017-01-11
 * Time: 16:54
 */

namespace Oasis\Mlib\AwsWrappers\DynamoDb;

use Aws\DynamoDb\DynamoDbClient;
use Aws\Result;
use Oasis\Mlib\AwsWrappers\DynamoDbItem;

class MultiQueryCommandWrapper
{
    function __invoke(DynamoDbClient $dbClient,
                      $tableName,
                      callable $callback,
                      $hashKeyName,
                      $hashKeyValues,
                      $rangeKeyConditions,
                      array $fieldsMapping,
                      array $paramsMapping,
                      $indexName,
                      $filterExpression,
                      $evaluationLimit,
                      $isConsistentRead,
                      $isAscendingOrder
    )
    {
        $fieldsMapping["#" . $hashKeyName] = $hashKeyName;
        $keyConditions                     = sprintf(
            "#%s = :%s AND (%s)",
            $hashKeyName,
            $hashKeyName,
            $rangeKeyConditions
        );
        
        $queue = new \SplQueue();
        foreach ($hashKeyValues as $hashKeyValue) {
            $queue->push([$hashKeyValue, false]);
        }
        $generator = function () use (
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
            $isAscendingOrder
        ) {
            while (!$queue->isEmpty()) {
                list($hashKeyValue, $lastKey) = $queue->shift();
                if ($lastKey === null) {
                    //minfo("Finished for hash key %s", $hashKeyValue);
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
                    false
                );
                $promise->then(
                    function (Result $result) use ($callback, $queue, $hashKeyValue) {
                        $lastKey = isset($result['LastEvaluatedKey']) ? $result['LastEvaluatedKey'] : null;
                        $items   = isset($result['Items']) ? $result['Items'] : [];
                        foreach ($items as $typedItem) {
                            $item = DynamoDbItem::createFromTypedArray($typedItem);
                            call_user_func($callback, $item->toArray());
                        }
                        $queue->push([$hashKeyValue, $lastKey]);
                    }
                );
                //mdebug("yielded %s", \GuzzleHttp\json_encode($paramsMapping));
                yield $promise;
            }
        };
        
        while (!$queue->isEmpty()) {
            \GuzzleHttp\Promise\each_limit($generator(), count($hashKeyValues))->wait();
        }
    }
    
}
