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
        parent::__construct($args);
    }
    
    /**
     * @param $durationInSeconds
     *
     * @return TemporaryCredential
     */
    public function getTemporaryCredential($durationInSeconds)
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

        return $credential;
    }

}
