<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2017-05-08
 * Time: 16:55
 */

namespace Oasis\Mlib\AwsWrappers\Test\Integration;

use Aws\S3\Exception\S3Exception;
use Oasis\Mlib\AwsWrappers\S3Client;
use Oasis\Mlib\AwsWrappers\StsClient;
use Oasis\Mlib\AwsWrappers\Test\UTConfig;
use PHPUnit\Framework\TestCase;

class StsClientIntegrationTest extends TestCase
{
    public function testGetTempToken()
    {
        $bucket = UTConfig::$s3Config['bucket'] ?? 'aw-ut-s3-bucket';

        // Step 1: verify fake credentials are rejected
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
            $s3->listObjects(['Bucket' => $bucket, 'MaxKeys' => 1]);
            throw new \RuntimeException("should fail authentication!");
        } catch (S3Exception $e) {
            $this->assertContains(
                $e->getAwsErrorCode(),
                ['InvalidAccessKeyId', 'SignatureDoesNotMatch']
            );
        }

        // Step 2: verify temporary credentials work
        $sts            = new StsClient(UTConfig::$awsConfig);
        $tempCredential = $sts->getTemporaryCredential(900);
        $s3             = new S3Client(
            [
                'credentials' => $tempCredential,
                'region'      => 'cn-north-1',
            ]
        );
        $result = $s3->listObjects(['Bucket' => $bucket, 'MaxKeys' => 1]);
        $this->assertIsArray($result->get('Contents') ?? []);
    }
}
