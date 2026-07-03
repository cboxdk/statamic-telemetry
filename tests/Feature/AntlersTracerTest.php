<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Tests\Feature;

use Cbox\StatamicTelemetry\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

class AntlersTracerTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-telemetry.instrument.antlers', true);
    }

    public function test_antlers_tag_invocations_become_detail_spans(): void
    {
        Collection::make('pages')->routes('{slug}')->save();

        tap(Entry::make()->collection('pages')->slug('tagged')->data([
            'title' => 'Tagged',
            'template' => 'tagged',
        ]))->save();

        $fake = $this->fakeTelemetry();

        $this->get('/tagged')->assertOk();

        $tagSpans = array_filter(
            $fake->recordedSpans(),
            fn ($span) => str_starts_with($span->name, 'antlers:collection'),
        );

        $this->assertNotEmpty($tagSpans);

        $span = array_values($tagSpans)[0];

        $this->assertTrue($span->isDetail());
        // Span name and antlers.tag stay bounded to the bare tag name;
        // the unbounded method part goes on antlers.method.
        $this->assertSame('antlers:collection', $span->name);
        $this->assertSame('collection', $span->attributes()['antlers.tag']);
        $this->assertSame('pages', $span->attributes()['antlers.method']);
    }

    public function test_nested_tags_produce_balanced_spans(): void
    {
        Collection::make('pages')->routes('{slug}')->save();

        tap(Entry::make()->collection('pages')->slug('nest')->data([
            'title' => 'Nest',
            'template' => 'nested',
        ]))->save();

        $fake = $this->fakeTelemetry();

        $this->get('/nest')->assertOk();

        $names = array_map(fn ($span) => $span->name, $fake->recordedSpans());

        // Both the outer collection tag and the inner partial are recorded,
        // and every span that opened also ended (recordedSpans only holds
        // ended spans) — no leak, no premature close throwing.
        $this->assertContains('antlers:collection', $names);
        $this->assertContains('antlers:partial', $names);
    }
}
