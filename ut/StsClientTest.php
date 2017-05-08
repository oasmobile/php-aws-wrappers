<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2017-05-08
 * Time: 16:55
 */

namespace Oasis\Mlib\AwsWrappers\Test;

use Aws\S3\Exception\S3Exception;
use Oasis\Mlib\AwsWrappers\S3Client;
use Oasis\Mlib\AwsWrappers\StsClient;

class StsClientTest extends \PHPUnit_Framework_TestCase
{
    public function testGetTempToken()
    {
        $s3 = new S3Client(
            [
                'credentials' => [
                    'key'    => 'foo',
                    'secret' => 'bar',
                    'token'  => 'foobar',
                ],
                'region'      => 'cn-north-1',
            ]
        );
        try {
            $s3->listBuckets();
            throw new \RuntimeException("should fail authentication!");
        } catch (S3Exception $e) {
            $this->assertEquals('InvalidAccessKeyId', $e->getAwsErrorCode());
        }
        $sts            = new StsClient(UTConfig::$awsConfig);
        $tempCredential = $sts->getTemporaryCredential(900);
        $s3             = new S3Client(
            [
                'credentials' => $tempCredential,
                'region'      => 'cn-north-1',
            ]
        );
        $s3->listBuckets();
    }
}
