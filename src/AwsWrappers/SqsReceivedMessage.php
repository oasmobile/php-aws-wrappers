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
    protected $body;
    protected $attributes;

    function __construct(array $msg_array)
    {
        parent::__construct($msg_array);

        $dp = new ArrayDataProvider($msg_array);

        $this->receiptHandle   = $dp->getMandatory('ReceiptHandle', ArrayDataProvider::STRING_TYPE);
        $this->body            = $dp->getMandatory('Body', ArrayDataProvider::STRING_TYPE);
        $this->md5OfBody       = $dp->getMandatory('MD5OfBody', ArrayDataProvider::STRING_TYPE);
        $this->attributes      = $dp->getOptional('MessageAttributes', ArrayDataProvider::ARRAY_TYPE, []);
        $this->md5OfAttributes = $dp->getOptional('MD5OfMessageAttributes', ArrayDataProvider::STRING_TYPE, '');

        $this->validate();
    }

    public function validate()
    {
        if ($this->md5OfBody) {
            if ($this->md5OfBody != md5($this->body)) {
                throw new \UnexpectedValueException("Body md5 doesn't match!");
            }
        }

        if ($this->md5OfAttributes) {
            $encoded_data = '';
            $attributes   = $this->attributes; // make copy to avoid touching original data
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
     * @return string
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * @return array
     */
    public function getAttributes()
    {
        return $this->attributes;
    }

}
