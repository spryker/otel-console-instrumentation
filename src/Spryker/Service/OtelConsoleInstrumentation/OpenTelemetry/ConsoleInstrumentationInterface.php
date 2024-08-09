<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Service\OtelConsoleInstrumentation\OpenTelemetry;

use Spryker\Zed\Opentelemetry\Business\Generator\Instrumentation\CachedInstrumentation;
use Symfony\Component\HttpFoundation\Request;

interface ConsoleInstrumentationInterface
{
    /**
     * @param \Spryker\Zed\Opentelemetry\Business\Generator\Instrumentation\CachedInstrumentation $instrumentation
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return void
     */
    public static function register(CachedInstrumentation $instrumentation, Request $request): void;
}
