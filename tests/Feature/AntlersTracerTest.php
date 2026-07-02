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
        $this->assertSame('collection:pages', $span->attributes()['antlers.tag']);
    }
}
