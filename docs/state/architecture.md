# Architecture

`docs/state/` — 系统架构与工程约束（SSOT）。

---

## 概述

`oasis/aws-wrappers` 是一个 PHP Composer 库，对 AWS SDK (`aws/aws-sdk-php`) 中常用服务进行面向对象封装。目标是降低使用门槛、减少字符串常量记忆负担，同时保持可扩展性。

---

## 封装的 AWS 服务

| 服务 | 封装类 | 说明 |
|------|--------|------|
| DynamoDB | `DynamoDbManager`、`DynamoDbTable`、`DynamoDbItem`、`DynamoDbIndex` | 表管理、CRUD、Query/Scan、批量操作、并行扫描 |
| S3 | `S3Client` | 继承自 `Aws\S3\S3Client`，增加 Presigned URI 生成 |
| SQS | `SqsQueue`、`SqsMessage`、`SqsReceivedMessage`、`SqsSentMessage` | 队列创建/删除、消息收发/批量操作、序列化支持 |
| SNS | `SnsPublisher` | 多通道消息发布（Email / SQS / Lambda / APNS / GCM 等） |
| STS | `StsClient` | 获取临时凭证（`TemporaryCredential`） |

---

## 核心组件

### `AwsConfigDataProvider`

统一的 AWS 配置处理器，负责：

- 校验必填字段（`region`、凭证信息）
- 设置 SDK 版本号
- 处理多种认证方式：profile、credentials 数组、`TemporaryCredential`、IAM Role（ECS）

### `DynamoDbTable`

DynamoDB 操作的核心类，提供：

- 单项操作：`get()`、`set()`、`delete()`
- 批量操作：`batchGet()`、`batchPut()`、`batchDelete()`
- 查询：`query()`、`queryAndRun()`、`queryCount()`、`multiQueryAndRun()`
- 扫描：`scan()`、`scanAndRun()`、`scanCount()`、`parallelScanAndRun()`
- 表管理：`describe()`、`enableStream()`、`disableStream()`、GSI 管理
- CloudWatch 吞吐量监控：`getConsumedCapacity()`

### `DynamoDbItem`

DynamoDB 类型系统与 PHP 原生类型之间的双向转换器，实现 `ArrayAccess` 接口。

支持的属性类型：`S`（String）、`N`（Number）、`B`（Binary）、`BOOL`、`NULL`、`L`（List）、`M`（Map）。

### `SqsQueue`

SQS 队列操作封装，支持：

- 队列生命周期管理：`createQueue()`、`deleteQueue()`、`purge()`
- 消息收发：`sendMessage()`、`sendMessages()`、`receiveMessage()`、`receiveMessages()`
- 批量删除：`deleteMessage()`、`deleteMessages()`
- 属性管理：`getAttribute()`、`getAttributes()`、`setAttributes()`
- 自动序列化/反序列化（`base64_serialize` 标记）

### Contracts（接口）

| 接口 | 说明 |
|------|------|
| `PublisherInterface` | 消息发布抽象（`publish()`） |
| `QueueInterface` | 队列操作抽象（收发、删除、属性） |
| `QueueMessageInterface` | 队列消息抽象（`getBody()`） |
| `CredentialProviderInterface` | 临时凭证提供者抽象（`getTemporaryCredential()`） |

---

## Logging 扩展

`AwsSnsHandler` 是 Monolog 的 `AbstractProcessingHandler` 实现，将日志通过 SNS 发布。支持单条写入和批量写入（`handleBatch()`）。

---

## 认证方式

| 方式 | 配置键 | 说明 |
|------|--------|------|
| AWS Profile | `"profile" => "name"` | 读取 `~/.aws/credentials` |
| 显式凭证 | `"credentials" => [...]` | 直接传入 key/secret |
| 临时凭证 | `"credentials" => TemporaryCredential` | 由 `StsClient` 生成 |
| IAM Role（ECS） | `"iamrole" => true` | 使用 ECS 容器角色，带 Doctrine 文件缓存 |
| 环境变量 | `AWS_ACCESS_KEY_ID` + `AWS_SECRET_ACCESS_KEY` | SDK 默认行为 |

---

## 测试

- 框架：PHPUnit ^5.7
- 测试目录：`ut/`
- 引导文件：`ut/ut-bootstrap.php`
- 配置模板：`ut/tpl.ut.yml`
- 测试套件包含：`DynamoDbManagerTest`、`DynamoDbTableTest`、`DynamoDbItemTest`、`S3ClientTest`、`StsClientTest`、`SqsQueueTest`

---

## 依赖

| 包 | 版本约束 | 用途 |
|----|----------|------|
| `aws/aws-sdk-php` | ^3.22 | AWS SDK 核心 |
| `oasis/logging` | ^1.3 | 日志工具 |
| `oasis/event` | ^1.0 | 事件分发（`SqsQueue` 进度事件） |
| `doctrine/common` | ^2.7 | 缓存适配器（IAM Role 凭证缓存） |
| `phpunit/phpunit` | ^5.7 | 测试（dev） |
| `league/uri` | ^4.2 | URI 处理（dev） |
