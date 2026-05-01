# Requirements Document

## Introduction

本文档定义 `oasis/aws-wrappers` Release 3.0 的需求，涵盖 PHP 8.5 升级、PHPUnit 13 迁移、单元测试补齐、集成测试迁移、Property-Based Testing 引入、覆盖率考核机制建立、依赖升级及源代码风格现代化。

来源：`docs/proposals/PRP-001-php85-phpunit13-upgrade.md`

**不涉及的内容**：不重构现有业务逻辑或公共 API；不迁移到其他测试框架（如 Pest）；不引入 CI/CD 流水线配置；不采用实验性语言特性（如 pipe operator）；AWS SDK 不做大版本升级（保持 ^3.x）。

---

## Glossary

- **Library**: `oasis/aws-wrappers` Composer 库
- **Unit_Test_Suite**: 纯单元测试套件，使用 mock，可离线运行，不依赖真实 AWS 资源
- **Integration_Test_Suite**: 集成测试套件，依赖真实 AWS 资源，需要 AWS 凭证（支持 profile 配置，不限 default）
- **PBT**: Property-Based Testing，基于属性的自动化测试方法
- **PCOV**: PHP 代码覆盖率扩展
- **Coverage_Threshold**: 覆盖率阈值，两个 suite 各自独立计算
- **Source_Code**: `src/` 目录下的所有 PHP 源文件
- **PHPUnit_13_API**: PHPUnit 13 的测试 API（`PHPUnit\Framework\TestCase`、Attributes、`void` 返回类型等）
- **Modern_PHP_Style**: PHP 8.5 现代代码风格（类型声明、构造器属性提升、`match`、`readonly`、联合类型等）
- **DynamoDbItem_Codec**: `DynamoDbItem` 类中 PHP 原生类型与 DynamoDB 类型系统之间的双向转换逻辑

---

## Requirements

### Requirement 1: 补齐纯单元测试

**User Story:** 作为开发者，我希望所有源文件都有对应的可离线运行的单元测试，以便在升级前建立回归基线。

#### Acceptance Criteria

1. THE Unit_Test_Suite SHALL contain test classes covering ALL Source_Code files, including but not limited to: `AwsConfigDataProvider`, `DynamoDbIndex`, `DynamoDbItem`, `DynamoDbManager`, `DynamoDbTable`, `S3Client`, `SnsPublisher`, `SqsQueue`, `SqsMessage`, `SqsReceivedMessage`, `SqsSentMessage`, `StsClient`, `TemporaryCredential`, `AwsSnsHandler`, and all 6 DynamoDb Command Wrapper classes
2. WHEN a unit test exercises AWS SDK interactions, THE Unit_Test_Suite SHALL use mock objects instead of real AWS service calls
3. THE Unit_Test_Suite SHALL be executable without network access or AWS credentials
4. THE Unit_Test_Suite SHALL be written using PHPUnit 5.7 API initially, to serve as a regression baseline before PHPUnit upgrade

---

### Requirement 2: PHPUnit 升级到 13

**User Story:** 作为开发者，我希望将 PHPUnit 升级到版本 13，以便使用现代测试特性并获得长期支持。

#### Acceptance Criteria

1. WHEN the PHPUnit upgrade is complete, THE Library SHALL declare `phpunit/phpunit ^13` in `composer.json` require-dev
2. WHEN the PHPUnit upgrade is complete, THE Library SHALL use `phpunit.xml` schema compatible with PHPUnit 13
3. THE Unit_Test_Suite SHALL use PHPUnit_13_API (namespace classes, `void` return types on lifecycle methods, modern assertions)
4. THE Unit_Test_Suite SHALL pass all tests under PHPUnit 13 with zero failures and zero deprecation warnings

---

### Requirement 3: 集成测试迁移

**User Story:** 作为开发者，我希望现有集成测试迁移到 PHPUnit 13 API 并组织为独立套件，以便在 AWS 凭证可用时独立运行。

#### Acceptance Criteria

1. THE Integration_Test_Suite SHALL be configured as a separate test suite in `phpunit.xml`
2. THE Integration_Test_Suite SHALL use PHPUnit_13_API
3. WHEN AWS credentials are not available, THE Integration_Test_Suite SHALL be skippable without affecting Unit_Test_Suite execution
4. THE Integration_Test_Suite SHALL support AWS credential resolution via local profile configuration (not limited to `default` profile)
5. THE Integration_Test_Suite SHALL pass all migrated tests with zero failures when valid AWS credentials are provided

---

### Requirement 4: PHP 版本升级到 8.5

**User Story:** 作为开发者，我希望将 PHP 最低版本要求提升到 8.5，以便利用现代语言特性并保持与当前 PHP 版本的兼容性。

#### Acceptance Criteria

1. WHEN the PHP upgrade is complete, THE Library SHALL declare `php >=8.5` in `composer.json` require
2. THE Source_Code SHALL be free of PHP 8.5 deprecation warnings
3. THE Unit_Test_Suite SHALL pass under PHP 8.5 runtime
4. THE Integration_Test_Suite SHALL pass under PHP 8.5 runtime

---

### Requirement 5: 核心依赖升级

**User Story:** 作为开发者，我希望所有核心依赖升级到 PHP 8.5 兼容版本，以便库在运行时不出现兼容性问题。

#### Acceptance Criteria

1. THE Library SHALL declare `aws/aws-sdk-php ^3.x` (latest compatible minor) in `composer.json`
2. THE Library SHALL replace `doctrine/common ^2.7` with a PHP 8.5 compatible cache solution (`symfony/cache` or `doctrine/cache`, specific choice deferred to design phase)
3. THE Library SHALL declare `oasis/logging` and `oasis/event` at PHP 8.5 compatible versions in `composer.json`
4. THE Library SHALL declare `league/uri` at a PHP 8.5 compatible version in `composer.json` require-dev
5. WHEN all dependencies are upgraded, THE Library SHALL resolve `composer install` without errors under PHP 8.5

---

### Requirement 6: 源代码风格现代化

**User Story:** 作为开发者，我希望源代码现代化为 PHP 8.5 风格，以便代码库更具可读性、类型安全性和可维护性。

#### Acceptance Criteria

1. THE Source_Code SHALL adopt Modern_PHP_Style: typed parameter declarations on all method parameters where the type is deterministic
2. THE Source_Code SHALL use typed return declarations on all methods where the return type is deterministic
3. THE Source_Code SHALL use typed property declarations where applicable
4. THE Source_Code SHALL use constructor property promotion where it simplifies the code
5. THE Source_Code SHALL use `match` expressions in place of simple `switch` statements where applicable
6. THE Source_Code SHALL use `readonly` properties where a property is assigned once and never mutated
7. THE Source_Code SHALL use union types where a parameter or return value accepts multiple types
8. THE Source_Code SHALL preserve all existing public API signatures (method names, parameter order, and behavioral contracts)
9. WHEN the Modern_PHP_Style upgrade is complete, THE Unit_Test_Suite SHALL pass with zero failures (behavioral equivalence)

---

### Requirement 7: Property-Based Testing 引入

**User Story:** 作为开发者，我希望为类型转换逻辑引入 Property-Based Testing，以便自动发现边界情况而非依赖手工枚举。

#### Acceptance Criteria

1. THE Library SHALL include a PBT library as a dev dependency in `composer.json`
2. THE Unit_Test_Suite SHALL contain PBT test cases for DynamoDbItem_Codec round-trip property: for all valid PHP values, encoding then decoding SHALL produce a value equivalent to the input
3. THE Unit_Test_Suite SHALL contain PBT test cases for DynamoDbItem_Codec typed-round-trip property: for all valid typed arrays, encoding via typed interface then retrieving data SHALL produce a value equivalent to the input
4. THE Unit_Test_Suite SHALL contain PBT test cases verifying DynamoDbItem_Codec idempotence: applying type conversion twice SHALL produce the same result as applying once
5. WHEN PBT tests are executed, THE Unit_Test_Suite SHALL run a minimum of 100 iterations per property

---

### Requirement 8: 覆盖率收集与考核

**User Story:** 作为开发者，我希望建立代码覆盖率收集与阈值考核机制，以便测试质量可量化且覆盖率回退能被及时发现。

#### Acceptance Criteria

1. THE Library SHALL configure PCOV as the coverage driver in `phpunit.xml`
2. THE Unit_Test_Suite SHALL have an independently measured line coverage percentage
3. THE Integration_Test_Suite SHALL have an independently measured line coverage percentage
4. THE Library SHALL enforce Coverage_Threshold for each suite (specific values deferred to design phase)
5. WHEN coverage falls below the configured Coverage_Threshold, THE Library SHALL report a failure via command-line exit code

---

### Requirement 9: 文档同步更新

**User Story:** 作为开发者，我希望架构和项目文档更新以反映新技术栈，以便文档始终作为唯一事实来源。

#### Acceptance Criteria

1. WHEN all upgrades are complete, THE Library SHALL update `docs/state/architecture.md` to reflect: PHP 8.5, PHPUnit 13, new dependency versions, dual test suite structure, PBT, and coverage mechanism
2. WHEN all upgrades are complete, THE Library SHALL update `PROJECT.md` to reflect: new PHP version, new PHPUnit version, new dependency versions, and updated build/test commands


---

## Socratic Review

### Q: 升级路径是否在 requirements 中体现？

A: 升级路径（补 UT → 升 PHPUnit → 升 PHP → 代码风格 → PBT 和覆盖率）是实施顺序约束，属于 design/tasks 层面的编排决策，不属于 requirements 的 "what"。Requirements 只定义每个阶段的验收标准，顺序依赖关系在 design 阶段体现。

### Q: `doctrine/common` 替代方案是否应在 requirements 中确定？

A: 不应。goal.md 明确说明 "具体选型在 design 阶段确定"。Requirement 5 AC2 已表述为 "替换为 PHP 8.5 兼容的缓存方案"，将选型决策留给 design。

### Q: 覆盖率阈值是否应在 requirements 中定义具体数值？

A: 不应。goal.md 明确说明 "阈值在 design 阶段根据实际情况确定"。Requirement 8 AC4 已将具体值 deferred to design phase。

### Q: PBT 的 round-trip property 是否覆盖了 DynamoDbItem 的核心逻辑？

A: 是。`DynamoDbItem` 的核心是 PHP 原生类型 ↔ DynamoDB 类型系统的双向转换。Round-trip property 和 typed-round-trip 覆盖了两个方向的正确性。Idempotence property 进一步验证转换的稳定性。

### Q: 是否遗漏了 Non-Goals 中的约束？

A: 已检查。Requirement 6 AC8 明确要求 "preserve all existing public API signatures"（不重构业务逻辑）。Requirements 不涉及 Pest、CI/CD、实验性特性或 AWS SDK 大版本升级。Introduction 已补充 Non-scope 说明。

### Q: 集成测试的 profile 支持是否充分体现？

A: 是。Requirement 3 AC4 明确要求 "support AWS credential resolution via local profile configuration (not limited to default profile)"，直接对应 goal.md Q2 的回答。

### Q: 各 requirement 之间是否存在矛盾或重叠？

A: 无矛盾。Req 1（补 UT 用 PHPUnit 5.7）与 Req 2（升级到 PHPUnit 13）存在时序依赖但不矛盾——Req 1 明确 "initially" 使用 5.7 API。Req 4（PHP 8.5）与 Req 5（依赖升级）互为前提但各自聚焦不同维度（运行时 vs 依赖声明）。Req 6（代码风格）与 Req 4（PHP 版本）有关联但 Req 6 聚焦代码层面的现代化，Req 4 聚焦运行时兼容性。

### Q: 是否有隐含的前置假设未显式列出？

A: 有两个隐含假设：(1) 本机同时具备 PHP 7.4 和 PHP 8.5 运行时——goal.md 已记录（`php74` 和 `php` 命令），但 requirements 未显式声明；(2) 现有集成测试在迁移前是通过的——Req 1 以此为前提建立回归基线。这两个假设属于环境约束，不影响 requirements 的正确性，无需修正。

### Q: 是否有遗漏的错误路径或边界条件？

A: Req 3 AC3 覆盖了"无 AWS 凭证时可跳过"的场景。Req 8 AC5 覆盖了"覆盖率低于阈值时报告失败"。PBT（Req 7）本身就是边界条件发现机制。对于依赖升级（Req 5），`composer install` 失败的场景由 AC5 隐式覆盖。整体边界覆盖充分。

---

## Gatekeep Log

**校验时间**: 2025-07-15
**校验结果**: ⚠️ 已修正后通过

### 修正项

- [语体] 全部 9 条 User Story 从英文改为中文行文（`作为 <角色>，我希望 <能力>，以便 <价值>`）
- [内容] Introduction 补充 Non-scope 说明（对应 goal.md Non-Goals）
- [内容] Requirement 7 AC2-4 移除具体方法名（`createFromArray`、`toArray` 等），改为基于 DynamoDbItem_Codec 术语的行为描述
- [术语] Requirement 8 AC4-5 中 "coverage thresholds" / "threshold" 改为 Glossary 术语 `Coverage_Threshold`
- [术语] Requirement 6 AC1、AC9 引用 Glossary 术语 `Modern_PHP_Style`，消除孤立术语
- [格式] Requirement 1→2、Requirement 5→6 之间补充 `---` 分隔符，统一各 section 间分隔风格
- [内容] Socratic Review 补充三个维度：requirement 间矛盾/重叠检查、隐含前置假设、错误路径/边界条件
- [内容] Socratic Review Q4 中移除具体方法名引用，与 AC 修正保持一致

### 合规检查

- [x] 无 TBD / TODO / 待定 / 占位符
- [x] 无空 section 或不完整列表
- [x] 内部引用一致（术语表术语在 AC 中使用，AC 编号连续）
- [x] 无 markdown 格式错误
- [x] 一级标题 `# Requirements Document` 存在
- [x] Introduction 存在，描述了 feature 范围，明确了 Non-scope
- [x] Glossary 存在且非空，所有术语在正文中被引用
- [x] Requirements section 包含 9 条 requirement，各含 User Story + AC
- [x] 各 section 之间使用 `---` 分隔
- [x] AC 使用 `THE <Subject> SHALL` / `WHEN ... SHALL` 语体
- [x] AC Subject 使用 Glossary 术语
- [x] User Story 使用中文行文
- [x] AC 不含实现细节（具体方法名已移除）
- [x] Socratic Review 覆盖充分（scope 边界、矛盾检查、隐含假设、边界条件）
- [x] Goal CR 决策已体现（全量 scope、双 suite、profile 支持、覆盖率独立计算、选型 deferred）
- [○] Graphify 校验（graphify_ready = false，GRAPH_REPORT.md 不存在，跳过）

### Clarification Round

**状态**: 已完成

**Q1:** Requirement 1 AC1 列出了 14 个需要补齐单元测试的源文件。现有的 6 个集成测试类（`DynamoDbManagerTest`、`DynamoDbTableTest`、`DynamoDbItemTest`、`S3ClientTest`、`StsClientTest`、`SqsQueueTest`）对应的源文件是否也需要补充纯单元测试（mock 版本），还是仅依赖迁移后的集成测试作为覆盖？
- A) 也需要补充纯单元测试——所有源文件都应有 mock 版本的单元测试，集成测试作为补充
- B) 不需要——已有集成测试覆盖的源文件不再补充纯单元测试，仅迁移集成测试即可
- C) 视覆盖率情况决定——design 阶段评估现有集成测试的覆盖范围后再定
- D) 其他（请说明）

**A:** A — 所有源文件都应有 mock 版本的纯单元测试，集成测试作为补充

**Q2:** Requirement 6 要求源代码采用 Modern_PHP_Style，其中 AC1-3 要求添加类型声明。对于 `DynamoDbItem` 等需要处理多种动态类型的类，部分参数/返回值的类型可能是 `mixed`。使用 `mixed` 类型声明是否符合 "type is deterministic" 的要求，还是应视为类型不确定而跳过声明？
- A) `mixed` 是合法的 PHP 类型声明，应当使用——"deterministic" 指的是能确定类型签名，`mixed` 本身就是明确的签名
- B) `mixed` 应避免使用——如果只能写 `mixed`，说明类型不够确定，应跳过声明
- C) 按场景判断——公共 API 参数可用 `mixed`，内部方法应尽量使用更精确的类型
- D) 其他（请说明）

**A:** C — 按场景判断，公共 API 参数可用 `mixed`，内部方法应尽量使用更精确的类型

**Q3:** Requirement 3 AC3 要求集成测试在无 AWS 凭证时 "skippable"。跳过的粒度是什么级别？
- A) 整个 Integration_Test_Suite 级别——通过 PHPUnit 命令行参数或配置排除整个 suite
- B) 单个测试方法级别——每个测试方法内部检测凭证，无凭证则 `markTestSkipped`
- C) 测试类级别——在 `setUp()` 中检测凭证，无凭证则跳过整个类
- D) 其他（请说明）

**A:** A — 整个 suite 级别，通过 PHPUnit 命令行参数或配置排除整个 suite

**Q4:** Requirement 8 要求两个 suite 各自独立计算覆盖率。"独立计算"在执行层面意味着什么？
- A) 两个 suite 必须分别执行、分别生成覆盖率报告，不能合并运行
- B) 可以在同一次 PHPUnit 执行中运行，但通过配置或后处理分别提取各 suite 的覆盖率
- C) 只要最终能看到各 suite 各自的覆盖率数字即可，执行方式不限
- D) 其他（请说明）

**A:** C — 只要最终能看到各 suite 各自的覆盖率数字即可，执行方式不限
