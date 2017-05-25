<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2017-05-08
 * Time: 14:35
 */

namespace Oasis\Mlib\AwsWrappers;

use Oasis\Mlib\Utils\Exceptions\MandatoryValueMissingException;

class AwsConfigDataProvider
{
    protected $config;
    
    /**
     * AwsConfigDataProvider constructor.
     *
     * @param array $data
     * @param null  $version
     */
    public function __construct(array $data, $version = null)
    {
        if (!isset($data['config']) && $version) {
            $data['version'] = $version;
        }
        if (!isset($data['credentials'])
            && !isset($data['profile'])
            && (!getenv('AWS_ACCESS_KEY_ID') || !getenv('AWS_SECRET_ACCESS_KEY'))
            && !getenv('AWS_SESSION_TOKEN')
            && !(isset($data['iamrole']) && $data['iamrole'] == true)
        ) {
            throw new MandatoryValueMissingException("Credentials information not provided in the AWS config!");
        }
        if (!isset($data['region'])) {
            throw new MandatoryValueMissingException("Region must be specified in the AWS config!");
        }
        if (isset($data['credentials']) && $data['credentials'] instanceof TemporaryCredential) {
            /** @var TemporaryCredential $tc */
            $tc                  = $data['credentials'];
            $data['credentials'] = [
                'key'    => $tc->accessKeyId,
                'secret' => $tc->secretAccessKey,
                'token'  => $tc->sessionToken,
            ];
        }
        
        $this->config = $data;
        
    }
    
    /**
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }
    
}
