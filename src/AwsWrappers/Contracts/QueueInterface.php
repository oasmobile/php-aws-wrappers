<?php

namespace Oasis\Mlib\AwsWrappers\Contracts;

interface QueueInterface
{
    public function sendMessage(mixed $payroll, int $delay = 0, array $attributes = []): mixed;

    public function sendMessages(array $payrolls, int $delay = 0, array $attributesList = [], int $concurrency = 10): array;

    public function receiveMessage(?int $wait = null, ?int $visibility_timeout = null, array $metas = [], array $message_attributes = []): mixed;

    public function receiveMessages(int $max_count, ?int $wait = null): array;

    public function deleteMessage(mixed $msg): void;

    public function deleteMessages(mixed $messages): void;

    public function getAttribute(string $name): mixed;

    public function getAttributes(array $attributeNames): array;
}
