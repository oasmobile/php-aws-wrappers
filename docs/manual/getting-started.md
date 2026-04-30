# Getting Started

`docs/manual/` — 快速上手指南，面向首次使用本库的开发者。

---

## 安装

```bash
composer require oasis/aws-wrappers
```

---

## 前置条件

### AWS 凭证

本库依赖 `aws/aws-sdk-php` 进行认证。最常用的方式是配置 AWS Profile：

1. 创建 `~/.aws/credentials` 文件（权限 600）：

```ini
[your-profile]
aws_access_key_id = <YOUR_ACCESS_KEY>
aws_secret_access_key = <YOUR_SECRET_KEY>
```

2. 在构造封装类时传入 profile 名称：

```php
$config = [
    'profile' => 'your-profile',
    'region'  => 'us-east-1',
];
```

### IAM 权限

确保 IAM 账户拥有对应服务的操作权限。各服务所需的最低权限：

| 服务 | 最低权限 |
|------|----------|
| DynamoDB | `dynamodb:*`（表操作）+ `cloudwatch:GetMetricStatistics`（吞吐量监控） |
| S3 | `s3:GetObject`（基础读取）；Presigned URI 需要对应 bucket 的权限 |
| SQS | `sqs:*`（队列操作） |
| SNS | `sns:Publish`（消息发布） |
| STS | `sts:GetSessionToken`（临时凭证） |

---

## 基本用法

### DynamoDB

```php
use Oasis\Mlib\AwsWrappers\DynamoDbTable;

$table = new DynamoDbTable(
    ['profile' => 'your-profile', 'region' => 'us-east-1'],
    'my-table',
    ['id' => 'N', 'name' => 'S']  // 属性类型映射
);

// 写入
$table->set(['id' => 1, 'name' => 'Alice']);

// 读取
$item = $table->get(['id' => 1]);

// 删除
$table->delete(['id' => 1]);

// 查询（使用索引）
$items = $table->query(
    '#id = :val',
    ['#id' => 'id'],
    [':val' => 1]
);
```

### SQS

```php
use Oasis\Mlib\AwsWrappers\SqsQueue;

$queue = new SqsQueue(
    ['profile' => 'your-profile', 'region' => 'us-east-1'],
    'my-queue'
);

// 发送消息
$queue->sendMessage('hello world');

// 接收消息（长轮询 5 秒）
$msg = $queue->receiveMessage(5);
if ($msg) {
    echo $msg->getBody();
    $queue->deleteMessage($msg);
}
```

### SNS

```php
use Oasis\Mlib\AwsWrappers\SnsPublisher;

$sns = new SnsPublisher(
    ['profile' => 'your-profile', 'region' => 'us-east-1'],
    'arn:aws:sns:us-east-1:123456789:my-topic'
);

$sns->publish('subject', 'message body', [SnsPublisher::CHANNEL_EMAIL]);
```

### S3

```php
use Oasis\Mlib\AwsWrappers\S3Client;

$s3 = new S3Client([
    'profile' => 'your-profile',
    'region'  => 'us-east-1',
]);

// 生成预签名 URL（默认 30 分钟有效）
$url = $s3->getPresignedUri('s3://my-bucket/path/to/file.jpg');
```

### STS（临时凭证）

```php
use Oasis\Mlib\AwsWrappers\StsClient;

$sts = new StsClient([
    'profile' => 'your-profile',
    'region'  => 'us-east-1',
]);

$credential = $sts->getTemporaryCredential(3600); // 1 小时有效

// 使用临时凭证构造其他客户端
$table = new DynamoDbTable(
    ['credentials' => $credential, 'region' => 'us-east-1'],
    'my-table'
);
```

---

## 认证方式汇总

```php
// 方式 1：AWS Profile
$config = ['profile' => 'your-profile', 'region' => 'us-east-1'];

// 方式 2：显式凭证
$config = [
    'credentials' => ['key' => '...', 'secret' => '...'],
    'region' => 'us-east-1',
];

// 方式 3：临时凭证（TemporaryCredential 对象）
$config = ['credentials' => $temporaryCredential, 'region' => 'us-east-1'];

// 方式 4：ECS IAM Role
$config = ['iamrole' => true, 'region' => 'us-east-1'];

// 方式 5：环境变量（AWS_ACCESS_KEY_ID + AWS_SECRET_ACCESS_KEY）
$config = ['region' => 'us-east-1'];
```

---

## 更多信息

- DynamoDB 详细用法（Query / Scan / 批量操作）：`docs/DynamoDB.md`
- SQS 详细用法：`docs/SQS.md`
- SNS 详细用法：`docs/SNS.md`
- S3 详细用法：`docs/S3.md`
- Redshift 导入导出：`docs/Redshift.md`
