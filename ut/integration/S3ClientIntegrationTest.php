<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2017-05-08
 * Time: 15:33
 */

namespace Oasis\Mlib\AwsWrappers\Test\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use League\Uri\Http;
use Oasis\Mlib\AwsWrappers\S3Client;
use Oasis\Mlib\AwsWrappers\Test\UTConfig;
use PHPUnit\Framework\TestCase;

class S3ClientIntegrationTest extends TestCase
{
    public function testPresignedUri()
    {
        $bucket    = UTConfig::$s3Config['bucket'] ?? 'aw-ut-s3-bucket';
        $keyPrefix = UTConfig::$s3Config['key-prefix'] ?? 'aw-ut-s3test/';
        $key       = $keyPrefix . 's3client-test';
        $s3        = new S3Client(UTConfig::$awsApConfig);
        $s3->putObject(
            [
                'Bucket' => $bucket,
                'Key'    => $key,
                'Body'   => 'testnote',
            ]
        );
        $url      = $s3->getPresignedUri("s3://{$bucket}/" . $key, '+3 minutes');
        $client   = new Client();
        $response = $client->request('GET', $url);
        $this->assertEquals('testnote', $response->getBody()->getContents());
        
        $unsignedUrl = Http::new($url)->withQuery('');
        try {
            $client->request('GET', $unsignedUrl);
            throw new \RuntimeException("Should not come here!");
        } catch (ClientException $e) {
            $this->assertEquals(403, $e->getResponse()->getStatusCode());
        }
    }
}
