<?php

namespace Oasis\Mlib\AwsWrappers\Contracts;

interface QueueMessageInterface
{
    public function getBody(): mixed;
}
