<?php

namespace Oasis\Mlib\AwsWrappers\DynamoDb;

use Aws\DynamoDb\DynamoDbClient;
use Aws\Result;
use Oasis\Mlib\AwsWrappers\DynamoDbItem;

class ParallelScanCommandWrapper
{
    public function __invoke(DynamoDbClient $dbClient,
                             string $tableName,
                             callable $callback,
                             ?string $filterExpression,
                             array $fieldsMapping,
                             array $paramsMapping,
                             string|bool $indexName,
                             ?int $evaluationLimit,
                             bool $isConsistentRead,
                             bool $isAscendingOrder,
                             int $totalSegments,
                             bool $countOnly,
                             array $projectedAttributes
    ): int
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
                        ): void {
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
                \GuzzleHttp\Promise\Utils::all($promises)->wait();
            }
        }
        
        return $ret;
    }
}
