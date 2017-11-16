<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2017-01-06
 * Time: 11:40
 */

namespace Oasis\Mlib\AwsWrappers\DynamoDb;

use Aws\DynamoDb\DynamoDbClient;
use Oasis\Mlib\AwsWrappers\DynamoDbItem;

class QueryCommandWrapper
{
    /**
     * @param DynamoDbClient $dbClient
     * @param                $tableName
     * @param callable       $callback
     * @param                $keyConditions
     * @param array          $fieldsMapping
     * @param array          $paramsMapping
     * @param                $indexName
     * @param                $filterExpression
     * @param                $lastKey
     * @param                $evaluationLimit
     * @param                $isConsistentRead
     * @param                $isAscendingOrder
     * @param                $countOnly
     * @param array          $projectedFields
     *
     * @return array|bool
     */
    function __invoke(DynamoDbClient $dbClient,
                      $tableName,
                      callable $callback,
                      $keyConditions,
                      array $fieldsMapping,
                      array $paramsMapping,
                      $indexName,
                      $filterExpression,
                      &$lastKey,
                      $evaluationLimit,
                      $isConsistentRead,
                      $isAscendingOrder,
                      $countOnly,
                      $projectedFields)
    {
        $asyncWrapper = new QueryAsyncCommandWrapper();
        
        $promise = $asyncWrapper(
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
            $countOnly,
            $projectedFields
        );
        $result  = $promise->wait();
        $lastKey = isset($result['LastEvaluatedKey']) ? $result['LastEvaluatedKey'] : null;
        
        if ($countOnly) {
            return $result['Count'];
        }
        else {
            $items = isset($result['Items']) ? $result['Items'] : [];
            $ret   = 0;
            foreach ($items as $typedItem) {
                $ret++;
                $item = DynamoDbItem::createFromTypedArray($typedItem);
                if (false === call_user_func($callback, $item->toArray())) {
                    break;
                }
            }
            
            return $ret;
        }
    }
}
