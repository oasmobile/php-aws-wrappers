# `SnsPublisher` **code example**

To use the `SnsPublisher`, you first need to setup correct AWS profile. Please prepare your `~/.aws/credentials` file with permission 600 and content like below:

```ini
[tester]
aws_access_key_id = <YOUR AWS ACCESS KEY>
aws_secret_access_key = <YOUR AWS SECRET>

```

After having a valid profile, you then need to decide, to which topic you are going to publish. Refer [here](http://docs.aws.amazon.com/sns/latest/dg/CreateTopic.html) to find out how to create a topic. Remember to write down the `ARN` for your topic for later usage.

The next and final step to publish to SNS, is to instantiate an `SnsPublisher` object, and publish to it:

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
