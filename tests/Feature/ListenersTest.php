<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use Statamic\Events\DuplicateIdRegenerated;
use Statamic\Events\EntryScheduleReached;
use Statamic\Events\GlideCacheCleared;
use Statamic\Events\GlideImageGenerated;
use Statamic\Events\GlobalVariablesSaved;
use Statamic\Events\ImpersonationStarted;
use Statamic\Events\SearchIndexUpdated;
use Statamic\Events\StacheCleared;
use Statamic\Events\StaticCacheCleared;
use Statamic\Events\SubmissionCreated;
use Statamic\Events\TwoFactorAuthenticationFailed;
use Statamic\Events\UserRegistered;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Search;
use Statamic\Facades\User;

test('glide generations are counted per preset', function () {
    $fake = $this->fakeTelemetry();

    event(new GlideImageGenerated('img/hero.jpg', ['p' => 'thumbnail']));
    event(new GlideImageGenerated('img/hero.jpg', ['w' => 100]));

    $fake->assertCounterIncremented('statamic.glide.generations', ['preset' => 'thumbnail']);
    $fake->assertCounterIncremented('statamic.glide.generations', ['preset' => 'custom']);
});

test('form submissions are counted per form', function () {
    $fake = $this->fakeTelemetry();

    $form = tap(Form::make('contact')->title('Contact'))->save();

    event(new SubmissionCreated($form->makeSubmission()));

    $fake->assertCounterIncremented('statamic.forms.submissions', ['form' => 'contact']);
});

test('content changes are counted by type and action', function () {
    $fake = $this->fakeTelemetry();

    // The generic class-name → type/action mechanism (entry publish-status
    // actions are covered in PublishStateTest).
    Collection::make('pages')->save();

    $fake->assertCounterIncremented('statamic.content.changes', ['type' => 'collection', 'action' => 'saved']);
});

test('search index updates are counted per index', function () {
    $fake = $this->fakeTelemetry();

    event(new SearchIndexUpdated(Search::index()));

    $fake->assertCounterIncremented('statamic.search.index_updates', ['index' => 'default']);
});

test('auth and security events are counted with bounded labels', function () {
    $fake = $this->fakeTelemetry();

    $user = tap(User::make()->email('auth@example.com'))->save();

    event(new UserRegistered($user));
    event(new TwoFactorAuthenticationFailed($user));
    event(new ImpersonationStarted($user, $user));

    $fake->assertCounterIncremented('statamic.auth.events', ['event' => 'user_registered']);
    $fake->assertCounterIncremented('statamic.auth.events', ['event' => 'two_factor_failed']);
    $fake->assertCounterIncremented('statamic.auth.events', ['event' => 'impersonation_started']);
});

test('glide cache clears are counted by scope', function () {
    $fake = $this->fakeTelemetry();

    event(new GlideCacheCleared);

    $fake->assertCounterIncremented('statamic.glide.cache_clears', ['scope' => 'all']);
});

test('special content events map to explicit type and action', function () {
    $fake = $this->fakeTelemetry();

    Collection::make('pages')->save();

    event(new EntryScheduleReached(Entry::make()->collection('pages')));
    event(new DuplicateIdRegenerated);
    event(new GlobalVariablesSaved(GlobalSet::make('footer')->makeLocalization('default')));

    $fake->assertCounterIncremented('statamic.content.changes', ['type' => 'entry', 'action' => 'schedule_reached']);
    $fake->assertCounterIncremented('statamic.content.changes', ['type' => 'duplicate_id', 'action' => 'regenerated']);
    $fake->assertCounterIncremented('statamic.content.changes', ['type' => 'global_variables', 'action' => 'saved']);
});

test('stache clears are counted', function () {
    $fake = $this->fakeTelemetry();

    event(new StacheCleared);

    $fake->assertCounterIncremented('statamic.stache.clears');
});

test('cache purges emit an annotation event per cache type', function () {
    $fake = $this->fakeTelemetry();

    event(new StacheCleared);
    event(new StaticCacheCleared);
    event(new GlideCacheCleared);

    $fake->assertEventEmitted('statamic.cache.purge', fn ($event) => $event->attributes['cache.type'] === 'stache');
    $fake->assertEventEmitted('statamic.cache.purge', fn ($event) => $event->attributes['cache.type'] === 'static');
    $fake->assertEventEmitted('statamic.cache.purge', fn ($event) => $event->attributes['cache.type'] === 'glide');

    expect($fake->recordedEvents('statamic.cache.purge'))->toHaveCount(3)
        ->and($fake->recordedEvents('statamic.cache.purge')[0]->attributes['cache.trigger'])->toBe('cli');
});

test('cache purge annotations respect their config toggle', function () {
    config()->set('statamic-telemetry.instrument.cache_purges', false);

    $fake = $this->fakeTelemetry();

    event(new StacheCleared);

    $fake->assertEventNotEmitted('statamic.cache.purge');
    $fake->assertCounterIncremented('statamic.stache.clears');
});

test('the current site becomes ambient context when a route matches', function () {
    $fake = $this->fakeTelemetry();

    $request = Request::create('/about');

    event(new RouteMatched(new Route(['GET'], '/about', []), $request));

    expect($fake->contextAttributes())->toHaveKey('statamic.site');
});

test('listeners respect their config toggles', function () {
    config()->set('statamic-telemetry.instrument.glide', false);

    $fake = $this->fakeTelemetry();

    event(new GlideImageGenerated('img/hero.jpg', ['p' => 'thumbnail']));

    $fake->assertCounterNotIncremented('statamic.glide.generations');
});
