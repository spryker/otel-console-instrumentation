<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Service\OtelConsoleInstrumentation\OpenTelemetry;

use Exception;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageScopeInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use Spryker\Zed\Opentelemetry\Business\Generator\Instrumentation\CachedInstrumentation;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\HttpFoundation\Request;
use Throwable;
use function OpenTelemetry\Instrumentation\hook;

class ConsoleInstrumentation implements ConsoleInstrumentationInterface
{
    /**
     * @var string
     */
    protected const RUN_METHOD_NAME = 'run';

    /**
     * @var string
     */
    protected const SPAN_NAME_PLACEHOLDER = 'Console command: %s';

    /**
     * @var string
     */
    protected const ERROR_MESSAGE = 'error_message';

    /**
     * @var string
     */
    protected const ERROR_CODE = 'error_code';

    /**
     * @var string
     */
    protected const CLI_TRACE_ID = 'cli_trace_id';

    /**
     * @var string
     */
    protected const ERROR_TEXT_PLACEHOLDER = 'Error: %s in %s on line %d';

    /**
     * @param \Spryker\Zed\Opentelemetry\Business\Generator\Instrumentation\CachedInstrumentation $instrumentation
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return void
     */
    public static function register(
        CachedInstrumentation $instrumentation,
        Request $request
    ): void {
        hook(
            class: ConsoleApplication::class,
            function: static::RUN_METHOD_NAME,
            pre: static function ($instance, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $request): void {
                define('OTEL_CLI_TRACE_ID', uuid_create());

                $input = [static::CLI_TRACE_ID => OTEL_CLI_TRACE_ID];
                TraceContextPropagator::getInstance()->inject($input);

                $span = $instrumentation::getCachedInstrumentation()
                    ->tracer()
                    ->spanBuilder(implode(' ', $request->server->get('argv')))
                    ->setAttribute('CLI_ID', \OTEL_CLI_TRACE_ID)
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
                    ->setAttribute(TraceAttributes::URL_QUERY, $request->getQueryString())
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function ($instance, array $params, $returnValue, ?Throwable $exception): void {
                $scope = Context::storage()->scope();

                if ($scope === null) {
                    return;
                }

                static::handleError($scope);
            },
        );
    }

    /**
     * @param \OpenTelemetry\Context\ContextStorageScopeInterface $scope
     *
     * @return \OpenTelemetry\API\Trace\Span
     */
    protected static function handleError(ContextStorageScopeInterface $scope): Span
    {
        $error = error_get_last();

        if (is_array($error) && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
            $exception = new Exception(
                sprintf(static::ERROR_TEXT_PLACEHOLDER, $error['message'], $error['file'], $error['line']),
            );
        }

        $scope->detach();
        $span = Span::fromContext($scope->context());

        if ($exception) {
            $span->recordException($exception);
        }

        $span->setAttribute(static::ERROR_MESSAGE, $exception ? $exception->getMessage() : '');
        $span->setAttribute(static::ERROR_CODE, $exception ? $exception->getCode() : '');
        $span->setStatus($exception ? StatusCode::STATUS_ERROR : StatusCode::STATUS_OK);

        $span->end();

        return $span;
    }

    /**
     * @param string $name
     *
     * @return string
     */
    protected static function formatSpanName(string $name): string
    {
        return sprintf(static::SPAN_NAME_PLACEHOLDER, $name);
    }
}
