<?php

namespace Oasis\Mlib\AwsWrappers;

class StsClient extends \Aws\Sts\StsClient
    implements CredentialProviderInterface
{
    public function __construct(array $awsConfig)
    {
        $dp = new AwsConfigDataProvider($awsConfig, '2011-06-15');
        parent::__construct($dp->getConfig());
    }
    
    public function getTemporaryCredential(int $durationInSeconds = 43200): TemporaryCredential
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
