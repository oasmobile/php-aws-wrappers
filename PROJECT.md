# oasis/aws-wrappers

PHP 库，为 AWS SDK 常用服务提供面向对象的简化封装。

---

## 技术栈

| 项目 | 值 |
|------|-----|
| 语言 | PHP >=8.5 |
| 包管理 | Composer |
| 命名空间 | `Oasis\Mlib\AwsWrappers\`、`Oasis\Mlib\Logging\` |
| 核心依赖 | `aws/aws-sdk-php ^3.22`、`oasis/logging ^3.0`、`oasis/event ^3.0`、`symfony/cache ^7.0` |
| 测试框架 | PHPUnit ^13 |
| PBT 库 | `giorgiosironi/eris ^1.1` |
| 覆盖率驱动 | PCOV |
| 许可证 | MIT |

---

## 构建与测试命令

```bash
# 安装依赖
composer install

# 运行全部测试（unit + integration）
php vendor/bin/phpunit

# 仅运行单元测试（无需 AWS 凭证）
php vendor/bin/phpunit --testsuite unit

# 仅运行集成测试（需 AWS 凭证）
php vendor/bin/phpunit --testsuite integration

# 运行单个测试文件
php vendor/bin/phpunit ut/unit/DynamoDbItemTest.php

# 单元测试 + 覆盖率（阈值 80%）
php -dpcov.enabled=1 vendor/bin/phpunit --testsuite unit --coverage-text | ./check-coverage.sh 80

# 集成测试 + 覆盖率（阈值 60%，需 AWS 凭证）
php -dpcov.enabled=1 vendor/bin/phpunit --testsuite integration --coverage-text | ./check-coverage.sh 60
```

### PBT（Property-Based Testing）

PBT 测试位于 `ut/unit/Pbt/`，使用 `giorgiosironi/eris` 库，覆盖 `DynamoDbItem` 类型转换的 3 个正确性属性（Codec Round-Trip、Typed Codec Round-Trip、Codec Idempotence）。每个 property 最低 100 次迭代，随 unit suite 一起运行。

---

## 目录结构

```
src/
├── AwsWrappers/          # AWS 服务封装类
│   ├── Contracts/        # 接口定义
│   └── DynamoDb/         # DynamoDB 查询/扫描命令封装
└── Logging/              # Monolog Handler（SNS）

ut/
├── unit/                 # 纯单元测试（mock，可离线运行）
│   ├── DynamoDb/         # DynamoDB Command Wrapper 测试
│   └── Pbt/              # Property-Based Testing
├── integration/          # 集成测试（需 AWS 凭证）
├── ut-bootstrap.php      # 引导文件
└── tpl.ut.yml            # 配置模板

docs/                     # 项目文档
```

---

## 版本号位置

- `composer.json` → `version` 字段（当前未显式声明，由 Packagist / Git tag 管理）

---

## 敏感文件清单

| 文件/模式 | 说明 |
|-----------|------|
| `~/.aws/credentials` | AWS 凭证文件，不在仓库内 |
| `ut/tpl.ut.yml` | 测试配置模板，可能包含 region / profile 信息 |
| `.env` / `.env.*` | 环境变量文件（如存在） |
