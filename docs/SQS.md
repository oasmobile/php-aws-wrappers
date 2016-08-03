# oasis/aws-wrappers for **SQS** service

> **NOTE**: this document assumes you have a valid AWS profile configured for the executing user, and the IAM of this profile has at least "sqs:*" permission on the related ARN.

> **IMPORTANT**: this document will not cover the definition of SQS and any key concepts related to it. If you are not familiar with SQS, please read the official guide [here](http://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/Welcome.html).

The SQS wrapper in oasis/aws-wrappers is the `SqsQueue` class.

## Constructing

An `SqsQueue` object can be created like below:

```php
<?php

use Oasis\Mlib\AwsWrappers\SqsQueue;

$queue = new SqsQueue(
    [
        "profile" => "tester",
        "region"  => "us-east-1",
    ],
    "sqs-demo-queue" // queue name
);
```

## Send Message

Each message to be sent consists of a payroll string and an optional attribute set. In addition, you can decide if the message should be delayed (so that the receiver won't receive the message immediately):

```php
<?php
$queue->sendMessage(
    "This is the payroll",
    0, // delay in seconds
    [
        "attribute1" => "abc",
        "attribute2" => "xyz",
    ]
);
```

When you have more than one message to be sent at once, you can use the `sendMessages()` method:

```php
<?php
$queue->sendMessages(
    [
        "payroll1",
        "payroll2",
    ],
    0, // delay in seconds
    [
        [
            "attribute1" => "abc",
        ],
        [
            "attribute1" => "xyz",
        ]
    ]
);
```

> **NOTE**: when sending messages with attributes in batch, the number of payrolls and number of attributes should be the same.

## Receive Message

To receive a message, you should first understand two concepts:

- [long polling](http://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/sqs-long-polling.html)
- [visibility timeout](http://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/AboutVT.html)

`SqsQueue` allows you to receive messages with different options:

```php
<?php
$received = $queue->receiveMessage(
    5, // wait time seconds for long polling
    null // use default visibility timeout
);
$received = $queue->receiveMessage(
    null, // don't block when reading
    86400 // visibility timeout for 1 day
);
```

The received message is of type `SqsReceivedMessage`, and you can get payroll or attributes out of this object easily:

```php
<?php
$payroll = $received->getBody();
$attrs   = $received->getAttributes();
```

Batch reading of messages is also supported:

```php
<?php
/** @var SqsReceivedMessage[] $msgs */
$msgs = $queue->receiveMessages(
    50, // max number of messages to read
    5, // wait time seconds
    null // use default visibility timeout
);
```

## Delete Message

By default, a message that is read from an SQS queue will remain invisible to other receivers for a period of time (defined as visibility timeout). The message will become accessible again once this period runs off. In practice, we need to delete such message when it is successfully handled. To achieve this, you should use the `deleteMessage()` or `deleteMessages()` method:

```php
<?php

/** @var SqsReceivedMessage $msg */
$queue->deleteMessage($msg);

/** @var SqsReceivedMessage[] $msgs */
$queue->deleteMessages($msgs);
```
