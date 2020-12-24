<?php

namespace Oasis\Mlib\AwsWrappers\Contracts;

interface PublisherInterface
{
    public function publish($subject, $body, $channels = []);
}
