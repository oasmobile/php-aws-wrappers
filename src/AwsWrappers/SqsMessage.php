<?php

namespace Oasis\Mlib\AwsWrappers;

use Oasis\Mlib\Utils\ArrayDataProvider;
use Oasis\Mlib\Utils\DataType;

class SqsMessage
{
    protected string $messageId;
    protected ?string $md5OfBody = null;
    protected ?string $md5OfAttributes = null;

    public function __construct(array $arr_message)
    {
        $dp              = new ArrayDataProvider($arr_message);
        $this->messageId = $dp->getMandatory('MessageId', DataType::String);
    }

    public function getMd5OfAttributes(): ?string
    {
        return $this->md5OfAttributes;
    }

    public function getMd5OfBody(): ?string
    {
        return $this->md5OfBody;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }
}
