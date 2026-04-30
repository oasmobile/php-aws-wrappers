# PHP 8.5 & PHPUnit 13 Upgrade

`docs/proposals/` — 升级 PHP 运行时与测试框架，引入 PBT 和覆盖率考核。

---

## Status

`in-progress`

---

## Background

当前项目运行在较旧的技术栈上：

| 项目 | 当前版本 | 目标版本 |
|------|----------|----------|
| PHP | 7.4（本机当前版本），代码风格为 PHP 5.6/7.x 时代 | 8.5 |
| PHPUnit | ^5.7 | 13 |
| 代码风格 | PHP 5.6/7.x 风格（无类型声明、旧式类名等） | PHP 8.5 现代风格 |
| PBT（Property-Based Testing） | 无 | 引入 |
| UT 覆盖率考核 | 无 | 引入 |

PHPUnit 5.7 → 13 跨越了多个大版本，API 变化显著（类名、生命周期方法签名、断言方法等）。在升级前需要确保现有测试充分覆盖当前行为，作为升级后的回归验证基线。

---

## Problem

1. PHPUnit 5.7 已停止维护多年，无法使用现代测试特性（Attributes、原生 mock 改进等）
2. PHP 8.5 引入了新的语言特性和弃用项，旧依赖可能不兼容
3. 现有 UT 覆盖不完整——以下源文件缺少对应测试：
   - `AwsConfigDataProvider`
   - `DynamoDbIndex`
   - `SnsPublisher`
   - `SqsMessage`
   - `SqsReceivedMessage`
   - `SqsSentMessage`
   - `TemporaryCredential`
   - `AwsSnsHandler`（Logging）
   - `DynamoDb/` 下的 6 个 Command Wrapper 类
4. 现有测试全部为集成测试（依赖真实 AWS 资源），缺少可离线运行的纯单元测试
5. 没有 Property-Based Testing，对类型转换等逻辑的边界覆盖依赖手工枚举
6. 没有覆盖率度量机制，无法量化测试质量
7. 源代码仍使用 PHP 5.6/7.x 风格，缺少类型声明、属性提升等现代特性，可读性和类型安全性不足

---

## Goals

1. 将 PHP 最低版本要求升级到 8.5
2. 将 PHPUnit 升级到 13
3. 升级前补齐现有版本下的 UT，建立回归基线
4. 引入 Property-Based Testing（PBT）库，对适合的逻辑编写 PBT 用例
5. 引入覆盖率收集与考核机制（行覆盖率 / 分支覆盖率阈值）
6. 所有核心依赖（`aws/aws-sdk-php`、`oasis/logging`、`oasis/event`、`doctrine/common`）同步升级到兼容 PHP 8.5 的版本
7. 源代码风格升级到 PHP 8.5 现代风格（类型声明、命名参数、`match` 表达式、属性提升、联合类型、`readonly` 等适用特性）

---

## Non-Goals

- 不重构现有业务逻辑或公共 API
- 不迁移到其他测试框架（如 Pest）
- 不引入 CI/CD 流水线配置（覆盖率考核仅在本地 / 命令行层面实现）
- 不采用尚处于实验性质的语言特性（如 pipe operator 等）

---

## Scope

### 包含

- `composer.json` 依赖版本升级
- `phpunit.xml` 配置迁移到 PHPUnit 13 schema
- 所有现有测试文件的 API 迁移（`\PHPUnit_Framework_TestCase` → `PHPUnit\Framework\TestCase`，`setUp()` 签名加 `void` 返回类型等）
- 补充缺失的纯单元测试（mock AWS SDK，不依赖真实资源）
- 引入 PBT 库及对应测试用例
- 引入覆盖率配置（`phpunit.xml` coverage 配置 + PCOV）
- 源代码风格现代化：类型声明（参数、返回值、属性）、构造器属性提升、`match`、`readonly`、联合类型等适用的 PHP 8.x 特性
- `docs/state/architecture.md` 和 `PROJECT.md` 的版本信息同步更新

### 不包含

- AWS SDK 大版本升级（保持 ^3.x）
- 源代码业务逻辑变更
- CI/CD pipeline 文件

---

## References

- PHPUnit 13 changelog 与迁移指南
- PHP 8.5 新特性与弃用列表
- `docs/state/architecture.md`（当前架构 SSOT）

---

## Risks

- `doctrine/common` ^2.7 可能不兼容 PHP 8.5，该包已被拆分，需评估替代方案（如 `doctrine/cache` 或 `symfony/cache`）
- `league/uri` ^4.2（dev 依赖）可能不兼容 PHP 8.5，需升级到兼容版本
- PHPUnit 5.7 → 13 跨度大，中间可能存在多轮弃用，迁移工作量需在 spec 阶段细化
- 代码风格升级涉及全量源文件修改，需在 UT 基线建立后进行，以确保行为不变

---

## Notes

- 升级路径建议分阶段：先补 UT → 再升 PHPUnit → 再升 PHP → 代码风格升级 → 最后加 PBT 和覆盖率
- 覆盖率收集使用 PCOV 扩展（本机 php74 和 php85 均已安装）
- 本机环境：`php74`（当前版本）、`php`（即 php85）；两个版本均有 PCOV 插件
- 现有测试依赖真实 AWS 资源（DynamoDB 表、SQS 队列、S3 bucket、STS），补充的纯单元测试应使用 mock，与集成测试分离为不同 test suite
