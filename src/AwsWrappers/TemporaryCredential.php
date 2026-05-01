<?php

namespace Oasis\Mlib\AwsWrappers;

use Aws\Api\DateTimeResult;

class TemporaryCredential
{
    public ?string $accessKeyId = null;
    public ?string $secretAccessKey = null;
    public ?string $sessionToken = null;
    public mixed $expireDateTime = null;
    public ?int $expireAt = null;
}
