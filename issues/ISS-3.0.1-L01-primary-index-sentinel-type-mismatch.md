# ISS-3.0.1-L01 PRIMARY_INDEX Loose Comparison Misidentifies GSI as Primary Index

| 字段 | 值 |
|------|------|
| Severity | `[P1] major` |
| Status | `open` |
| Found In | `v3.0.1` |
| Fixed In | |
| Related Test | |

---

## Description

`DynamoDbIndex::PRIMARY_INDEX = true`（bool）用作 sentinel value，标识"使用主索引"。`DynamoDbTable` 中三个方法使用松散比较 `==` / `!=` 判断该 sentinel，导致任何非空字符串 GSI 名称都被误判为 primary index。

---

## Steps to Reproduce

1. 调用 `DynamoDbTable::getThroughput("email-index")`
2. 方法内部执行 `$indexName == DynamoDbIndex::PRIMARY_INDEX`，即 `"email-index" == true`
3. PHP 松散比较下，任何非空字符串 `== true` 结果为 `true`
4. 错误地走入 primary index 分支，返回主表吞吐量而非 GSI 吞吐量

`setThroughput` 和 `getConsumedCapacity` 存在同样问题。

---

## Expected Behavior

传入 GSI 名称时，应正确识别为非 primary index，走 GSI 分支。

---

## Actual Behavior

- `getThroughput("email-index")` 返回主表吞吐量，而非 GSI 吞吐量
- `setThroughput(5, 5, "email-index")` 更新主表吞吐量，而非 GSI 吞吐量
- `getConsumedCapacity("email-index")` 不附加 GSI dimension，返回主表消耗量

---

## Analysis

### 根因

三个方法使用松散比较 `==` / `!=` 对比 bool sentinel：

```php
// getThroughput, setThroughput
if ($indexName == DynamoDbIndex::PRIMARY_INDEX)  // "any-string" == true → true

// getConsumedCapacity
if ($indexName != DynamoDbIndex::PRIMARY_INDEX)  // "any-string" != true → false
```

PHP 松散比较规则下，任何非空字符串与 `true` 比较结果为 `true`，导致 GSI 名称被误判为 primary index。

### 对比

Wrapper 层（`ScanAsyncCommandWrapper`、`QueryAsyncCommandWrapper`）使用严格比较 `!==`，不受此问题影响。

### 影响范围

| 方法 | 比较方式 | 问题 |
|------|----------|------|
| `getThroughput` | `==`（松散） | GSI 被误判为 primary，返回错误吞吐量 |
| `setThroughput` | `==`（松散） | GSI 被误判为 primary，更新错误目标 |
| `getConsumedCapacity` | `!=`（松散） | GSI 被误判为 primary，缺少 GSI dimension |

### 建议修复方向

将三处松散比较改为严格比较 `===` / `!==`。

---

## History

- `2026-05-01T08:00Z` `v3.0.1` [发现] review `DynamoDbIndex::PRIMARY_INDEX` sentinel 用法时发现松散比较导致 GSI 名称被误判
