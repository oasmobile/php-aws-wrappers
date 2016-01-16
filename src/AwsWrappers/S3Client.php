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

    public function getPresignedUri($path, $expires = '+30 minutes')
    {
        if (preg_match('#^s3://(.*?)/(.*)$#', $path, $matches)) {
            $bucket = $matches[1];
            $path   = $matches[2];
        }
        else {
            throw new \InvalidArgumentException("path should be a full path starting with s3://");
        }
        $path = ltrim($path, "/");
        $path = preg_replace('#/+#', '/', $path);

        $cmd = $this->getCommand(
            'GetObject',
            [
                "Bucket" => $bucket,
                "Key"    => $path,
            ]
        );
        $req = $this->createPresignedRequest($cmd, $expires);

        return strval($req->getUri());
    }
}
