#!/usr/bin/env bash
#
# check-coverage.sh — Verify PHPUnit line coverage meets a minimum threshold.
#
# Usage:
#   php -dpcov.enabled=1 vendor/bin/phpunit --testsuite unit --coverage-text \
#       | ./check-coverage.sh <threshold>
#
# Arguments:
#   <threshold>  Minimum acceptable line coverage percentage (integer, e.g. 80)
#
# Exit codes:
#   0  Coverage meets or exceeds the threshold
#   1  Coverage is below the threshold
#   2  Usage error or unable to parse coverage output

set -euo pipefail

if [[ $# -ne 1 ]]; then
    echo "Usage: $0 <threshold>" >&2
    exit 2
fi

threshold="$1"

if ! [[ "$threshold" =~ ^[0-9]+$ ]]; then
    echo "Error: threshold must be a positive integer, got '${threshold}'" >&2
    exit 2
fi

# Strip ANSI escape sequences from a string.
strip_ansi() {
    # Remove CSI sequences (ESC [ ... final_byte) and OSC/other sequences.
    sed $'s/\x1b\\[[0-9;]*[a-zA-Z]//g'
}

# Read stdin (piped coverage-text output) and extract the Summary "Lines:" percentage.
# PHPUnit --coverage-text outputs a Summary section like:
#
#  Summary:
#    Classes: 52.63% (10/19)
#    Methods: 74.38% (90/121)
#    Lines:   72.73% (1208/1661)
#
# We need the Lines: from the Summary block, not from per-class breakdowns.
# Strategy: detect "Summary:" header, then capture the next "Lines:" line.
coverage_line=""
in_summary=false
while IFS= read -r line; do
    echo "$line"
    clean=$(echo "$line" | strip_ansi)
    if [[ "$clean" =~ Summary: ]]; then
        in_summary=true
    fi
    if $in_summary && [[ "$clean" =~ Lines:[[:space:]]+([0-9]+(\.[0-9]+)?)% ]]; then
        coverage_line="${BASH_REMATCH[1]}"
        in_summary=false
    fi
done

if [[ -z "$coverage_line" ]]; then
    echo "" >&2
    echo "Error: could not find Summary 'Lines:' percentage in coverage output" >&2
    exit 2
fi

# Compare using integer truncation (floor).
coverage_int="${coverage_line%%.*}"

if (( coverage_int >= threshold )); then
    echo ""
    echo "✅ Coverage ${coverage_line}% meets threshold ${threshold}%"
    exit 0
else
    echo "" >&2
    echo "❌ Coverage ${coverage_line}% is below threshold ${threshold}%" >&2
    exit 1
fi
