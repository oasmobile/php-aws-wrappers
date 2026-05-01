<?php

namespace Oasis\Mlib\AwsWrappers\Contracts;

interface PublisherInterface
{
    public function publish(mixed $subject, mixed $body, array|string $channels = []): void;
}
