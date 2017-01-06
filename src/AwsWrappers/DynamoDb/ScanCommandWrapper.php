<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2017-01-06
 * Time: 11:40
 */

namespace Oasis\Mlib\AwsWrappers\DynamoDb;

use Aws\DynamoDb\DynamoDbClient;
use Aws\Result;
use Oasis\Mlib\AwsWrappers\DynamoDbItem;

class ScanCommandWrapper
{
    /**
     * @param DynamoDbClient $dbClient
     * @param                $tableName
     * @param callable       $callback
     * @param                $filterExpression
     * @param array          $fieldsMapping
     * @param array          $paramsMapping
     * @param                $indexName
     * @param                $lastKey
     * @param                $evaluationLimit
     * @param                $isConsistentRead
     * @param                $isAscendingOrder
     * @param                $countOnly
     *
     * @return int
     */
    function __invoke(DynamoDbClient $dbClient,
                      $tableName,
                      callable $callback,
                      $filterExpression,
                      array $fieldsMapping,
                      array $paramsMapping,
                      $indexName,
                      &$lastKey,
                      $evaluationLimit,
                      $isConsistentRead,
                      $isAscendingOrder,
                      $countOnly
    )
    {
        $asyncCommandWrapper = new ScanAsyncCommandWrapper();
        $promise             = $asyncCommandWrapper(
            $dbClient,
            $tableName,
            $filterExpression,
            $fieldsMapping,
            $paramsMapping,
            $indexName,
            $lastKey,
            $evaluationLimit,
            $isConsistentRead,
            $isAscendingOrder,
            0,
            1,
            $countOnly
        );
        $promise->then(
            function (Result $result) use (&$lastKey, &$ret, $callback, $countOnly) {
                $lastKey = isset($result['LastEvaluatedKey']) ? $result['LastEvaluatedKey'] : null;
                if ($countOnly) {
                    $ret = $result['Count'];
                }
                else {
                    $items = isset($result['Items']) ? $result['Items'] : [];
                    foreach ($items as $typedItem) {
                        $item = DynamoDbItem::createFromTypedArray($typedItem);
                        call_user_func($callback, $item->toArray());
                    }
                    
                    $ret = count($items);
                }
            }
        );
        $promise->wait();
        
        return $ret;
    }
}
