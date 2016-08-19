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
    public function __construct(array $args)
    {
        if (!isset($args['version'])) {
            $args['version'] = "2011-06-15";
        }
        if (!isset($args['endpoint']) && isset($args['region'])) {
            $args['endpoint'] = sprintf("https://sts.%s.amazonaws.com", $args['region']);
        }
        parent::__construct($args);
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
