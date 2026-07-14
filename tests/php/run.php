<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$testFiles = glob(__DIR__ . '/*_test.php');
if ($testFiles === false) {
    fwrite(STDERR, "Unable to discover PHP tests.\n");
    exit(1);
}

$passed = 0;
$failed = 0;

foreach ($testFiles as $testFile) {
    $tests = require $testFile;
    if (!is_array($tests)) {
        fwrite(STDERR, sprintf("FAIL %s did not return a test array.\n", basename($testFile)));
        $failed++;
        continue;
    }

    foreach ($tests as $name => $test) {
        try {
            $test();
            printf("PASS %s\n", $name);
            $passed++;
        } catch (Throwable $error) {
            printf("FAIL %s: %s\n", $name, $error->getMessage());
            $failed++;
        }
    }
}

printf("\n%d passed, %d failed.\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
