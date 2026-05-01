<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit\DynamoDb;

use Aws\DynamoDb\DynamoDbClient;

/**
 * Manual stub for DynamoDbClient to avoid PHPUnit 5.7 reflection deprecation warnings.
 *
 * Extends DynamoDbClient to satisfy type hints, but bypasses the constructor
 * and overrides queryAsync/scanAsync to record calls and return queued results.
 */
class StubDynamoDbClient extends DynamoDbClient
{
    /** @var array Recorded queryAsync calls */
    public $queryAsyncCalls = [];

    /** @var array Queued return values for queryAsync */
    public $queryAsyncResults = [];

    /** @var array Recorded scanAsync calls */
    public $scanAsyncCalls = [];

    /** @var array Queued return values for scanAsync */
    public $scanAsyncResults = [];

    private $queryAsyncIndex = 0;
    private $scanAsyncIndex = 0;

    /**
     * Bypass the parent constructor entirely.
     */
    public function __construct()
    {
        // Intentionally empty — do NOT call parent::__construct()
    }

    public function queryAsync(array $args = [])
    {
        $this->queryAsyncCalls[] = $args;

        return $this->queryAsyncResults[$this->queryAsyncIndex++];
    }

    public function scanAsync(array $args = [])
    {
        $this->scanAsyncCalls[] = $args;

        return $this->scanAsyncResults[$this->scanAsyncIndex++];
    }
}
