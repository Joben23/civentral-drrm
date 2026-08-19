<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

drrmApiRun(static fn ($service): array => $service->faultFeatures());
