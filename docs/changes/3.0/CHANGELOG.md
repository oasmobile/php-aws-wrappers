# Changelog v3.0

本文件记录 v3.0 release 的变更内容。

---

## 包含的 Feature

### PHP 8.5 & PHPUnit 13 Upgrade（PRP-001）

- PHP 最低版本要求从 7.4 升级到 8.5
- PHPUnit 从 ^5.7 升级到 ^13
- 替换 `doctrine/common` 缓存为 `symfony/cache ^7.0`（IAM Role 凭证缓存）
- 新增 `psr/simple-cache ^3.0` 依赖
- `league/uri` 从 ^4.2 升级到 ^7.0
- 新增 `symfony/yaml ^8.0`（dev 依赖）
- `oasis/logging` 升级到 ^3.0
- `oasis/event` 升级到 ^3.0
- 源代码全面现代化：类型声明（参数、返回值、属性）、构造器属性提升、`match` 表达式、`readonly` 属性、联合类型
- 公共 API 签名保持不变（方法名、参数顺序、行为契约）

---

## 工程变更

### 测试基础设施

- 补齐全部源文件的纯单元测试（mock AWS SDK，可离线运行）
- 现有集成测试迁移到 PHPUnit 13 API，重组为独立 `integration` suite
- 引入 Property-Based Testing（`giorgiosironi/eris ^1.1`），覆盖 DynamoDbItem Codec 的 3 个正确性属性
- 引入覆盖率考核机制（PCOV + `check-coverage.sh`），Unit suite 阈值 80%、Integration suite 阈值 60%
- 测试目录重组：`ut/unit/`（纯单元测试）+ `ut/integration/`（集成测试）

### 配置变更

- `phpunit.xml` 迁移到 PHPUnit 13 schema，双 suite 配置
- `composer.json` 依赖版本全面更新

---

## 测试覆盖

- Unit suite：342 tests, 1620 assertions
- PBT：3 properties × 100+ iterations
- Integration suite：依赖 AWS 凭证，独立运行
