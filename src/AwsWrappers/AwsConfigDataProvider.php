<?php

namespace Oasis\Mlib\AwsWrappers;

use Aws\Credentials\CredentialProvider;
use Aws\Psr16CacheAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Oasis\Mlib\Utils\Exceptions\MandatoryValueMissingException;

class AwsConfigDataProvider
{
    protected array $config;
    
    public function __construct(array $data, ?string $version = null)
    {
        if (!isset($data['region'])) {
            throw new MandatoryValueMissingException("Region must be specified in the AWS config!");
        }
        if (!isset($data['config']) && $version) {
            $data['version'] = $version;
        }
        if (!isset($data['credentials'])
            && !isset($data['profile'])
            && (!getenv('AWS_ACCESS_KEY_ID') || !getenv('AWS_SECRET_ACCESS_KEY'))
            && !getenv('AWS_SESSION_TOKEN')
            && !(isset($data['iamrole']) && $data['iamrole'] != false)
        ) {
            throw new MandatoryValueMissingException("Credentials information not provided in the AWS config!");
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
        elseif (isset($data['iamrole']) && $data['iamrole']) {
            $cacheFile = $data['iamrole'];
            if ($cacheFile === true) {
                $cacheFile = \sys_get_temp_dir() . "/iam.role.cache";
            }
            $psr6Cache           = new FilesystemAdapter('aws_credentials', 0, $cacheFile);
            $psr16Cache          = new Psr16Cache($psr6Cache);
            $cacheAdapter        = new Psr16CacheAdapter($psr16Cache);
            $data['credentials'] = CredentialProvider::cache(CredentialProvider::ecsCredentials(), $cacheAdapter);
        }
        
        $this->config = $data;
    }
    
    public function getConfig(): array
    {
        return $this->config;
    }
}
