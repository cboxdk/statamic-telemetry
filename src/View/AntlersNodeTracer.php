<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\View;

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Tracing\Span;
use Statamic\View\Antlers\Language\Nodes\AbstractNode;
use Statamic\View\Antlers\Language\Nodes\AntlersNode;
use Statamic\View\Antlers\Language\Runtime\Tracing\RuntimeTracerContract;

/**
 * A span per Antlers *tag* invocation — collection, nav, form, partial —
 * via Statamic's official runtime tracing hook. Tags are where the
 * rendering cost lives (queries, augmentation, nested partials); variable
 * nodes are skipped. Spans are detail-marked, so tail-detail mode drops
 * them from uninteresting traces.
 *
 * Span names are the bare tag name only (`antlers:partial`,
 * `antlers:collection`) — bounded to Statamic's registered tag set. The
 * method part (`partial:components/hero`, `nav:main`) is unbounded — a
 * distinct value per partial file or dynamic target — so it goes on the
 * `antlers.method` span *attribute*, never the span name (which Grafana
 * groups on) or a metric label.
 *
 * Enabled by statamic-telemetry.instrument.antlers, which turns on
 * statamic.antlers.tracing — the runtime only consults tracers when
 * tracing is on, which is why this is opt-in.
 */
final class AntlersNodeTracer implements RuntimeTracerContract
{
    /** @var array<int, Span> open spans keyed by node object id */
    private array $open = [];

    public function onEnter(AbstractNode $node)
    {
        if (! $node instanceof AntlersNode || ! $this->isTraceableTag($node)) {
            return;
        }

        // No ambient trace (a render outside any request — Stache warm in
        // a console command, a queued render) — don't mint orphan root
        // traces, one per tag.
        if (Telemetry::tracer()->rootSpan() === null) {
            return;
        }

        [$name, $method] = $this->tagParts($node);

        $span = Telemetry::span('antlers:'.$name, null, array_filter([
            'antlers.tag' => $name,
            'antlers.method' => $method,
        ], fn ($value) => $value !== null));
        $span->markDetail();

        $this->open[spl_object_id($node)] = $span;
    }

    public function onExit(AbstractNode $node, $runtimeContent)
    {
        $span = $this->open[spl_object_id($node)] ?? null;

        if ($span !== null) {
            unset($this->open[spl_object_id($node)]);
            $span->end();
        }
    }

    public function onRenderComplete()
    {
        // Anything still open is an unbalanced enter (the runtime bailed on
        // a node) — close instead of leaking into the next render.
        foreach (array_reverse($this->open, true) as $id => $span) {
            $span->end();
            unset($this->open[$id]);
        }
    }

    private function isTraceableTag(AntlersNode $node): bool
    {
        return $node->isTagNode
            && ! $node->isClosingTag
            && ! $node->isComment;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function tagParts(AntlersNode $node): array
    {
        $identifier = $node->name;

        if ($identifier === null) {
            return ['unknown', null];
        }

        $name = (string) $identifier->name;
        $method = (string) $identifier->methodPart;

        return [$name, $method !== '' ? $method : null];
    }
}
