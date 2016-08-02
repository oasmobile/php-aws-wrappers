# oasis/aws-wrappers for **SNS** service

> **NOTE**: this document assumes you have a valid AWS profile configured for the executing user, and the IAM of this profile has at least "sns:Publish" permission on the related ARN.

> **IMPORTANT**: this document will not cover the definition of SNS and any key concepts related to it. If you are not familiar with SNS, please read the official guide [here](http://docs.aws.amazon.com/sns/latest/dg/welcome.html).

First, you first need to decide, to which topic you are going to publish. Refer [here](http://docs.aws.amazon.com/sns/latest/dg/CreateTopic.html) to find out how to create a topic. Remember to write down the `ARN` for your topic for later usage.

The next step to publish to SNS, is to instantiate an `SnsPublisher` object with the profile and topic information. After the `SnsPublisher` is created, you can call the `publish()` method publish to the specific topic:

```php
<?php

use Oasis\Mlib\AwsWrappers\SnsPublisher;

$sns = new SnsPublisher(
    [
        'profile' => 'tester',
        "region"  => 'us-east-1',
    ],
    "arn-name"
);

$sns->publish('subject', 'body', [SnsPublisher::CHANNEL_EMAIL]);

```
