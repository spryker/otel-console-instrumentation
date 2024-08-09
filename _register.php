<?php

declare(strict_types=1);

use Spryker\Service\OtelConsoleInstrumentation\OpenTelemetry\ConsoleInstrumentation;

if (extension_loaded('opentelemetry') === false) {
    trigger_error('The opentelemetry extension must be loaded in order to autoload the OpenTelemetry Spryker Framework auto-instrumentation', E_USER_WARNING);

    return;
}

/**
 * @TO-DO Adjust
 */
ConsoleInstrumentation::register(
    new \Spryker\Zed\Opentelemetry\Business\Generator\Instrumentation\CachedInstrumentation(),
    (new \Spryker\Zed\Opentelemetry\Business\Generator\Request\RequestProcessor())->getRequest()
);

