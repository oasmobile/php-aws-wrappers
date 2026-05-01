# Spec Goal: Release 3.0 — PHP 8.5 & PHPUnit 13 Upgrade

## 来源

- 分支: `release/3.0`
- 需求文档: `docs/proposals/PRP-001-php85-phpunit13-upgrade.md`

## 背景摘要

`oasis/aws-wrappers` 当前运行在 PHP 7.4 + PHPUnit 5.7 技术栈上，代码风格停留在 PHP 5.6/7.x 时代（无类型声明、旧式类名等）。PHPUnit 5.7 已停止维护多年，无法使用现代测试特性。

现有测试全部为集成测试，依赖真实 AWS 资源（DynamoDB、SQS、S3、STS），缺少可离线运行的纯单元测试。多个源文件完全没有对应测试覆盖。项目没有 Property-Based Testing，也没有覆盖率度量机制。

核心依赖中 `doctrine/common` ^2.7 已被拆分，可能不兼容 PHP 8.5，需要评估替代方案。

## 目标

- 将 PHP 最低版本要求升级到 8.5
- 将 PHPUnit 升级到 13
- 补齐缺失的纯单元测试（mock AWS SDK），建立回归基线
- 迁移现有集成测试到 PHPUnit 13 API，作为独立 test suite 保留
- 引入 Property-Based Testing（PBT）库，对适合的逻辑编写 PBT 用例
- 引入覆盖率收集与考核机制（两个 suite 各自独立计算覆盖率）
- 所有核心依赖同步升级到兼容 PHP 8.5 的版本
- 源代码风格升级到 PHP 8.5 现代风格（类型声明、构造器属性提升、`match`、`readonly`、联合类型等）
- 同步更新 `docs/state/architecture.md` 和 `PROJECT.md`

## 不做的事情（Non-Goals）

- 不重构现有业务逻辑或公共 API
- 不迁移到其他测试框架（如 Pest）
- 不引入 CI/CD 流水线配置
- 不采用尚处于实验性质的语言特性（如 pipe operator）
- AWS SDK 不做大版本升级（保持 ^3.x）

## Clarification 记录

### Q1: Release 3.0 的范围确认

- 选项: A) 全部完成 / B) 分阶段 / C) 补充说明
- 回答: A — release 3.0 包含 PRP-001 的所有 goals

### Q2: 现有集成测试的处理方式

- 选项: A) 保留并迁移 / B) 保留但不迁移 / C) 移除 / D) 补充说明
- 回答: D — 要迁移，集成测试作为独立 test suite，执行时需要 AWS 凭证（支持 AWS 本地 profile，不限于 default）

### Q3: `doctrine/common` 的替代策略

- 选项: A) `symfony/cache` / B) `doctrine/cache` / C) PHP 原生实现 / D) 补充说明
- 回答: A/B 皆可，具体选型在 design 阶段确定

### Q4: 覆盖率阈值与考核范围

- 选项: A) 只考核纯单元测试 suite / B) 合并计算 / C) 只收集不设阈值 / D) 补充说明
- 回答: A（补充）— 两个 suite 各自独立计算覆盖率，阈值在 design 阶段根据实际情况确定

## 约束与决策

- 全量 scope：本次 release 一次性完成 PRP-001 所有 goals
- 双 test suite：纯单元测试（mock，可离线运行）+ 集成测试（需 AWS 凭证，支持 profile 配置，不限 default）
- 覆盖率两个 suite 各自独立计算，阈值 design 阶段定
- `doctrine/common` 替代方案（`symfony/cache` 或 `doctrine/cache`）在 design 阶段选型
- 升级路径遵循 PRP-001 建议：补 UT → 升 PHPUnit → 升 PHP → 代码风格升级 → PBT 和覆盖率
- 本机环境：`php74`（当前）、`php`（即 php85），两者均有 PCOV 扩展
