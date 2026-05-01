#!/usr/bin/env bash
#
# test-task-8.sh — Release 3.0 手工测试脚本
#
# 覆盖 sub-task: 8.2, 8.3, 8.4, 8.5
# 8.1 (alpha tag) 已单独完成
# 8.6 (API 兼容性) 需代码分析，单独执行
#
set -uo pipefail

PASS=0
FAIL=0
SKIP=0
RESULTS=()

record() {
    local id="$1" status="$2" detail="$3"
    RESULTS+=("[$status] $id: $detail")
    case "$status" in
        PASS) ((PASS++)) ;;
        FAIL) ((FAIL++)) ;;
        SKIP) ((SKIP++)) ;;
    esac
}

echo "========================================"
echo " Release 3.0 Manual Test — Task 8"
echo " PHP: $(php --version | head -1)"
echo " Date: $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"
echo ""

# ── 8.2 全量测试 ──────────────────────────────────────────

echo "── 8.2a Unit tests ──"
unit_output=$(php vendor/bin/phpunit --testsuite unit 2>&1)
unit_exit=$?
echo "$unit_output"

if [ $unit_exit -eq 0 ]; then
    # Check for deprecation warnings
    if echo "$unit_output" | grep -qi "deprecat"; then
        record "8.2a" "FAIL" "Unit tests passed but deprecation warnings found"
    else
        record "8.2a" "PASS" "Unit tests passed, zero deprecation warnings"
    fi
else
    record "8.2a" "FAIL" "Unit tests failed (exit code: $unit_exit)"
fi

echo ""
echo "── 8.2b Integration tests ──"
int_output=$(php vendor/bin/phpunit --testsuite integration 2>&1)
int_exit=$?
echo "$int_output"

if [ $int_exit -eq 0 ]; then
    if echo "$int_output" | grep -qi "deprecat"; then
        record "8.2b" "FAIL" "Integration tests passed but deprecation warnings found"
    else
        record "8.2b" "PASS" "Integration tests passed, zero deprecation warnings"
    fi
else
    record "8.2b" "FAIL" "Integration tests failed (exit code: $int_exit)"
fi

# ── 8.3 覆盖率阈值 ──────────────────────────────────────────

echo ""
echo "── 8.3a Unit coverage (threshold: 80%) ──"
php -dpcov.enabled=1 vendor/bin/phpunit --testsuite unit --coverage-text 2>&1 \
    | ./check-coverage.sh 80
cov_unit_exit=$?

if [ $cov_unit_exit -eq 0 ]; then
    record "8.3a" "PASS" "Unit coverage meets 80% threshold"
else
    record "8.3a" "FAIL" "Unit coverage below 80% threshold"
fi

echo ""
echo "── 8.3b Integration coverage (threshold: 60%) ──"
php -dpcov.enabled=1 vendor/bin/phpunit --testsuite integration --coverage-text 2>&1 \
    | ./check-coverage.sh 60
cov_int_exit=$?

if [ $cov_int_exit -eq 0 ]; then
    record "8.3b" "PASS" "Integration coverage meets 60% threshold"
else
    record "8.3b" "FAIL" "Integration coverage below 60% threshold"
fi

# ── 8.4 PBT 测试 ──────────────────────────────────────────

echo ""
echo "── 8.4 PBT tests ──"
pbt_output=$(php vendor/bin/phpunit --filter 'DynamoDbItemCodecPbtTest' --testsuite unit 2>&1)
pbt_exit=$?
echo "$pbt_output"

if [ $pbt_exit -eq 0 ]; then
    # Verify all 3 properties ran (macOS compatible grep)
    pbt_tests=$(echo "$pbt_output" | grep -o 'OK ([0-9]* test' | grep -o '[0-9]*' || echo "0")
    if [ "$pbt_tests" -ge 3 ] 2>/dev/null; then
        record "8.4" "PASS" "PBT: $pbt_tests properties passed"
    else
        record "8.4" "FAIL" "PBT: expected >= 3 properties, got $pbt_tests"
    fi
else
    record "8.4" "FAIL" "PBT tests failed (exit code: $pbt_exit)"
fi

# ── 8.5 composer install 干净安装 ──────────────────────────

echo ""
echo "── 8.5 Clean composer install ──"
# Backup vendor state
echo "Removing vendor/ ..."
rm -rf vendor/
echo "Running composer install ..."
composer_output=$(composer install --no-interaction 2>&1)
composer_exit=$?
echo "$composer_output"

if [ $composer_exit -eq 0 ]; then
    # Check for warnings (exclude "Nothing to install" which is fine)
    if echo "$composer_output" | grep -i "warning" | grep -vi "nothing to install" | grep -qi "warning"; then
        record "8.5" "FAIL" "composer install succeeded but warnings found"
    else
        record "8.5" "PASS" "composer install clean, no errors or warnings"
    fi
else
    record "8.5" "FAIL" "composer install failed (exit code: $composer_exit)"
fi

# ── Summary ──────────────────────────────────────────────

echo ""
echo "========================================"
echo " SUMMARY"
echo "========================================"
for r in "${RESULTS[@]}"; do
    echo "  $r"
done
echo ""
echo "  PASS: $PASS  FAIL: $FAIL  SKIP: $SKIP"
echo "========================================"

if [ $FAIL -gt 0 ]; then
    exit 1
fi
exit 0
