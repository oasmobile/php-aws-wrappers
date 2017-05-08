<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2016-01-11
 * Time: 17:04
 */

namespace Oasis\Mlib\AwsWrappers;

class StsClient extends \Aws\Sts\StsClient
    implements CredentialProviderInterface
{
    public function __construct(array $awsConfig)
    {
        // 2017-05-08: could not recall why this endpoint setting is writtern, commented out for now
        //if (!isset($awsConfig['endpoint']) && isset($awsConfig['region'])) {
        //    $awsConfig['endpoint'] = sprintf("https://sts.%s.amazonaws.com", $awsConfig['region']);
        //}
        
        $dp = new AwsConfigDataProvider($awsConfig, '2011-06-15');
        parent::__construct($dp->getConfig());
    }
    
    /**
     * @param int $durationInSeconds default to 43200 which is 12 hours
     *
     * @return TemporaryCredential
     */
    public function getTemporaryCredential($durationInSeconds = 43200)
    {
        $now      = time();
        $expireAt = $now + $durationInSeconds;
        $cmd      = $this->getCommand(
            "GetSessionToken",
            [
                "DurationSeconds" => $durationInSeconds,
            ]
        );
        $result   = $this->execute($cmd);
        
        $credential                  = new TemporaryCredential();
        $credential->expireAt        = $expireAt;
        $credential->sessionToken    = $result['Credentials']['SessionToken'];
        $credential->accessKeyId     = $result['Credentials']['AccessKeyId'];
        $credential->secretAccessKey = $result['Credentials']['SecretAccessKey'];
        $credential->expireDateTime  = $result['Credentials']['Expiration'];
        
        return $credential;
    }
    
}
