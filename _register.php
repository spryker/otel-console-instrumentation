<?php

declare(strict_types=1);

use Spryker\Service\OtelConsoleInstrumentation\OpenTelemetry\ConsoleInstrumentation;
use Spryker\Shared\Opentelemetry\Instrumentation\CachedInstrumentation;
use Spryker\Shared\Opentelemetry\Request\RequestProcessor;

if (extension_loaded('opentelemetry') === false) {
    error_log('The opentelemetry extension must be loaded in order to autoload the OpenTelemetry Spryker Framework auto-instrumentation', E_USER_WARNING);

    return;
}

ConsoleInstrumentation::register(new CachedInstrumentation(), new RequestProcessor());

