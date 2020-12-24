<?php

namespace Oasis\Mlib\AwsWrappers\Contracts;


interface QueueInterface
{
    public function sendMessage($payroll, $delay = 0, $attributes = []);

    public function sendMessages(array $payrolls, $delay = 0, array $attributesList = [], $concurrency = 10);

    public function receiveMessage($wait = null, $visibility_timeout = null, $metas = [], $message_attributes = []);

    public function receiveMessages($max_count, $wait = null);

    public function deleteMessage($msg);

    public function deleteMessages($messages);

    public function getAttribute($name);

    public function getAttributes(array $attributeNames);

}
