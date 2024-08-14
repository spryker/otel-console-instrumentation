<?php

declare(strict_types=1);

use Spryker\Service\OtelConsoleInstrumentation\OpenTelemetry\ConsoleInstrumentation;
use Spryker\Shared\Opentelemetry\Instrumentation\CachedInstrumentation;
use Spryker\Shared\Opentelemetry\Request\RequestProcessor;

if (extension_loaded('opentelemetry') === false) {
    return;
}

ConsoleInstrumentation::register(new CachedInstrumentation(), new RequestProcessor());

