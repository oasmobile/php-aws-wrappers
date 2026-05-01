# Changelog v3.0.2

本文件记录 v3.0.2 patch 的变更内容。

---

## 修复

- **ISS-3.0.1-L01** `DynamoDbTable` 中 `getThroughput`、`setThroughput`、`getConsumedCapacity` 三处松散比较 `==`/`!=` 改为严格比较 `===`/`!==`，修复 GSI 名称被误判为 primary index 的问题

## 新增

- `DynamoDbTableTest::testGetThroughputWithStringGSIName` — 验证字符串 GSI 名称正确走 GSI 分支
- `DynamoDbTableTest::testSetThroughputWithStringGSIName` — 验证字符串 GSI 名称正确更新 GSI 吞吐量
