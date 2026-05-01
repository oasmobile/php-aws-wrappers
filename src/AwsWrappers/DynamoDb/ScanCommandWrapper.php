<?php

namespace Oasis\Mlib\AwsWrappers\DynamoDb;

use Aws\DynamoDb\DynamoDbClient;
use Aws\Result;
use Oasis\Mlib\AwsWrappers\DynamoDbItem;

class ScanCommandWrapper
{
    public function __invoke(DynamoDbClient $dbClient,
                             string $tableName,
                             callable $callback,
                             ?string $filterExpression,
                             array $fieldsMapping,
                             array $paramsMapping,
                             string|bool $indexName,
                             mixed &$lastKey,
                             ?int $evaluationLimit,
                             bool $isConsistentRead,
                             bool $isAscendingOrder,
                             bool $countOnly,
                             array $projectedAttributes
    ): int
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
            $countOnly,
            $projectedAttributes
        );
        $promise->then(
            function (Result $result) use (&$lastKey, &$ret, $callback, $countOnly): void {
                $lastKey = isset($result['LastEvaluatedKey']) ? $result['LastEvaluatedKey'] : null;
                if ($countOnly) {
                    $ret = $result['Count'];
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
                }
            }
        );
        $promise->wait();
        
        return $ret;
    }
}
