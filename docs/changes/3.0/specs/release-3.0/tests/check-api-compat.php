<?php
/**
 * Extract all public method signatures from src/ and compare with the
 * baseline (first commit on release/3.0 branch, before any changes).
 *
 * Checks: method names, parameter order, parameter count.
 * Does NOT flag: added type declarations, added return types (these are
 * backward-compatible additions in PHP).
 */

// Get baseline: develop branch (the state before release/3.0 changes)
$baseCommit = trim(shell_exec('git rev-parse origin/develop 2>/dev/null') ?: '');

if (!$baseCommit) {
    echo "ERROR: Cannot find develop branch\n";
    exit(2);
}

echo "Baseline commit: $baseCommit\n\n";

// Extract public method signatures from current src/
$currentSigs = extractSignatures('src/');

// Extract from baseline using git show
$tmpDir = sys_get_temp_dir() . '/api-compat-' . getmypid();
@mkdir($tmpDir, 0755, true);

$srcFiles = [];
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('src/'));
foreach ($iter as $f) {
    if ($f->getExtension() === 'php') {
        $srcFiles[] = $f->getPathname();
    }
}

$baselineSigs = [];
foreach ($srcFiles as $file) {
    $content = shell_exec("git show {$baseCommit}:{$file} 2>/dev/null");
    if ($content === null) continue;
    $tmpFile = $tmpDir . '/' . basename($file);
    file_put_contents($tmpFile, $content);
    $sigs = extractSignaturesFromFile($tmpFile, basename($file));
    $baselineSigs = array_merge($baselineSigs, $sigs);
    unlink($tmpFile);
}
@rmdir($tmpDir);

// Compare
$issues = [];
foreach ($baselineSigs as $key => $baseSig) {
    if (!isset($currentSigs[$key])) {
        $issues[] = "REMOVED: {$key}";
        continue;
    }
    $curSig = $currentSigs[$key];
    // Compare parameter names and order (ignore types)
    if ($baseSig['params'] !== $curSig['params']) {
        $issues[] = "CHANGED: {$key}\n  was:    (" . implode(', ', $baseSig['params']) . ")\n  now:    (" . implode(', ', $curSig['params']) . ")";
    }
}

if (empty($issues)) {
    echo "✅ All public API signatures preserved. No breaking changes.\n";
    echo "   Checked " . count($baselineSigs) . " methods.\n";
    exit(0);
} else {
    echo "❌ API compatibility issues found:\n\n";
    foreach ($issues as $issue) {
        echo "  $issue\n";
    }
    exit(1);
}

function extractSignatures(string $dir): array
{
    $sigs = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') continue;
        $sigs = array_merge($sigs, extractSignaturesFromFile($file->getPathname(), $file->getFilename()));
    }
    return $sigs;
}

function extractSignaturesFromFile(string $path, string $filename): array
{
    $sigs = [];
    $content = file_get_contents($path);
    $tokens = token_get_all($content);
    $className = '';

    for ($i = 0; $i < count($tokens); $i++) {
        // Find class name
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS) {
            for ($j = $i + 1; $j < count($tokens); $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $className = $tokens[$j][1];
                    break;
                }
            }
        }
        // Find public function
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_PUBLIC) {
            // Look ahead for T_FUNCTION
            for ($j = $i + 1; $j < min($i + 5, count($tokens)); $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_FUNCTION) {
                    // Get method name
                    for ($k = $j + 1; $k < count($tokens); $k++) {
                        if (is_array($tokens[$k]) && $tokens[$k][0] === T_STRING) {
                            $methodName = $tokens[$k][1];
                            // Get parameters (just names, not types)
                            $params = extractParamNames($tokens, $k);
                            $key = "{$className}::{$methodName}";
                            $sigs[$key] = ['params' => $params];
                            break;
                        }
                    }
                    break;
                }
            }
        }
    }
    return $sigs;
}

function extractParamNames(array $tokens, int $startIdx): array
{
    $params = [];
    $depth = 0;
    $inParams = false;
    for ($i = $startIdx; $i < count($tokens); $i++) {
        $t = $tokens[$i];
        if ($t === '(') { $depth++; $inParams = true; continue; }
        if ($t === ')') { $depth--; if ($depth === 0) break; continue; }
        if ($inParams && $depth === 1 && is_array($t) && $t[0] === T_VARIABLE) {
            $params[] = $t[1];
        }
    }
    return $params;
}
