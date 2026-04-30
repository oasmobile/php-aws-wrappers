# oasis/aws-wrappers

PHP 库，为 AWS SDK 常用服务提供面向对象的简化封装。

---

## 技术栈

| 项目 | 值 |
|------|-----|
| 语言 | PHP |
| 包管理 | Composer |
| 命名空间 | `Oasis\Mlib\AwsWrappers\`、`Oasis\Mlib\Logging\` |
| 核心依赖 | `aws/aws-sdk-php ^3.22`、`oasis/logging ^1.3`、`oasis/event ^1.0`、`doctrine/common ^2.7` |
| 测试框架 | PHPUnit ^5.7 |
| 许可证 | MIT |

---

## 构建与测试命令

```bash
# 安装依赖
composer install

# 运行全量测试
./vendor/bin/phpunit

# 运行单个测试文件
./vendor/bin/phpunit ut/DynamoDbItemTest.php
```

---

## 目录结构

```
src/
├── AwsWrappers/          # AWS 服务封装类
│   ├── Contracts/        # 接口定义
│   └── DynamoDb/         # DynamoDB 查询/扫描命令封装
└── Logging/              # Monolog Handler（SNS）

ut/                       # 单元测试
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
