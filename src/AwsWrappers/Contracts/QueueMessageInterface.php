<?php

namespace Oasis\Mlib\AwsWrappers\Contracts;

interface QueueMessageInterface
{
    /**
     * @return mixed
     */
    public function getBody();
}
