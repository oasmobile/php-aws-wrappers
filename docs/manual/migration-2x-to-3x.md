# Migration Guide: 2.x → 3.x

`docs/manual/` — 从 `oasis/aws-wrappers` 2.x 升级到 ^3.0 的迁移指南。

---

## 概述

3.0 是一次技术栈升级，核心变更：

- PHP 最低版本从 7.4 提升到 **8.5**
- PHPUnit 从 ^5.7 升级到 **^13**
- `doctrine/common` 缓存替换为 `symfony/cache`
- 源代码全面添加类型声明（参数、返回值、属性）

**公共 API 签名保持不变**——方法名、参数顺序、行为契约均未改变。大多数项目只需调整 PHP 版本和依赖即可完成升级。

---

## 前置条件

| 项目 | 要求 |
|------|------|
| PHP | >= 8.5 |
| Composer | >= 2.x |

升级前确认你的运行环境满足 PHP 8.5 要求。如果项目中还有其他依赖锁定在 PHP 7.x，需要先处理那些依赖。

---

## 升级步骤

### 1. 更新 Composer 依赖

```bash
composer require oasis/aws-wrappers:^3.0
```

这会同时拉入以下依赖变更：

| 依赖 | 2.x | 3.x |
|------|-----|-----|
| `php` | >= 7.4 | >= 8.5 |
| `aws/aws-sdk-php` | ^3.22 | ^3.22（不变） |
| `oasis/logging` | ^2.0 | ^3.0 |
| `oasis/event` | ^2.0 | ^3.0 |
| `doctrine/common` | ^2.7 | **已移除** |
| `symfony/cache` | — | ^7.0（新增） |
| `psr/simple-cache` | — | ^3.0（新增） |

如果你的项目直接依赖了 `oasis/logging` 或 `oasis/event`，需要同步升级到 ^3.0。

### 2. 移除 `doctrine/common`（如有直接依赖）

如果你的 `composer.json` 中直接 require 了 `doctrine/common`，且仅因本库而引入，可以移除：

```bash
composer remove doctrine/common
```

### 3. 检查 IAM Role 缓存（仅 ECS 用户）

如果你使用 `"iamrole" => true` 认证方式，缓存实现已从 `Doctrine\Common\Cache\FilesystemCache` 切换到 `Symfony\Component\Cache\Adapter\FilesystemAdapter`。

**影响**：

- 缓存目录路径不变
- 底层存储格式不同，旧缓存文件会自动失效并重建
- **无需手动操作**，首次请求时会自动重新获取凭证并缓存

### 4. 检查类型兼容性

3.0 为所有方法添加了 PHP 类型声明。如果你的代码存在以下情况，可能触发 `TypeError`：

```php
// 2.x 可以工作（隐式类型转换）
$table->get("123");  // 传入 string，但 key 期望 array

// 3.x 会抛出 TypeError
$table->get("123");  // 类型不匹配
```

**排查方法**：在 PHP 8.5 下运行你的测试套件，`TypeError` 会明确指出不兼容的调用点。

公共 API 参数中不确定类型的位置使用了 `mixed`，不会影响现有正确调用。

### 5. 更新测试（如果你扩展了本库的类）

如果你的项目继承了本库的类并覆写了方法，需要在子类中添加匹配的类型声明：

```php
// 2.x
class MyTable extends DynamoDbTable {
    public function get($keys) { ... }
}

// 3.x — 需要匹配父类的类型声明
class MyTable extends DynamoDbTable {
    public function get(array $keys): ?array { ... }
}
```

---

## Breaking Changes 清单

### 必须处理

| 变更 | 影响 | 操作 |
|------|------|------|
| PHP >= 8.5 | 无法在 PHP 7.x/8.0-8.4 上运行 | 升级 PHP 运行时 |
| `oasis/logging` ^3.0 | 依赖版本冲突 | 同步升级 |
| `oasis/event` ^3.0 | 依赖版本冲突 | 同步升级 |
| `doctrine/common` 移除 | 如直接依赖会报错 | 移除或替换 |

### 可能需要处理

| 变更 | 影响 | 操作 |
|------|------|------|
| 方法添加类型声明 | 隐式类型转换的调用点会抛 `TypeError` | 修正调用方类型 |
| 属性添加 `readonly` | 子类中对父类 `readonly` 属性的赋值会报错 | 调整子类逻辑 |
| 构造器属性提升 | 子类 `parent::__construct()` 调用不受影响 | 通常无需操作 |

### 无需处理

| 变更 | 说明 |
|------|------|
| `match` 替换 `switch` | 内部实现变更，不影响外部行为 |
| `symfony/cache` 替换 `doctrine/common` | 内部缓存实现变更，API 不变 |
| 测试目录重组 | 仅影响库开发者，不影响使用者 |
| PHPUnit ^13 | dev 依赖，不影响使用者 |

---

## 常见问题

### Q: 升级后 `composer install` 报依赖冲突怎么办？

最常见的原因是 `oasis/logging` 或 `oasis/event` 版本不兼容。确保这两个包也升级到 ^3.0：

```bash
composer require oasis/logging:^3.0 oasis/event:^3.0 oasis/aws-wrappers:^3.0
```

### Q: 我用了 `"iamrole" => true`，升级后需要清理缓存吗？

不需要。旧的 `doctrine/common` 缓存文件会自动失效，`symfony/cache` 会重新创建缓存。首次请求可能稍慢（需要重新获取 ECS 凭证），之后恢复正常。

### Q: 公共 API 有没有方法被删除或重命名？

没有。3.0 保持了完整的 API 兼容性——所有方法名、参数顺序、行为契约均未改变。唯一的变化是添加了类型声明。

### Q: 我需要修改业务代码吗？

如果你的调用方式类型正确（传入的参数类型与文档一致），通常不需要修改。运行测试套件即可验证。

### Q: 3.0.1 和 3.0.2 有什么额外变更？

- **3.0.1**：README 文档链接修复，无代码变更
- **3.0.2**：修复 `DynamoDbTable` 中松散比较导致 GSI 名称被误判为 primary index 的 bug（`==`/`!=` → `===`/`!==`）。建议直接升级到 3.0.2

---

## 升级检查清单

- [ ] PHP 运行时升级到 >= 8.5
- [ ] `composer require oasis/aws-wrappers:^3.0`（建议 ^3.0.2）
- [ ] 同步升级 `oasis/logging:^3.0` 和 `oasis/event:^3.0`
- [ ] `composer install` 无报错
- [ ] 运行项目测试套件，无 `TypeError`
- [ ] 如有子类覆写，添加匹配的类型声明
- [ ] 如直接依赖 `doctrine/common`，评估是否移除
