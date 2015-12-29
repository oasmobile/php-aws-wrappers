<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-10-10
 * Time: 14:30
 */

namespace Oasis\Mlib\AwsWrappers;

use Oasis\Mlib\Utils\ArrayDataProvider;

/**
 * Class SqsMessage
 *
 * @package Oasis\Mlib\AwsWrappers
 *
 *          array(5) {
 * 'MessageId' =>
 * string(36) "6a0f35b7-1406-4eb7-8be9-5b6eccea9f38"
 * 'ReceiptHandle' =>
 * string(348)
 * "AQEBdRRBWicUzkp8TtXJooQHPfrND6Ccz9h+UFch1CuY17RI2JOzf4z5OnJhzHdXnaWuH4Ko1iCEIYl0HwP+8LK3pRKZdXoXuk/M1kGYp46H4rYG38dVKV8tA7A4HOJuGjH6CRG0tIMFaIZOoEbnH3TOGsCI/KRylwxj+0Msl9ir6jZGKfdg7KwRmfkcJgkLN7TN2xjNPcR3eKtxDPsmQnQiqE2XXGsyBRrtCIMEdA9ieV2SIMdIzFRykxIpFrKL391Ghp5CUMeh0QLLlhUTE8yP0r4MfKlA5Z9zdLLpNL/O8AMYr0n4bsRkKIU2FpwTr8ZhKUlTEJVyIZVaUbmpyZqmew=="
 * 'MD5OfBody' =>
 * string(32) "73ab8813905ece03f7f2d0c0628c1fe0"
 * 'Body' =>
 * string(13) "test body 100"
 * 'Attributes' =>
 * array(1) {
 * ...
 * }
 * }
 */
class SqsSentMessage extends SqsMessage
{
    function __construct(array $msg_array)
    {
        parent::__construct($msg_array);
        $dp                    = new ArrayDataProvider($msg_array);
        $this->md5OfBody       = $dp->getMandatory('MD5OfMessageBody', ArrayDataProvider::STRING_TYPE);
        $this->md5OfAttributes = $dp->getOptional('MD5OfMessageAttributes', ArrayDataProvider::STRING_TYPE, '');
    }
}
