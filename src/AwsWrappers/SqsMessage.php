<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-10-10
 * Time: 16:51
 */

namespace Oasis\Mlib\AwsWrappers;


use Oasis\Mlib\Utils\ArrayDataProvider;

class SqsMessage
{
    protected $messageId;
    protected $md5OfBody;
    protected $md5OfAttributes;

    function __construct(array $arr_message)
    {
        $dp              = new ArrayDataProvider($arr_message);
        $this->messageId = $dp->getMandatory('MessageId', ArrayDataProvider::STRING_TYPE);
    }

    /**
     * @return mixed
     */
    public function getMd5OfAttributes()
    {
        return $this->md5OfAttributes;
    }

    /**
     * @return mixed
     */
    public function getMd5OfBody()
    {
        return $this->md5OfBody;
    }

    /**
     * @return mixed
     */
    public function getMessageId()
    {
        return $this->messageId;
    }
}
