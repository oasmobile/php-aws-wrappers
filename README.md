# oasis/aws-wrappers

The oasis/aws-wrappers component provides a collection of object oriented warppers for Amazon's official [aws/aws-php-sdk].

## Features

- Only a limited number of widely used AWS services are wrapped.
- One purpose of using the wrapper class is to avoid having to remember too many string constants in AWS SDK.
- The wrapper classes provides simple-and-clear interface by sacrificing many advnaced feature of the original SDK.
- The wrapper classes are extendable, and it is appreciated if you can help extend the wrappers' functionalities.

## Installation

The oasis/aws-wrappers is an open-source component available at `packagist.org`. To require the package, try the following in your project directory:

```bash
composer require oasis/aws-wrappers
```

## Prerequisite

#### Profile

Because oasis/aws-wrappers is only a wrapper on top of [aws/aws-php-sdk], it relies on the profile based authentication mechanism of the official SDK.
You will need to setup a correct AWS profile. Please prepare your `~/.aws/credentials` file with permission 600 and content like below:

```ini
[tester]
aws_access_key_id = <YOUR AWS ACCESS KEY>
aws_secret_access_key = <YOUR AWS SECRET>

```

#### Policy Permission

When using AWS SDK, one thing that is overlooked most of the time is the policy permission. You will have to visit the AWS IAM console to attach correct policy to your IAM account used in the profile setup. Detailed discussion on how to setup a correct policy using policy generator can be found [here](http://docs.aws.amazon.com/IAM/latest/UserGuide/access_policies_create.html#access_policies_create-generator).

## Service Wrappers

- [DynamoDB](docs/DynamoDB.md)
- [SNS](docs/SnsPublisher.md)
- [Redshift import/export](docs/Redshift.md)
- [S3](docs/S3Client.md)
- [SQS](docs/SQS.md)
- [STS](docs/STS.md)

[aws/aws-php-sdk]: https://github.com/aws/aws-sdk-php/ "Official Repository"
