<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2016-01-11
 * Time: 17:01
 */

namespace Oasis\Mlib\AwsWrappers;

interface CredentialProviderInterface
{
    /**
     * @param $durationInSeconds
     *
     * @return TemporaryCredential
     */
    public function getTemporaryCredential($durationInSeconds);
}
