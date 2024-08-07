<?php

declare(strict_types=1);

use OpenTelemetry\SDK\Sdk;
use Spryker\Service\OtelConsoleInstrumentation\OpenTelemetry\ConsoleInstrumentation;

if (class_exists(Sdk::class)) {
    return;
}

if (extension_loaded('opentelemetry') === false) {
    trigger_error('The opentelemetry extension must be loaded in order to autoload the OpenTelemetry Spryker Framework auto-instrumentation', E_USER_WARNING);

    return;
}

/**
 * @TO-DO Adjust
 */
$cachedInstrumentation = (new \Spryker\Zed\Opentelemetry\Business\Generator\Instrumentation\CachedInstrumentation())
    ->getCachedInstrumentation();
$request = (new \Spryker\Zed\Opentelemetry\Business\Generator\Request\RequestProcessor())
    ->getRequest();

ConsoleInstrumentation::register($cachedInstrumentation, $request);

