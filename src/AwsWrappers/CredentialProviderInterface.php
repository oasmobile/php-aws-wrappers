<?php

namespace Oasis\Mlib\AwsWrappers;

interface CredentialProviderInterface
{
    public function getTemporaryCredential(int $durationInSeconds): TemporaryCredential;
}
