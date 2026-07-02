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
 * rendering cost lives (queries, augmentation, nested partials), and
 * tag names are bounded, unlike variable nodes, which are skipped
 * entirely. Spans are detail-marked, so tail-detail mode drops them
 * from uninteresting traces.
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

        $name = $this->tagName($node);

        $span = Telemetry::span('antlers:'.$name, null, ['antlers.tag' => $name]);
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
        // Anything still open is an unbalanced enter (runtime bailed) —
        // close instead of leaking into the next render.
        foreach (array_reverse($this->open) as $span) {
            $span->end();
        }

        $this->open = [];
    }

    private function isTraceableTag(AntlersNode $node): bool
    {
        return $node->isTagNode
            && ! $node->isClosingTag
            && ! $node->isComment;
    }

    private function tagName(AntlersNode $node): string
    {
        $identifier = $node->name;

        if ($identifier === null) {
            return 'unknown';
        }

        $name = (string) $identifier->name;
        $method = (string) $identifier->methodPart;

        return $method !== '' ? $name.':'.$method : $name;
    }
}
