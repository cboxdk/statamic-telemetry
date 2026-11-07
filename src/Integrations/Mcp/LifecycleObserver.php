<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Integrations\Mcp;

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Telemetry\Tracing\SpanStatus;
use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleEvent;
use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleObserver as ObserverContract;
use Cboxdk\StatamicMcp\Actions\Events\ExecutionEvent;
use Cboxdk\StatamicMcp\Actions\Events\GrantEvent;

/** Explicit metadata projection; identifiers, content and exceptions never become labels. */
final class LifecycleObserver implements ObserverContract
{
    /** @var array<string, ActiveExecution> */
    private array $active = [];

    /** @var array<string, true> */
    private array $seen = [];

    public function observe(LifecycleEvent $event): void
    {
        if (! config('statamic-telemetry.enabled', true) || ! config('statamic-telemetry.instrument.mcp', false)) {
            $this->discardActive();

            return;
        }
        if (isset($this->seen[$event->eventId()])) {
            return;
        }
        if (count($this->seen) >= 1024) {
            array_shift($this->seen);
        }
        $this->seen[$event->eventId()] = true;

        $manager = Telemetry::getFacadeRoot();
        if (! $manager instanceof TelemetryManager || ! $manager->enabled()) {
            $this->discardActive();

            return;
        }
        if ($event instanceof ExecutionEvent) {
            $this->execution($event, $manager);
        } elseif ($event instanceof GrantEvent) {
            if ($event->schemaVersion !== 1
                || ! in_array($event->eventName(), ['grant.issued', 'grant.revoked', 'grant.refresh_replay_detected'], true)
                || ! in_array($event->transport, ['http', 'stdio', 'cp', 'internal'], true)) {
                return;
            }
            // Neither stable grant ID nor credential/user/client IDs are exported.
            $manager->counter('statamic.mcp.grants', 'MCP grant lifecycle observations')
                ->inc(1, ['event' => $event->eventName(), 'transport' => $event->transport]);
        }
    }

    private function execution(ExecutionEvent $event, TelemetryManager $manager): void
    {
        if ($event->schemaVersion !== 1 || $event->executionId === null || strlen($event->executionId) > 128
            || ! in_array($event->transport, ['http', 'stdio'], true)
            || ! in_array($event->outcome, ['started', 'completed', 'denied', 'failed', 'replayed'], true)
            || ! in_array($event->effects, ['none', 'committed', 'unknown'], true)
            || ! is_finite($event->durationMilliseconds) || $event->durationMilliseconds < 0) {
            return;
        }
        $tracer = $manager->tracer();
        $attributes = $this->attributes($event);
        if ($event->outcome === 'started') {
            if (isset($this->active[$event->executionId])) {
                return;
            }
            if (count($this->active) >= 64) {
                throw new \RuntimeException('MCP telemetry has too many open executions.');
            }
            $ownsContext = $event->transport === 'stdio' && $this->active === [];
            if ($ownsContext) {
                // A server loop is not one user request. Never inherit a previous call.
                $tracer->resetContext();
            }
            $span = $tracer->startSpan('statamic.mcp.execute', SpanKind::Internal, $attributes);
            $this->active[$event->executionId] = new ActiveExecution($span, $manager, $ownsContext);

            return;
        }

        $active = $this->active[$event->executionId] ?? null;
        unset($this->active[$event->executionId]);
        $ownsContext = $active->ownsContext ?? ($event->transport === 'stdio' && $this->active === []);
        // Complete through the manager that started the span, even if a test/worker swapped it.
        $manager = $active->manager ?? $manager;
        $tracer = $manager->tracer();
        if ($active === null && $ownsContext) {
            $tracer->resetContext();
        }
        try {
            if ($active !== null) {
                $active->span->setAttributes($attributes);
                $active->span->setStatus($event->outcome === 'failed' ? SpanStatus::Error : SpanStatus::Ok);
                $active->span->end();
            } else {
                // Denial/replay is observed without inventing a handler execution.
                $tracer->recordSpan('statamic.mcp.'.$event->outcome, $event->durationMilliseconds, $attributes,
                    status: $event->outcome === 'failed' ? SpanStatus::Error : SpanStatus::Unset);
            }
            $labels = ['action' => $attributes['mcp.action'], 'transport' => $event->transport,
                'outcome' => $event->outcome, 'effects' => $event->effects];
            $manager->counter('statamic.mcp.calls', 'Completed MCP attempts, including denial and replay')->inc(1, $labels);
            $manager->histogram('statamic.mcp.duration', description: 'MCP attempt duration', unit: 's')
                ->record($event->durationMilliseconds / 1000, $labels);
            if ($ownsContext && config('statamic-telemetry.mcp.flush_stdio', true)) {
                $manager->flush();
            }
        } finally {
            if ($active !== null && ! $active->span->hasEnded()) {
                $tracer->discardSpan($active->span);
            }
            if ($ownsContext) {
                $tracer->resetContext();
            }
        }
    }

    /** @return array{'mcp.action': string, 'mcp.action_version': int, 'mcp.transport': string, 'mcp.outcome': string, 'mcp.effects': string} */
    private function attributes(ExecutionEvent $event): array
    {
        $action = strlen($event->actionId) <= 200
            && preg_match('/^[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*\/[a-z][a-z0-9_-]{0,63}$/D', $event->actionId)
            ? $event->actionId : 'unknown';

        return ['mcp.action' => $action, 'mcp.action_version' => $event->actionVersion,
            'mcp.transport' => $event->transport, 'mcp.outcome' => $event->outcome, 'mcp.effects' => $event->effects];
    }

    private function discardActive(): void
    {
        $active = $this->active;
        $this->active = [];
        foreach ($active as $item) {
            $item->manager->tracer()->discardSpan($item->span);
            if ($item->ownsContext) {
                $item->manager->tracer()->resetContext();
            }
        }
    }
}
