<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2016-01-10
 * Time: 19:07
 */

namespace Oasis\Mlib\AwsWrappers;

class S3Client extends \Aws\S3\S3Client
{
    public function __construct(array $args)
    {
        if (!isset($args['version'])) {
            $args['version'] = "2006-03-01";
        }
        parent::__construct($args);
    }
}
