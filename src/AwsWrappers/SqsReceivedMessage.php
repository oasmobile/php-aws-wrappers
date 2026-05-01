<?php

namespace Oasis\Mlib\AwsWrappers;

use Oasis\Mlib\AwsWrappers\Contracts\QueueMessageInterface;
use Oasis\Mlib\Utils\ArrayDataProvider;
use Oasis\Mlib\Utils\DataType;
use Oasis\Mlib\Utils\StringUtils;

class SqsReceivedMessage extends SqsMessage implements QueueMessageInterface
{
    protected string $messageId;
    protected string $receiptHandle;
    protected string $originalBody;
    protected mixed $body;
    protected array $originalAttributes;
    
    public function __construct(array $msg_array)
    {
        parent::__construct($msg_array);
        
        $dp = new ArrayDataProvider($msg_array);
        
        $this->receiptHandle      = $dp->getMandatory('ReceiptHandle', DataType::String);
        $this->originalBody       = $dp->getMandatory('Body', DataType::String);
        $this->md5OfBody          = $dp->getMandatory('MD5OfBody', DataType::String);
        $this->originalAttributes = $dp->getOptional('MessageAttributes', DataType::Array, []);
        $this->md5OfAttributes    = $dp->getOptional('MD5OfMessageAttributes', DataType::String, '');
        
        $this->validate();
        
        if ($this->getAttribute(SqsQueue::SERIALIZATION_FLAG) == 'base64_serialize') {
            $this->body = unserialize(base64_decode($this->originalBody));
        }
        else {
            try {
                $json = \GuzzleHttp\json_decode($this->originalBody, true);
                if (isset($json['Message'])) {
                    $this->body = $json['Message'];
                    
                    if (isset($json['Subject']) && $json['Subject'] == 'base64_serialize') {
                        $this->body = unserialize(base64_decode($this->body));
                    }
                }
                else {
                    $this->body = $this->originalBody;
                }
            } catch (\InvalidArgumentException $e) {
                // Can't parse body out from json
                $this->body = $this->originalBody;
            }
        }
    }
    
    protected function validate(): void
    {
        if ($this->md5OfBody) {
            if ($this->md5OfBody != md5($this->originalBody)) {
                throw new \UnexpectedValueException("Body md5 doesn't match!");
            }
        }
        
        if ($this->md5OfAttributes) {
            $encoded_data = '';
            $attributes   = $this->originalAttributes; // make copy to avoid touching original data
            ksort($attributes);
            foreach ($attributes as $k => $v) {
                $klen = intval(strlen($k));
                
                $tlen = intval(strlen($v['DataType']));
                
                if (StringUtils::stringStartsWith($v['DataType'], "Binary")) {
                    $transport_type = 2;
                }
                else {
                    $transport_type = 1;
                }
                
                $value = match ($v['DataType']) {
                    'String', 'Number' => $v['StringValue'],
                    'Binary'           => $v['BinaryValue'],
                    default            => throw new \UnexpectedValueException("Unknown data type: {$v['DataType']}"),
                };
                $vlen = intval(strlen($value));
                
                $encoded_data .= pack('N', $klen);
                $encoded_data .= $k;
                $encoded_data .= pack('N', $tlen);
                $encoded_data .= $v['DataType'];
                $encoded_data .= pack('C', $transport_type);
                $encoded_data .= pack('N', $vlen);
                $encoded_data .= $value;
            }
            
            if ($this->md5OfAttributes != md5($encoded_data)) {
                throw new \UnexpectedValueException("Attribute md5 doesn't match!");
            }
        }
    }
    
    public function getMessageId(): string
    {
        return $this->messageId;
    }
    
    public function getReceiptHandle(): string
    {
        return $this->receiptHandle;
    }
    
    public function getBody(): mixed
    {
        return $this->body;
    }
    
    public function getOriginalBody(): string
    {
        return $this->originalBody;
    }
    
    public function getOriginalAttributes(): array
    {
        return $this->originalAttributes;
    }
    
    public function getAttribute(string $attributeKey): ?string
    {
        if (!isset($this->originalAttributes[$attributeKey])) {
            return null;
        }
        
        $msgAttrValue = $this->originalAttributes[$attributeKey];
        
        return match ($msgAttrValue['DataType']) {
            'String', 'Number' => $msgAttrValue['StringValue'],
            'Binary'           => $msgAttrValue['BinaryValue'],
            default            => throw new \UnexpectedValueException("Unknown data type: {$msgAttrValue['DataType']}"),
        };
    }
}
