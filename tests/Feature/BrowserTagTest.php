<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Tags\Telemetry;
use Cbox\Telemetry\Facades\Telemetry as TelemetryFacade;

beforeEach(function () {
    config()->set('telemetry.ingest.spans.enabled', true);
    config()->set('telemetry.ingest.spans.path', 'telemetry/spans');
    config()->set('telemetry.ingest.spans.asset_path', 'telemetry/browser.js');
});

function tag(): Telemetry
{
    return app(Telemetry::class);
}

test('the browser tag renders the RUM script', function () {
    $this->fakeTelemetry();

    $out = tag()->browser();

    expect($out)->toContain('<script src=')
        ->and($out)->toContain('data-endpoint=')
        ->and($out)->toContain('telemetry/browser.js');
});

test('{{ telemetry:browser }} dispatches through the Antlers engine as raw HTML', function () {
    $this->fakeTelemetry();

    // A real Antlers view render — proves the tag is registered and wired.
    $out = view('browser')->render();

    expect($out)->toContain('<script src=')
        ->and($out)->not->toContain('&lt;script'); // raw, not escaped
});

test('the browser tag is empty when the span ingest is off', function () {
    config()->set('telemetry.ingest.spans.enabled', false);
    $this->fakeTelemetry();

    expect(tag()->browser())->toBe('');
});

test('the browser tag includes the traceparent meta within a trace', function () {
    $this->fakeTelemetry();

    $span = TelemetryFacade::span('GET /');

    expect(tag()->browser())->toContain('<meta name="traceparent" content="00-');

    $span->end();
});

test('the traceparent tag renders only the meta, and only within a trace', function () {
    $this->fakeTelemetry();

    expect(tag()->traceparent())->toBe('');

    $span = TelemetryFacade::span('GET /');

    $out = tag()->traceparent();

    expect($out)->toStartWith('<meta name="traceparent" content="00-')
        ->and($out)->not->toContain('<script');

    $span->end();
});

test('the traceparent value is html-escaped in the output', function () {
    $this->fakeTelemetry();
    TelemetryFacade::span('GET /');

    // A real traceparent is hex+dashes, but the attribute must be escaped
    // regardless — assert the output is a well-formed, quote-safe attribute.
    $out = tag()->traceparent();

    expect($out)->toMatch('/^<meta name="traceparent" content="[0-9a-f-]+">$/');
});

test('data-session only appears when analytics is enabled', function () {
    config()->set('telemetry.analytics.enabled', false);
    $this->fakeTelemetry();
    expect(tag()->browser())->not->toContain('data-session=');

    config()->set('telemetry.analytics.enabled', true);
    $this->fakeTelemetry();
    expect(tag()->browser())->toContain('data-session=');
});
