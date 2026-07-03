<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\View;

use Cbox\Telemetry\Facades\Telemetry;
use Illuminate\Contracts\View\Engine;
use Illuminate\Support\Str;
use Throwable;

/**
 * Wraps the Antlers engine so each render becomes a span. Spans are
 * marked as detail — under tail-detail mode they are dropped from
 * uninteresting traces.
 */
final class TracingEngine implements Engine
{
    public function __construct(private readonly Engine $inner) {}

    public function get($path, array $data = [])
    {
        // Outside a trace (a render in a console command or queued job)
        // don't mint an orphan root trace per view.
        if (Telemetry::tracer()->rootSpan() === null) {
            return $this->inner->get($path, $data);
        }

        $span = Telemetry::span('view.render', null, [
            'view.path' => Str::after((string) $path, base_path().DIRECTORY_SEPARATOR),
            'view.engine' => 'antlers',
        ]);

        $span->markDetail();

        try {
            return $this->inner->get($path, $data);
        } catch (Throwable $e) {
            $span->recordException($e);

            throw $e;
        } finally {
            $span->end();
        }
    }
}
