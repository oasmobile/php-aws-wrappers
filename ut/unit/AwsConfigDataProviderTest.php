<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit;

use Oasis\Mlib\AwsWrappers\AwsConfigDataProvider;
use Oasis\Mlib\AwsWrappers\TemporaryCredential;
use Oasis\Mlib\Utils\Exceptions\MandatoryValueMissingException;

class AwsConfigDataProviderTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var string|false Original AWS_ACCESS_KEY_ID env value
     */
    private $origAccessKeyId;

    /**
     * @var string|false Original AWS_SECRET_ACCESS_KEY env value
     */
    private $origSecretAccessKey;

    /**
     * @var string|false Original AWS_SESSION_TOKEN env value
     */
    private $origSessionToken;

    protected function setUp()
    {
        // Save original env values
        $this->origAccessKeyId     = getenv('AWS_ACCESS_KEY_ID');
        $this->origSecretAccessKey = getenv('AWS_SECRET_ACCESS_KEY');
        $this->origSessionToken    = getenv('AWS_SESSION_TOKEN');

        // Clear env to ensure tests are isolated
        putenv('AWS_ACCESS_KEY_ID');
        putenv('AWS_SECRET_ACCESS_KEY');
        putenv('AWS_SESSION_TOKEN');
    }

    protected function tearDown()
    {
        // Restore original env values
        if ($this->origAccessKeyId !== false) {
            putenv('AWS_ACCESS_KEY_ID=' . $this->origAccessKeyId);
        } else {
            putenv('AWS_ACCESS_KEY_ID');
        }
        if ($this->origSecretAccessKey !== false) {
            putenv('AWS_SECRET_ACCESS_KEY=' . $this->origSecretAccessKey);
        } else {
            putenv('AWS_SECRET_ACCESS_KEY');
        }
        if ($this->origSessionToken !== false) {
            putenv('AWS_SESSION_TOKEN=' . $this->origSessionToken);
        } else {
            putenv('AWS_SESSION_TOKEN');
        }
    }

    // ================================================================
    // Region validation
    // ================================================================

    public function testThrowsWhenRegionMissing()
    {
        $this->setExpectedException(
            MandatoryValueMissingException::class,
            'Region must be specified'
        );

        new AwsConfigDataProvider([
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);
    }

    // ================================================================
    // Credentials: explicit credentials array
    // ================================================================

    public function testExplicitCredentialsArray()
    {
        $dp = new AwsConfigDataProvider([
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'AKID', 'secret' => 'SECRET'],
        ]);

        $config = $dp->getConfig();

        $this->assertSame('us-east-1', $config['region']);
        $this->assertSame(['key' => 'AKID', 'secret' => 'SECRET'], $config['credentials']);
    }

    // ================================================================
    // Credentials: profile
    // ================================================================

    public function testProfileCredentials()
    {
        $dp = new AwsConfigDataProvider([
            'region'  => 'us-west-2',
            'profile' => 'my-profile',
        ]);

        $config = $dp->getConfig();

        $this->assertSame('my-profile', $config['profile']);
    }

    // ================================================================
    // Credentials: TemporaryCredential object
    // ================================================================

    public function testTemporaryCredentialIsConvertedToArray()
    {
        $tc = new TemporaryCredential();
        $tc->accessKeyId     = 'AKID_TEMP';
        $tc->secretAccessKey = 'SECRET_TEMP';
        $tc->sessionToken    = 'SESSION_TOKEN';

        $dp = new AwsConfigDataProvider([
            'region'      => 'eu-west-1',
            'credentials' => $tc,
        ]);

        $config = $dp->getConfig();

        $this->assertInternalType('array', $config['credentials']);
        $this->assertSame('AKID_TEMP', $config['credentials']['key']);
        $this->assertSame('SECRET_TEMP', $config['credentials']['secret']);
        $this->assertSame('SESSION_TOKEN', $config['credentials']['token']);
    }

    // ================================================================
    // Credentials: IAM Role with custom cache path
    // ================================================================

    public function testIamRoleWithCustomCachePath()
    {
        $dp = new AwsConfigDataProvider([
            'region'  => 'us-east-1',
            'iamrole' => '/tmp/custom-cache-path',
        ]);

        $config = $dp->getConfig();

        // credentials should be set to a callable (CredentialProvider::cache returns a callable)
        $this->assertTrue(is_callable($config['credentials']));
    }

    // ================================================================
    // Credentials: IAM Role with true (default cache path)
    // ================================================================

    public function testIamRoleWithTrueUsesDefaultCachePath()
    {
        $dp = new AwsConfigDataProvider([
            'region'  => 'us-east-1',
            'iamrole' => true,
        ]);

        $config = $dp->getConfig();

        $this->assertTrue(is_callable($config['credentials']));
    }

    // ================================================================
    // Credentials: environment variables
    // ================================================================

    public function testEnvironmentVariableCredentials()
    {
        putenv('AWS_ACCESS_KEY_ID=ENV_AKID');
        putenv('AWS_SECRET_ACCESS_KEY=ENV_SECRET');

        $dp = new AwsConfigDataProvider([
            'region' => 'us-east-1',
        ]);

        $config = $dp->getConfig();

        // Should not throw — env vars provide credentials
        $this->assertSame('us-east-1', $config['region']);
        // credentials key should not be set (SDK picks up from env)
        $this->assertArrayNotHasKey('credentials', $config);
    }

    public function testSessionTokenAloneIsValidCredential()
    {
        putenv('AWS_SESSION_TOKEN=my-session-token');

        $dp = new AwsConfigDataProvider([
            'region' => 'us-east-1',
        ]);

        $config = $dp->getConfig();

        $this->assertSame('us-east-1', $config['region']);
    }

    // ================================================================
    // Credentials: missing → throws
    // ================================================================

    public function testThrowsWhenNoCredentialsProvided()
    {
        $this->setExpectedException(
            MandatoryValueMissingException::class,
            'Credentials information not provided'
        );

        new AwsConfigDataProvider([
            'region' => 'us-east-1',
        ]);
    }

    public function testThrowsWhenIamRoleFalse()
    {
        $this->setExpectedException(
            MandatoryValueMissingException::class,
            'Credentials information not provided'
        );

        new AwsConfigDataProvider([
            'region'  => 'us-east-1',
            'iamrole' => false,
        ]);
    }

    public function testThrowsWhenOnlyAccessKeyWithoutSecret()
    {
        putenv('AWS_ACCESS_KEY_ID=AKID_ONLY');
        // AWS_SECRET_ACCESS_KEY is not set

        $this->setExpectedException(
            MandatoryValueMissingException::class,
            'Credentials information not provided'
        );

        new AwsConfigDataProvider([
            'region' => 'us-east-1',
        ]);
    }

    // ================================================================
    // Version: explicit version parameter
    // ================================================================

    public function testVersionIsSetFromConstructorParameter()
    {
        $dp = new AwsConfigDataProvider([
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ], '2012-08-10');

        $config = $dp->getConfig();

        $this->assertSame('2012-08-10', $config['version']);
    }

    public function testVersionIsNotSetWhenNull()
    {
        $dp = new AwsConfigDataProvider([
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $config = $dp->getConfig();

        $this->assertArrayNotHasKey('version', $config);
    }

    public function testVersionIsNotOverriddenWhenConfigKeyExists()
    {
        $dp = new AwsConfigDataProvider([
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'config'      => ['some' => 'value'],
        ], '2012-08-10');

        $config = $dp->getConfig();

        // When 'config' key exists, version should NOT be set
        $this->assertArrayNotHasKey('version', $config);
    }

    // ================================================================
    // getConfig: returns full config array
    // ================================================================

    public function testGetConfigReturnsCompleteArray()
    {
        $dp = new AwsConfigDataProvider([
            'region'      => 'ap-northeast-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'endpoint'    => 'http://localhost:8000',
        ], '2012-08-10');

        $config = $dp->getConfig();

        $this->assertSame('ap-northeast-1', $config['region']);
        $this->assertSame('http://localhost:8000', $config['endpoint']);
        $this->assertSame('2012-08-10', $config['version']);
    }

    // ================================================================
    // Extra keys are preserved
    // ================================================================

    public function testExtraKeysArePreservedInConfig()
    {
        $dp = new AwsConfigDataProvider([
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'http'        => ['timeout' => 5],
        ]);

        $config = $dp->getConfig();

        $this->assertSame(['timeout' => 5], $config['http']);
    }

    // ================================================================
    // Unicode region (edge case)
    // ================================================================

    public function testRegionWithUnicodeDoesNotThrow()
    {
        // Region is set (even if invalid for AWS), so no MandatoryValueMissingException
        $dp = new AwsConfigDataProvider([
            'region'      => '区域-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $config = $dp->getConfig();
        $this->assertSame('区域-1', $config['region']);
    }
}
