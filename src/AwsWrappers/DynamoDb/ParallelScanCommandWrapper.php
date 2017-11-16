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

class ParallelScanCommandWrapper
{
    /**
     * @param DynamoDbClient $dbClient
     * @param                $tableName
     * @param callable       $callback
     * @param                $filterExpression
     * @param array          $fieldsMapping
     * @param array          $paramsMapping
     * @param                $indexName
     * @param                $evaluationLimit
     * @param                $isConsistentRead
     * @param                $isAscendingOrder
     * @param                $totalSegments
     * @param                $countOnly
     * @param array          $projectedAttributes
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
                      $evaluationLimit,
                      $isConsistentRead,
                      $isAscendingOrder,
                      $totalSegments,
                      $countOnly,
                      $projectedAttributes
    )
    {
        $ret               = 0;
        $stoppedByCallback = false;
        $lastKeys          = [];
        $finished          = 0;
        for ($i = 0; $i < $totalSegments; ++$i) {
            $lastKeys[$i] = null;
        }
        
        while (!$stoppedByCallback && $finished < $totalSegments) {
            $promises = [];
            foreach ($lastKeys as $i => $lastKey) {
                if ($finished == 0 || $lastKey) {
                    $asyncCommandWrapper = new ScanAsyncCommandWrapper();
                    $promise             = $asyncCommandWrapper(
                        $dbClient,
                        $tableName,
                        $filterExpression,
                        $fieldsMapping,
                        $paramsMapping,
                        $indexName,
                        $lastKeys[$i],
                        $evaluationLimit,
                        $isConsistentRead,
                        $isAscendingOrder,
                        $i,
                        $totalSegments,
                        $countOnly,
                        $projectedAttributes
                    );
                    $promise->then(
                        function (Result $result) use (
                            &$lastKeys,
                            $i,
                            &$ret,
                            &$finished,
                            $callback,
                            $countOnly,
                            &$stoppedByCallback
                        ) {
                            if ($stoppedByCallback) {
                                return;
                            }
                            $lastKeys[$i] = isset($result['LastEvaluatedKey']) ? $result['LastEvaluatedKey'] : null;
                            if ($lastKeys[$i] === null) {
                                $finished++;
                            }
                            if ($countOnly) {
                                $ret += $result['Count'];
                            }
                            else {
                                $items = isset($result['Items']) ? $result['Items'] : [];
                                //\mdebug("Total items = %d, seg = %d", count($items), $i);
                                foreach ($items as $typedItem) {
                                    $item = DynamoDbItem::createFromTypedArray($typedItem);
                                    if (false === call_user_func($callback, $item->toArray(), $i)) {
                                        $stoppedByCallback = true;
                                        break;
                                    }
                                }
                                
                                $ret += count($items);
                            }
                        }
                    );
                    $promises[] = $promise;
                }
            }
            if ($promises) {
                \GuzzleHttp\Promise\all($promises)->wait();
            }
        }
        
        return $ret;
    }
}
