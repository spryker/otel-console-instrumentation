<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Service\OtelConsoleInstrumentation\OpenTelemetry;

use Exception;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageScopeInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use Spryker\Glue\Console\ConsoleBootstrap;
use Spryker\Service\Opentelemetry\Storage\CustomParameterStorage;
use Spryker\Shared\Opentelemetry\Instrumentation\CachedInstrumentation;
use Spryker\Shared\Opentelemetry\Request\RequestProcessor;
use Spryker\Yves\Console\Bootstrap\ConsoleBootstrap as YvesConsoleBootstrap;
use Spryker\Zed\Console\Communication\Bootstrap\ConsoleBootstrap as ZedConsoleBootstrap;
use Spryker\Zed\Opentelemetry\Business\Generator\SpanFilter\SamplerSpanFilter;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\HttpFoundation\Request;
use Throwable;
use function OpenTelemetry\Instrumentation\hook;

class ConsoleInstrumentation
{
    /**
     * @var string
     */
    protected const METHOD_NAME_RUN = 'doRun';

    /**
     * @var string
     */
    protected const METHOD_NAME_BOOTSTRAP = '__construct';

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
     * @return void
     */
    public static function register(): void
    {
        $request = new RequestProcessor();

        // phpcs:disable
        $bootstraps = [
            ConsoleBootstrap::class => 'Glue CLI',
            YvesConsoleBootstrap::class => 'Yves CLI',
            ZedConsoleBootstrap::class => 'Zed CLI',
        ];

        foreach ($bootstraps as $bootstrap => $application) {
            static::registerBootstrapHook($bootstrap, $application, $request);
        }

        hook(
            class: ConsoleApplication::class,
            function: static::METHOD_NAME_RUN,
            pre: static function ($instance, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($request): void {
                $instrumentation = CachedInstrumentation::getCachedInstrumentation();
                if ($instrumentation === null || $request->getRequest() === null) {
                    return;
                }

                if (!defined('OTEL_CLI_TRACE_ID')) {
                    define('OTEL_CLI_TRACE_ID', uuid_create());
                }

                $input = [static::CLI_TRACE_ID => OTEL_CLI_TRACE_ID];
                TraceContextPropagator::getInstance()->inject($input);

                $span = $instrumentation
                    ->tracer()
                    ->spanBuilder('Run: ' . static::formatSpanName($request->getRequest()))
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
                    ->setAttribute(TraceAttributes::URL_QUERY, $request->getRequest()->getQueryString())
                    ->startSpan();
                $span->activate();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function ($instance, array $params, $returnValue, ?Throwable $exception): void {
                $scope = Context::storage()->scope();

                if ($scope === null) {
                    return;
                }

                $span = static::handleError($scope);
                $span->setAttribute('hasCustomParams', true);
                $span->setAttributes(CustomParameterStorage::getInstance()->getAttributes());
                $span = SamplerSpanFilter::filter($span, true);

                $span->end();
            },
        );
        // phpcs:enable
    }

    /**
     * @param string $className
     * @param string $application
     * @param \Spryker\Shared\Opentelemetry\Request\RequestProcessor $requestProcessor
     *
     * @return void
     */
    protected static function registerBootstrapHook(string $className, string $application, RequestProcessor $requestProcessor): void
    {
        hook(
            class: $className,
            function: static::METHOD_NAME_BOOTSTRAP,
            pre: static function ($instance, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($requestProcessor, $application): void {
                putenv('OTEL_SERVICE_NAME=' . $application);
                $instrumentation = CachedInstrumentation::getCachedInstrumentation();
                if ($instrumentation === null || $requestProcessor->getRequest() === null) {
                    return;
                }

                if (!defined('OTEL_CLI_TRACE_ID')) {
                    define('OTEL_CLI_TRACE_ID', uuid_create());
                }

                $input = [static::CLI_TRACE_ID => OTEL_CLI_TRACE_ID];
                TraceContextPropagator::getInstance()->inject($input);

                $span = $instrumentation
                    ->tracer()
                    ->spanBuilder('Boot: ' . static::formatSpanName($requestProcessor->getRequest()))
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
                    ->setAttribute(TraceAttributes::URL_QUERY, $requestProcessor->getRequest()->getQueryString())
                    ->startSpan();
                $span->activate();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function ($instance, array $params, $returnValue, ?Throwable $exception): void {
                $scope = Context::storage()->scope();

                if ($scope === null) {
                    return;
                }

                $span = static::handleError($scope);
                SamplerSpanFilter::filter($span, true);

                $span->end();
            },
        );
    }

    /**
     * @param \OpenTelemetry\Context\ContextStorageScopeInterface $scope
     *
     * @return \OpenTelemetry\API\Trace\SpanInterface
     */
    protected static function handleError(ContextStorageScopeInterface $scope): SpanInterface
    {
        $error = error_get_last();
        $exception = null;

        if (is_array($error) && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
            $exception = new Exception(
                sprintf(static::ERROR_TEXT_PLACEHOLDER, $error['message'], $error['file'], $error['line']),
            );
        }

        $scope->detach();
        $span = Span::fromContext($scope->context());

        if ($exception !== null) {
            $span->recordException($exception);
        }

        $span->setAttribute(static::ERROR_MESSAGE, $exception !== null ? $exception->getMessage() : '');
        $span->setAttribute(static::ERROR_CODE, $exception !== null ? $exception->getCode() : '');
        $span->setStatus($exception !== null ? StatusCode::STATUS_ERROR : StatusCode::STATUS_OK);

        return $span;
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return string
     */
    protected static function formatSpanName(Request $request): string
    {
        return implode(' ', $request->server->get('argv'));
    }
}
