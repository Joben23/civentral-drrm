<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

echo 'SharedDatabaseMutationTest=RETIRED' . PHP_EOL;
echo 'Replacement=Database-free Module 4 citizen/lifecycle suite' . PHP_EOL;

require __DIR__ . '/test-early-warning-draft-history.php';
