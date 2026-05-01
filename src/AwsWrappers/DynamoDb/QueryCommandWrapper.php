<?php

namespace Oasis\Mlib\AwsWrappers\DynamoDb;

use Aws\DynamoDb\DynamoDbClient;
use Oasis\Mlib\AwsWrappers\DynamoDbItem;

class QueryCommandWrapper
{
    public function __invoke(DynamoDbClient $dbClient,
                             string $tableName,
                             callable $callback,
                             ?string $keyConditions,
                             array $fieldsMapping,
                             array $paramsMapping,
                             string|bool $indexName,
                             ?string $filterExpression,
                             mixed &$lastKey,
                             ?int $evaluationLimit,
                             bool $isConsistentRead,
                             bool $isAscendingOrder,
                             bool $countOnly,
                             array $projectedFields): int
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
