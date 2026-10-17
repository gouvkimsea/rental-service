<?php
// tests/run_all_tests.php - Unified CLI Test Runner for RentalKH Test Harness

$startTime = microtime(true);

echo "====================================================================\n";
echo "   RentalKH Unified Automated Test Harness Runner                  \n";
echo "====================================================================\n\n";

$suites = [
    'Rental Business Rules & Booking Engine' => __DIR__ . '/rental_business_tests.php',
    'Operational Management & RBAC'          => __DIR__ . '/operational_management_tests.php',
    'Financial Operations & Ledger'         => __DIR__ . '/financial_operations_tests.php',
    'End-to-End Production Verification'    => __DIR__ . '/production_e2e_tests.php',
    'UI Rendering & View Integrations'      => __DIR__ . '/render_test.php',
];


$results = [];
$totalPassed = 0;
$totalFailed = 0;
$allSuccess = true;

$phpBinary = PHP_BINARY ?: 'php';

foreach ($suites as $name => $file) {
    echo ">> Running suite: $name...\n";
    $suiteStart = microtime(true);

    $command = escapeshellcmd($phpBinary) . ' ' . escapeshellarg($file);
    exec($command . ' 2>&1', $outputLines, $returnCode);

    $suiteDuration = round(microtime(true) - $suiteStart, 2);
    $output = implode("\n", $outputLines);

    // Extract passed / failed counts from output
    $passed = 0;
    $failed = 0;

    if (preg_match('/Passed:\s*(\d+)/i', $output, $m)) {
        $passed = (int)$m[1];
    } elseif (preg_match('/(\d+)\s+Passed/i', $output, $m)) {
        $passed = (int)$m[1];
    }

    if (preg_match('/Failed:\s*(\d+)/i', $output, $m)) {
        $failed = (int)$m[1];
    } elseif (preg_match('/(\d+)\s+Failed/i', $output, $m)) {
        $failed = (int)$m[1];
    }

    $isOk = ($returnCode === 0 && $failed === 0);
    if (!$isOk) {
        $allSuccess = false;
    }

    $totalPassed += $passed;
    $totalFailed += $failed;

    $results[] = [
        'name'     => $name,
        'file'     => basename($file),
        'duration' => $suiteDuration,
        'passed'   => $passed,
        'failed'   => $failed,
        'status'   => $isOk ? 'PASSED' : 'FAILED',
        'code'     => $returnCode,
    ];

    $statusLabel = $isOk ? "[OK]" : "[FAIL]";
    echo "   $statusLabel Duration: {$suiteDuration}s | Passed: $passed | Failed: $failed\n\n";
    $outputLines = [];
}

$totalDuration = round(microtime(true) - $startTime, 2);

echo "====================================================================\n";
echo "   Test Harness Summary Report                                     \n";
echo "====================================================================\n";
printf("%-42s | %-8s | %-6s | %-6s | %-7s\n", "Test Suite", "Status", "Passed", "Failed", "Time");
echo str_repeat("-", 76) . "\n";

foreach ($results as $res) {
    printf(
        "%-42s | %-8s | %-6d | %-6d | %-6.2fs\n",
        $res['name'],
        $res['status'],
        $res['passed'],
        $res['failed'],
        $res['duration']
    );
}

echo str_repeat("=", 76) . "\n";
printf("Total Executed: %d tests | Passed: %d | Failed: %d | Total Time: %.2fs\n",
    $totalPassed + $totalFailed,
    $totalPassed,
    $totalFailed,
    $totalDuration
);
echo "====================================================================\n";

if ($allSuccess) {
    echo "All automated test suites completed successfully!\n";
    exit(0);
} else {
    echo "One or more test suites failed. Please review errors above.\n";
    exit(1);
}
