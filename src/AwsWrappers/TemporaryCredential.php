<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2016-01-11
 * Time: 17:01
 */

namespace Oasis\Mlib\AwsWrappers;

use Aws\Api\DateTimeResult;

class TemporaryCredential
{
    /** @var  string */
    public $accessKeyId;
    /** @var  string */
    public $secretAccessKey;
    /** @var  string */
    public $sessionToken;
    /** @var  DateTimeResult */
    public $expireDateTime;
    /** @var  integer timestamp */
    public $expireAt;
}
