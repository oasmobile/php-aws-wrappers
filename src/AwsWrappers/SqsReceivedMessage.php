<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-10-10
 * Time: 14:30
 */

namespace Oasis\Mlib\AwsWrappers;

use Oasis\Mlib\Utils\ArrayDataProvider;
use Oasis\Mlib\Utils\StringUtils;

class SqsReceivedMessage extends SqsMessage
{
    protected $messageId;
    protected $receiptHandle;
    protected $originalBody;
    protected $body;
    protected $originalAttributes;
    
    function __construct(array $msg_array)
    {
        parent::__construct($msg_array);
        
        $dp = new ArrayDataProvider($msg_array);
        
        $this->receiptHandle      = $dp->getMandatory('ReceiptHandle', ArrayDataProvider::STRING_TYPE);
        $this->originalBody       = $dp->getMandatory('Body', ArrayDataProvider::STRING_TYPE);
        $this->md5OfBody          = $dp->getMandatory('MD5OfBody', ArrayDataProvider::STRING_TYPE);
        $this->originalAttributes = $dp->getOptional('MessageAttributes', ArrayDataProvider::ARRAY_TYPE, []);
        $this->md5OfAttributes    = $dp->getOptional('MD5OfMessageAttributes', ArrayDataProvider::STRING_TYPE, '');
        
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
    
    protected function validate()
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
                
                switch ($v['DataType']) {
                    case "String":
                    case "Number":
                        $value = $v['StringValue'];
                        break;
                    case "Binary":
                        $value = $v['BinaryValue'];
                        break;
                    default:
                        throw new \UnexpectedValueException("Unknown data type: {$v['DataType']}");
                        break;
                }
                $vlen = intval(strlen($value));
                
                $encoded_data .= pack('N', $klen);
                $encoded_data .= $k;
                $encoded_data .= pack('N', $tlen);
                $encoded_data .= $v['DataType'];
                $encoded_data .= pack('C', $transport_type);
                $encoded_data .= pack('N', $vlen);
                $encoded_data .= $value;
            }
            
            //mdebug("Calculated md5 = " . md5($encoded_data));
            if ($this->md5OfAttributes != md5($encoded_data)) {
                throw new \UnexpectedValueException("Attribute md5 doesn't match!");
            }
        }
    }
    
    /**
     * @return string
     */
    public function getMessageId()
    {
        return $this->messageId;
    }
    
    /**
     * @return string
     */
    public function getReceiptHandle()
    {
        return $this->receiptHandle;
    }
    
    /**
     * @return mixed
     */
    public function getBody()
    {
        return $this->body;
    }
    
    /**
     * @return string
     */
    public function getOriginalBody()
    {
        return $this->originalBody;
    }
    
    /**
     * @return array
     */
    public function getOriginalAttributes()
    {
        return $this->originalAttributes;
    }
    
    public function getAttribute($attributeKey)
    {
        if (!isset($this->originalAttributes[$attributeKey])) {
            return null;
        }
        
        $msgAttrValue = $this->originalAttributes[$attributeKey];
        switch ($msgAttrValue['DataType']) {
            case 'String':
            case 'Number':
                return $msgAttrValue['StringValue'];
            case 'Binary':
                return $msgAttrValue['BinaryValue'];
            default:
                throw new \UnexpectedValueException("Unknown data type: {$msgAttrValue['DataType']}");
                break;
        }
    }
    
}
