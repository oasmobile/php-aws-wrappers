<?php
/**
 * Run each integration test method individually with a 5s timeout.
 * Usage: php .kiro/specs/release-3.0/tests/profile-integration.php
 */

$output = shell_exec('php vendor/bin/phpunit --testsuite integration --list-tests 2>&1');
$tests = [];
foreach (explode("\n", $output) as $line) {
    if (preg_match('/^ - (.+)$/', $line, $m)) {
        $tests[] = $m[1];
    }
}

printf("%-80s %8s %s\n", 'TEST', 'TIME(s)', 'STATUS');
printf("%s\n", str_repeat('-', 100));

$problems = [];
foreach ($tests as $test) {
    [$class, $method] = explode('::', $test);
    $shortClass = basename(str_replace('\\', '/', $class));
    $label = sprintf('%s::%s', $shortClass, $method);

    $cmd = "php vendor/bin/phpunit --filter '{$method}' --testsuite integration 2>&1";

    $t0 = microtime(true);

    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    $out = '';
    $timedOut = false;

    if (is_resource($proc)) {
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                // Drain remaining output
                $out .= stream_get_contents($pipes[1]);
                $out .= stream_get_contents($pipes[2]);
                break;
            }
            $out .= fread($pipes[1], 8192);
            $out .= fread($pipes[2], 8192);

            if (microtime(true) - $t0 > 5.0) {
                $timedOut = true;
                proc_terminate($proc, 9);
                break;
            }
            usleep(50_000); // 50ms poll
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }

    $elapsed = microtime(true) - $t0;

    if ($timedOut) {
        $status = 'TIMEOUT';
    } elseif (str_contains($out, 'OK (')) {
        $status = 'PASS';
    } elseif (str_contains($out, 'ERRORS!') || str_contains($out, 'FAILURES!')) {
        $status = 'FAIL';
    } else {
        $status = 'UNKNOWN';
    }

    $flag = '';
    if ($elapsed > 1.0) $flag = ' ⚠ SLOW';
    if ($timedOut) $flag = ' ⛔ KILLED';

    printf("%-80s %7.2fs %s%s\n", $label, $elapsed, $status, $flag);

    if ($status !== 'PASS') {
        $lines = explode("\n", trim($out));
        $problems[] = ['label' => $label, 'time' => $elapsed, 'status' => $status, 'tail' => array_slice($lines, -20)];
    }
}

if ($problems) {
    echo "\n=== PROBLEM TESTS ===\n";
    foreach ($problems as $p) {
        printf("\n--- %s (%.2fs, %s) ---\n", $p['label'], $p['time'], $p['status']);
        echo implode("\n", $p['tail']) . "\n";
    }
}
