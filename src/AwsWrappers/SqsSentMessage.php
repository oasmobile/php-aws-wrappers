<?php

namespace Oasis\Mlib\AwsWrappers;

use Oasis\Mlib\Utils\ArrayDataProvider;
use Oasis\Mlib\Utils\DataType;

class SqsSentMessage extends SqsMessage
{
    public function __construct(array $msg_array)
    {
        parent::__construct($msg_array);
        $dp                    = new ArrayDataProvider($msg_array);
        $this->md5OfBody       = $dp->getMandatory('MD5OfMessageBody', DataType::String);
        $this->md5OfAttributes = $dp->getOptional('MD5OfMessageAttributes', DataType::String, '');
    }
}
