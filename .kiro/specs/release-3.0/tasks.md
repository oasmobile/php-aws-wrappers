# Implementation Plan: Release 3.0 — PHP 8.5 & PHPUnit 13 Upgrade

## Overview

按照 design 中定义的升级路径（Phase 1→5）将 `oasis/aws-wrappers` 从 PHP 7.4 + PHPUnit 5.7 升级到 PHP 8.5 + PHPUnit 13，同时补齐单元测试、引入 PBT、建立覆盖率考核、完成源代码风格现代化并同步文档。

每个 Phase 完成后全量测试必须通过，确保行为等价。Test-first 编排：先写测试（RED），再写实现（GREEN）。

## Tasks

- [x] 1. Phase 1-A: 补齐 DynamoDB 模块纯单元测试（PHPUnit 5.7 API，PHP 7.4 运行）
  - [x] 1.1 创建 `ut/unit/` 目录结构，更新 `phpunit.xml` 添加 unit test suite
    - 创建 `ut/unit/` 和 `ut/unit/DynamoDb/` 目录
    - 在 `phpunit.xml` 中新增 `unit` test suite 指向 `ut/unit/`，保留现有 `basic` suite
    - 更新 `composer.json` autoload-dev 添加 `ut/unit/` 的 PSR-4 映射
    - 验证空 suite 可运行：`php74 vendor/bin/phpunit --testsuite unit`
    - _Requirements: 1.1, 1.3_
  - [x] 1.2 编写 `DynamoDbItem` 纯单元测试
    - 创建 `ut/unit/DynamoDbItemTest.php`，使用 PHPUnit 5.7 API（`\PHPUnit_Framework_TestCase`）
    - 覆盖：`createFromArray`、`createFromTypedArray`、`toArray`、`getData`、`ArrayAccess` 接口、各类型转换（S/N/BOOL/NULL/L/M/B）、边界值（空字符串→NULL、非数字→"0"、嵌套结构）、异常路径（`InvalidDataTypeException`、`RuntimeException`）
    - _Requirements: 1.1, 1.2, 1.4_
  - [x] 1.3 编写 `DynamoDbIndex` 纯单元测试
    - 创建 `ut/unit/DynamoDbIndexTest.php`
    - 覆盖：构造函数、`getName` 自动生成逻辑、`equals` 比较、`getKeySchema`、`getProjection`
    - _Requirements: 1.1, 1.2, 1.4_
  - [x] 1.4 编写 `DynamoDbManager` 纯单元测试（mock `DynamoDbClient`）
    - 创建 `ut/unit/DynamoDbManagerTest.php`
    - Mock `Aws\DynamoDb\DynamoDbClient`，覆盖：`listTables`（含分页 mock）、`createTable`、`deleteTable`、`waitForTableActive`/`waitForTableNotExists`
    - _Requirements: 1.1, 1.2, 1.4_
  - [x] 1.5 编写 `DynamoDbTable` 纯单元测试（mock `DynamoDbClient`）
    - 创建 `ut/unit/DynamoDbTableTest.php`
    - Mock `Aws\DynamoDb\DynamoDbClient`，覆盖：`get`/`set`/`delete`（单项操作）、`batchGet`/`batchPut`/`batchDelete`（批量操作）、`query`/`scan` 委托到 Command Wrapper、`describe`、GSI 管理、Stream 操作
    - _Requirements: 1.1, 1.2, 1.4_
  - [x] 1.6 编写 6 个 DynamoDb Command Wrapper 纯单元测试
    - 创建 `ut/unit/DynamoDb/` 下 6 个测试文件：`MultiQueryCommandWrapperTest.php`、`ParallelScanCommandWrapperTest.php`、`QueryAsyncCommandWrapperTest.php`、`QueryCommandWrapperTest.php`、`ScanAsyncCommandWrapperTest.php`、`ScanCommandWrapperTest.php`
    - 覆盖：参数构建、回调处理、分页/并发逻辑
    - _Requirements: 1.1, 1.2, 1.4_
  - [x] 1.7 Checkpoint: 运行 `php74 vendor/bin/phpunit --testsuite unit` 确认 DynamoDB 模块全部测试通过，commit

- [x] 2. Phase 1-B: 补齐 SQS 模块纯单元测试（PHPUnit 5.7 API，PHP 7.4 运行）
  - [x] 2.1 编写 `SqsQueue` 纯单元测试（mock `SqsClient`）
    - 创建 `ut/unit/SqsQueueTest.php`
    - Mock `Aws\Sqs\SqsClient`，覆盖：`createQueue`、`deleteQueue`、`purge`、`sendMessage`/`sendMessages`（含 `base64_serialize` 序列化路径）、`receiveMessage`/`receiveMessages`、`deleteMessage`/`deleteMessages`、属性管理（`getAttribute`/`getAttributes`/`setAttributes`）、异常路径（`InvalidArgumentException`）
    - _Requirements: 1.1, 1.2, 1.4_
  - [x] 2.2 编写 `SqsMessage` 纯单元测试
    - 创建 `ut/unit/SqsMessageTest.php`
    - 覆盖：构造函数、getter 方法
    - _Requirements: 1.1, 1.2, 1.4_
  - [x] 2.3 编写 `SqsReceivedMessage` 纯单元测试
    - 创建 `ut/unit/SqsReceivedMessageTest.php`
    - 覆盖：MD5 校验逻辑、反序列化（`base64_serialize` / JSON / plain text）、`getAttribute`、异常路径（`UnexpectedValueException`）
    - _Requirements: 1.1, 1.2, 1.4_
  - [x] 2.4 编写 `SqsSentMessage` 纯单元测试
    - 创建 `ut/unit/SqsSentMessageTest.php`
    - 覆盖：构造函数、MD5 字段
    - _Requirements: 1.1, 1.2, 1.4_
  - [x] 2.5 Checkpoint: 运行 `php74 vendor/bin/phpunit --testsuite unit` 确认 SQS 模块全部测试通过，commit

- [x] 3. Phase 1-C: 补齐其他模块纯单元测试（PHPUnit 5.7 API，PHP 7.4 运行）
  - [x] 3.1 编写 `S3Client` 纯单元测试
    - 创建 `ut/unit/S3ClientTest.php`
    - 覆盖：构造函数（endpoint 生成逻辑：`cn-` 前缀 / `us-east-1` / 其他 region）、`getPresignedUri` 路径解析、异常路径（`InvalidArgumentException`）
    - _Requirements: 1.1, 1.2, 1.4_
  - [x] 3.2 编写 `StsClient` 纯单元测试（mock `Aws\Sts\StsClient`）
    - 创建 `ut/unit/StsClientTest.php`
    - 覆盖：构造函数、`getTemporaryCredential` mock 返回值
    - _Requirements: 1.1, 1.2, 1.4_
  - [x] 3.3 编写 `SnsPublisher` 纯单元测试（mock `SnsClient`）
    - 创建 `ut/unit/SnsPublisherTest.php`
    - 覆盖：`publish` 多通道消息结构（Email / SQS / Lambda / APNS / GCM）、`publishToSubscribedSQS` 序列化
    - _Requirements: 1.1, 1.2, 1.4_
  - [x] 3.4 编写 `AwsConfigDataProvider` 纯单元测试
    - 创建 `ut/unit/AwsConfigDataProviderTest.php`
    - 覆盖：region 校验（缺失→`MandatoryValueMissingException`）、凭证处理（profile / credentials 数组 / `TemporaryCredential` / IAM Role / env）、版本设置
    - _Requirements: 1.1, 1.2, 1.4_
  - [x] 3.5 编写 `TemporaryCredential` 纯单元测试
    - 创建 `ut/unit/TemporaryCredentialTest.php`
    - 覆盖：属性赋值与读取
    - _Requirements: 1.1, 1.2, 1.4_
  - [x] 3.6 编写 `AwsSnsHandler` 纯单元测试（mock `SnsPublisher`）
    - 创建 `ut/unit/AwsSnsHandlerTest.php`
    - 覆盖：`write`（单条日志）、`handleBatch`（批量日志）、publisher 交互验证
    - _Requirements: 1.1, 1.2, 1.4_
  - [x] 3.7 Checkpoint: 运行 `php74 vendor/bin/phpunit --testsuite unit` 确认全部单元测试通过（Phase 1 完成），commit

- [x] 4. Phase 2: PHPUnit 13 + PHP 8.5 + 依赖升级（含 symfony/cache 替换）
  - [x] 4.1 编写 `AwsConfigDataProvider` 缓存替换的单元测试
    - 在 `ut/unit/AwsConfigDataProviderTest.php` 中补充 IAM Role 凭证缓存路径的测试用例，验证 `symfony/cache` 替换后的行为
    - 测试应先于实现编写（RED），此时测试预期会失败
    - _Requirements: 5.2_
  - [x] 4.2 更新 `composer.json` 依赖声明
    - `require.php`：添加 `>=8.5`
    - `require.doctrine/common`：移除
    - `require.symfony/cache`：添加 `^7.x`
    - `require-dev.phpunit/phpunit`：从 `^5.7` 改为 `^13`
    - `require-dev.league/uri`：升级到 PHP 8.5 兼容版本
    - `require.oasis/logging` 和 `require.oasis/event`：确认或升级到 PHP 8.5 兼容版本
    - _Requirements: 2.1, 2.2, 4.1, 5.1, 5.2, 5.3, 5.4_
  - [x] 4.3 执行 `composer update` 并解决依赖冲突
    - 使用 `php composer.phar update`（PHP 8.5 运行时）
    - 逐个排查并解决依赖版本冲突
    - 确认 `composer install` 无错误
    - _Requirements: 5.5_
  - [x] 4.4 更新 `phpunit.xml` 为 PHPUnit 13 兼容格式
    - Schema 从 `http://schema.phpunit.de/5.7/phpunit.xsd` 更新为 `vendor/phpunit/phpunit/phpunit.xsd`
    - 移除 `backupGlobals`、`backupStaticAttributes`（PHPUnit 13 已移除）
    - 将现有 `basic` suite 重命名为 `integration`，指向 `ut/integration/` 目录
    - 保留 `unit` suite 指向 `ut/unit/`
    - 添加 `<source><include><directory>src</directory></include></source>` 配置
    - 确保 `--testsuite unit` 可独立运行，不受 integration suite 影响（Req 3 AC3 跳过粒度）
    - _Requirements: 2.2, 3.1, 3.3_
  - [x] 4.5 迁移现有集成测试到 `ut/integration/` 目录
    - 将 `ut/DynamoDbManagerTest.php`、`DynamoDbTableTest.php`、`DynamoDbItemTest.php`、`S3ClientTest.php`、`StsClientTest.php`、`SqsQueueTest.php` 移动到 `ut/integration/`
    - 重命名为 `*IntegrationTest.php`（如 `DynamoDbManagerIntegrationTest.php`）
    - 更新类名和命名空间
    - 确保集成测试支持 AWS profile 配置（不限 default），凭证解析逻辑保留在 bootstrap 或测试基类中
    - _Requirements: 3.1, 3.2, 3.4_
  - [x] 4.6 将全部测试代码迁移到 PHPUnit 13 API，同时更新 `ut/ut-bootstrap.php`
    - 基类：`\PHPUnit_Framework_TestCase` → `PHPUnit\Framework\TestCase`
    - 生命周期方法签名：`function setUp()` → `protected function setUp(): void`，`tearDown` 同理
    - Mock 创建：`$this->getMock()` → `$this->createMock()`
    - 断言：`assertContains`（string 场景）→ `assertStringContainsString`
    - 注解：`@test` → `#[Test]`，`@depends` → `#[Depends]`（或保留 docblock，确保无 deprecation warning）
    - 适用于 `ut/unit/` 和 `ut/integration/` 下全部测试文件
    - 检查并修复 `ut/ut-bootstrap.php` 中的 deprecation 用法
    - _Requirements: 2.3, 2.4, 3.2, 3.5_
  - [x] 4.7 替换 `AwsConfigDataProvider` 中的 `doctrine/common` 缓存为 `symfony/cache`
    - 将 `DoctrineCacheAdapter` + `FilesystemCache` 替换为 `Psr16CacheAdapter` + `Psr16Cache` + `FilesystemAdapter`
    - 按 design 中的目标实现代码修改
    - 运行 4.1 中编写的测试确认通过（GREEN）
    - _Requirements: 5.2_
  - [x] 4.8 修复 PHP 8.5 deprecation warnings
    - 运行全量测试，检查并修复源代码中的 PHP 8.5 弃用用法
    - 确保零 deprecation warning
    - _Requirements: 4.2, 4.3, 4.4_
  - [x] 4.9 Checkpoint: 使用 PHP 8.5 运行 `php vendor/bin/phpunit --testsuite unit` 确认全部单元测试通过（Req 2 AC4, Req 4 AC3）；如有 AWS 凭证可运行 `php vendor/bin/phpunit --testsuite integration` 验证（Req 3 AC5, Req 4 AC4），commit

- [ ] 5. Phase 3: 源代码风格现代化
  - [ ] 5.1 编写风格现代化的回归测试基线
    - 在现有单元测试中确认所有测试通过，作为行为等价的基线
    - 记录当前测试数量和通过状态
    - _Requirements: 6.9_
  - [ ] 5.2 对 `src/` 下全部 24 个 PHP 文件进行现代化改造
    - 参数类型声明：公共 API 参数类型不确定时可用 `mixed`，内部方法使用精确类型（CR Q2→C）
    - 返回类型声明：所有方法添加返回类型
    - 属性类型声明：所有属性添加类型
    - 构造器属性提升：简单赋值场景使用（如 `TemporaryCredential`、`DynamoDbIndex`）
    - `match` 表达式：替换简单 `switch`（如 `DynamoDbItem::toUntypedValue`、`SnsPublisher::publish`）
    - `readonly`：构造后不再变更的属性（如 `SqsQueue::$name`、`DynamoDbTable::$tableName`）
    - 联合类型：如 `int|float`、`string|null`
    - 保持公共 API 签名不变（方法名、参数顺序、行为契约）
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8_
  - [ ] 5.3 Checkpoint: 运行 `php vendor/bin/phpunit --testsuite unit` 确认全部单元测试通过（行为等价验证），commit

- [ ] 6. Phase 4: PBT 引入 + 覆盖率考核机制
  - [ ] 6.1 添加 Eris PBT 库依赖
    - `composer require --dev giorgiosironi/eris`
    - 确认安装成功
    - _Requirements: 7.1_
  - [ ] 6.2 编写 Property 1: Codec Round-Trip PBT 测试
    - 创建 `ut/unit/Pbt/DynamoDbItemCodecPbtTest.php`
    - 使用 `Eris\TestTrait`，实现 `testRoundTrip`：对任意有效 PHP 值，`createFromArray` → `toArray` 结果等价于原始输入
    - 自定义递归生成器：string（排除空字符串）、int、float、bool、null、sequential array、associative array，嵌套深度限制 3 层
    - 最低 100 次迭代
    - 标注：`Feature: release-3.0, Property 1: Codec Round-Trip`
    - **Property 1: Codec Round-Trip（untyped → typed → untyped）**
    - **Validates: Requirements 7.2**
    - _Requirements: 7.2, 7.5_
  - [ ] 6.3 编写 Property 2: Typed Codec Round-Trip PBT 测试
    - 在 `DynamoDbItemCodecPbtTest.php` 中添加 `testTypedRoundTrip`：对任意有效 DynamoDB typed array，`createFromTypedArray` → `getData` 结果等价于原始输入
    - 自定义 typed array 生成器（S/N/BOOL/NULL/L/M 类型标记）
    - 最低 100 次迭代
    - 标注：`Feature: release-3.0, Property 2: Typed Codec Round-Trip`
    - **Property 2: Typed Codec Round-Trip（typed → item → typed）**
    - **Validates: Requirements 7.3**
    - _Requirements: 7.3, 7.5_
  - [ ] 6.4 编写 Property 3: Codec Idempotence PBT 测试
    - 在 `DynamoDbItemCodecPbtTest.php` 中添加 `testIdempotence`：对任意有效 PHP 值，`f(f(x)) == f(x)` 其中 `f = toArray ∘ createFromArray`
    - 复用 6.2 的生成器
    - 最低 100 次迭代
    - 标注：`Feature: release-3.0, Property 3: Codec Idempotence`
    - **Property 3: Codec Idempotence**
    - **Validates: Requirements 7.4**
    - _Requirements: 7.4, 7.5_
  - [ ] 6.5 配置覆盖率收集与阈值检查
    - 确认 `phpunit.xml` 中 `<source>` 配置正确（已在 4.4 完成）
    - 创建 `check-coverage.sh` 脚本：解析 `--coverage-text` 输出中的 `Lines:` 百分比，低于阈值则 `exit 1`
    - Unit suite 阈值 80%，Integration suite 阈值 60%
    - 验证命令：`php -dpcov.enabled=1 vendor/bin/phpunit --testsuite unit --coverage-text | ./check-coverage.sh 80`
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5_
  - [ ] 6.6 Checkpoint: 运行 PBT 测试和覆盖率检查确认全部通过，commit

- [ ] 7. Phase 5: 文档同步更新
  - [ ] 7.1 更新 `docs/state/architecture.md`
    - 测试 section：PHPUnit ^5.7 → 13，测试目录 `ut/` → `ut/unit/` + `ut/integration/`，新增 PBT 和覆盖率机制描述
    - 依赖 section：`doctrine/common ^2.7` → `symfony/cache ^7.x`，`phpunit/phpunit ^5.7` → `^13`，`league/uri` 版本更新，新增 `giorgiosironi/eris`
    - 认证方式 section：IAM Role 缓存从 Doctrine 切换到 symfony/cache
    - _Requirements: 9.1_
  - [ ] 7.2 更新 `PROJECT.md`
    - 技术栈表：PHP 版本、PHPUnit 版本、核心依赖版本
    - 构建与测试命令：新增 `--testsuite unit` / `--testsuite integration`、覆盖率命令、PBT 说明
    - 目录结构：反映 `ut/unit/` + `ut/integration/` 双目录
    - _Requirements: 9.2_
  - [ ] 7.3 Checkpoint: 确认文档内容与实际代码/配置一致，commit

- [ ] 8. 手工测试
  - [ ] 8.1 Increment alpha tag
    - 查询已有 alpha tag（`git tag -l 'v3.0.0-alpha.*'`），取最大序号 +1，打新 tag
  - [ ] 8.2 验证全量测试在 PHP 8.5 下通过
    - 运行 `php vendor/bin/phpunit` 确认 unit + integration 全部通过（integration 需 AWS 凭证）
    - 确认零 deprecation warning、零 failure
  - [ ] 8.3 验证覆盖率阈值
    - 运行 `php -dpcov.enabled=1 vendor/bin/phpunit --testsuite unit --coverage-text` 并通过 `check-coverage.sh 80`
    - 运行 `php -dpcov.enabled=1 vendor/bin/phpunit --testsuite integration --coverage-text` 并通过 `check-coverage.sh 60`（需 AWS 凭证）
  - [ ] 8.4 验证 PBT 测试
    - 运行 PBT 测试确认 3 个 property 全部通过，每个 property ≥100 次迭代
  - [ ] 8.5 验证 `composer install` 干净安装
    - 删除 `vendor/` 后重新 `composer install`，确认无错误、无 warning
  - [ ] 8.6 验证公共 API 兼容性
    - 检查 `src/` 下所有公共方法签名未发生语义变更（方法名、参数顺序、行为契约不变）

- [ ] 9. Code Review
  - [ ] 9.1 委托给 code-reviewer sub-agent，基于当前分支的 diff 执行全量 code review

## Notes

- 执行时须遵循 `spec-execution.md` 中的规范
- **Commit 时机**：commit 随各 top-level task 的 Checkpoint sub-task 一起执行，不在 Checkpoint 之外单独 commit
- **PHP 运行时切换**：Phase 1 使用 `php74` 命令（PHP 7.4），Phase 2 起切换到 `php` 命令（PHP 8.5）——PHPUnit 13 要求 PHP ≥ 8.2，因此 PHPUnit 升级和 PHP 升级必须同步完成
- **PCOV 扩展**：`php74` 和 `php` 均已安装 PCOV，覆盖率收集使用 `-dpcov.enabled=1` 参数
- **集成测试**：需要 AWS 凭证才能运行，支持 profile 配置（不限 default）。无凭证时通过 `--testsuite unit` 跳过整个 integration suite
- **Test-first 编排**：Phase 1 先写测试再验证现有代码行为；Phase 2 中 symfony/cache 替换先写测试（4.1）再改实现（4.7）
- **升级路径约束**：严格按 Phase 1→2→3→4→5 顺序执行，每个 Phase 完成后全量测试必须通过。Phase 2 同时完成 PHPUnit 13 升级、PHP 8.5 升级和依赖升级（因 PHPUnit 13 要求 PHP ≥ 8.2，无法分步）
- **Composer 命令**：Phase 1 使用 `php74 composer.phar`（或系统 composer），Phase 2 起使用 `php composer.phar`（PHP 8.5）

## Socratic Review

### Q: CR 决策是否全部在 task 编排中体现？

A: 已逐一检查：
- **Design CR Q1→B**：Phase 1 按功能模块分 3 组（Task 1 DynamoDB、Task 2 SQS、Task 3 其他）✓
- **Design CR Q2→A**：Phase 2 合并为一个 task（Task 4），PHPUnit 13 + PHP 8.5 + 依赖升级同步完成（因 PHPUnit 13 要求 PHP ≥ 8.2），symfony/cache 替换包含在 Task 4 中 ✓
- **Design CR Q3→A**：Phase 3 整体一个 task（Task 5）✓
- **Design CR Q4→A**：PBT + 覆盖率合并为一个 task（Task 6）✓
- **Requirements CR Q1→A**：所有源文件都有 mock 版本单元测试 → Task 1-3 覆盖全部 20 个可测试源文件 ✓
- **Requirements CR Q2→C**：mixed 按场景判断 → Task 5.2 明确说明 ✓
- **Requirements CR Q3→A**：整个 suite 级别跳过 → Notes 中说明 `--testsuite unit` 跳过方式 ✓
- **Requirements CR Q4→C**：覆盖率执行方式灵活 → Task 6.5 和 8.3 中体现 ✓

### Q: Test-first 编排是否贯穿？

A: 是。Phase 1（Task 1-3）本身就是先写测试建立回归基线。Phase 2 中 symfony/cache 替换采用 RED→GREEN：先写测试（4.1）再改实现（4.7）。Phase 3 先确认基线（5.1）再改代码（5.2）。Phase 4 PBT 测试本身就是测试编写。

### Q: Requirements 覆盖是否完整？

A: 已检查全部 9 条 Requirement：
- Req 1（补 UT）→ Task 1-3（Phase 1 三组）
- Req 2（PHPUnit 13）→ Task 4.2, 4.4, 4.6
- Req 3（集成测试迁移）→ Task 4.4, 4.5, 4.6
- Req 4（PHP 8.5）→ Task 4.2, 4.8
- Req 5（依赖升级）→ Task 4.1-4.3, 4.7
- Req 6（代码风格）→ Task 5
- Req 7（PBT）→ Task 6.1-6.4
- Req 8（覆盖率）→ Task 6.5
- Req 9（文档更新）→ Task 7

### Q: Checkpoint 编排是否符合规范？

A: 是。每个 top-level task 的最后一个 sub-task 都是 Checkpoint，包含验证步骤和 commit 动作。没有独立的 top-level checkpoint task。

### Q: Release top-level task 结构是否正确？

A: 是。Task 1-7 为实现 task，Task 8 为手工测试（第一个 sub-task 8.1 为 "Increment alpha tag"），Task 9 为 Code Review。符合 Release 分支的 top-level task 结构要求。

### Q: 是否存在悬空或孤立的代码？

A: 不存在。每个 task 都建立在前一个 task 的基础上：Phase 1 建立测试基线 → Phase 2 升级 PHPUnit + PHP + 依赖 → Phase 3 现代化源代码 → Phase 4 增强测试基础设施 → Phase 5 同步文档。每个 Phase 的 Checkpoint 确保增量验证。

### Q: 是否包含了不应由编码 agent 执行的 task？

A: 没有。所有 task 都涉及写代码、改配置、运行测试或编辑文档。没有部署、用户验收、性能分析等非编码 task。手工测试 task（Task 9）中的验证步骤都是可通过命令行执行的自动化验证。


## Gatekeep Log

**校验时间**: 2025-07-15
**校验结果**: ⚠️ 已修正后通过

### 修正项

- [内容] Requirement 追溯补全：Task 4.4 补充 Req 3 AC3（集成测试跳过粒度）引用；Task 4.5 补充 Req 3 AC3.4（profile 支持）引用及描述；Task 4.8 补充 Req 4 AC4.3/4.4（PHP 8.5 下测试通过）引用
- [内容] Code Review task 9.1 移除展开的 review checklist，改为委托给 code-reviewer sub-agent 执行（review 策略由 agent 自身定义）
- [内容] Notes section 补充 "Commit 时机" 说明：commit 随 Checkpoint sub-task 一起执行，不在 Checkpoint 之外单独 commit
- [结构] 合并原 Task 4（Phase 2: PHPUnit 迁移）和 Task 5（Phase 3: PHP 8.5 + 依赖升级）为新 Task 4——PHPUnit 13 要求 PHP ≥ 8.2，两者无法分步执行；后续 task 重新编号（原 6→5, 7→6, 8→7, 9→8, 10→9）

### 合规检查

- [x] 无 TBD / TODO / 待定 / 占位符
- [x] 无空 section 或不完整列表
- [x] 内部引用一致（requirement 编号、design 模块名）
- [x] checkbox 语法正确（`- [ ]`）
- [x] 无 markdown 格式错误
- [x] `## Tasks` section 存在
- [x] Release spec 手工测试 top-level task（Task 8）第一个 sub-task 是 "Increment alpha tag"
- [x] 最后一个 top-level task（Task 9）是 Code Review
- [x] 实现 task（1-7）排在手工测试和 Code Review 之前
- [x] 所有 task 使用 checkbox 语法，序号连续无跳号
- [x] 每个实现类 sub-task 引用了 requirements 条款
- [x] requirements.md 全部 9 条 requirement 的所有 AC 均被至少一个 task 引用（无遗漏）
- [x] 引用的 requirement 编号在 requirements.md 中确实存在（无悬空引用）
- [x] top-level task 按依赖关系排序（Phase 1→2→3→4→5→手工测试→Code Review），无循环依赖
- [x] Checkpoint 不作为独立 top-level task，而是每个 top-level task 的最后一个 sub-task
- [x] 每个 Checkpoint 包含具体验证命令和 commit 动作
- [x] Test-first 编排贯穿：Phase 1 先写测试建立基线；Phase 2 RED→GREEN（4.1→4.7）；Phase 3 先确认基线（5.1）再改代码（5.2）
- [x] 每个 sub-task 足够具体，可独立执行；无过粗或过细的 task；无 optional task
- [x] 手工测试 top-level task 存在，覆盖关键用户场景（全量测试、覆盖率、PBT、composer install、API 兼容性）
- [x] Code Review 是最后一个 top-level task，描述为委托给 code-reviewer sub-agent
- [x] `## Notes` section 存在，引用 `spec-execution.md`，说明 commit 时机，包含 spec 特有执行要点
- [x] `## Socratic Review` 存在且覆盖充分（CR 决策、test-first、requirements 覆盖、checkpoint、结构、依赖、非编码 task）
- [x] Design CR 决策全部体现（Q1→B 三组、Q2→A 合并为一个 task（PHPUnit 13 要求 PHP ≥ 8.2）、Q3→A 整体一个 task、Q4→A 合并一个 task）
- [x] Requirements CR 决策全部体现（Q1→A 全量 mock UT、Q2→C mixed 按场景、Q3→A suite 级别跳过、Q4→C 灵活执行）
- [x] Design 全覆盖：全部 5 个 Phase 均有对应 task（原 Phase 2-3 因 PHPUnit 13 的 PHP 版本要求合并为 Phase 2），所有模块和接口均被覆盖
- [x] 验收闭环完整：Checkpoint + 手工测试 + Code Review
- [x] 执行路径无歧义：Phase 顺序明确，无隐含依赖
- [○] Graphify 跨模块依赖校验（graphify_ready = false，GRAPH_REPORT.md 不存在，跳过）
