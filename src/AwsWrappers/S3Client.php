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
    public function __construct(array $awsConfig)
    {
        if (!isset($awsConfig['endpoint']) && isset($awsConfig['region'])) {
            $awsConfig['endpoint'] =
                \preg_match('/^cn-.*/', $awsConfig['region']) ?
                    sprintf("https://s3.%s.amazonaws.com.cn", $awsConfig['region']) :
                    (
                    \preg_match('/^us-east-1$/', $awsConfig['region']) ?
                        "http://s3.amazonaws.com" :
                        sprintf("https://s3-%s.amazonaws.com", $awsConfig['region'])
                    );
        }
        $dp = new AwsConfigDataProvider($awsConfig, '2006-03-01');
        parent::__construct($dp->getConfig());
    }
    
    /**
     * @NOTE: presigned URL will not work in AWS China
     *
     * @param        $path
     * @param string $expires
     *
     * @return string
     */
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
