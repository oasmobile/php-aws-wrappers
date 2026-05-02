# Changelog v3.0.4

本文件记录 v3.0.4 hotfix 的变更内容。

---

## 依赖升级

- `symfony/cache` 版本约束从 `^7.0` 升级到 `^8.0`（实际 v7.4.8 → v8.0.9）
- 全量更新所有依赖至最新兼容版本：
  - `aws/aws-sdk-php` 3.379.10 → 3.379.11
  - `oasis/event` v3.0.0 → v3.0.1
  - `oasis/logging` v3.0.0 → v3.1.0
  - `oasis/utils` v3.0.0 → v3.0.2
  - `phpunit/phpunit` 13.1.7 → 13.1.8
  - `symfony/filesystem` v8.0.8 → v8.0.9
  - `symfony/var-exporter` v8.0.8 → v8.0.9

---

## 测试覆盖

- 344 tests, 1780 assertions, 全部通过
