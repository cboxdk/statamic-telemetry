<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Hooks;
use Cbox\StatamicTelemetry\Support\Content;
use Illuminate\Http\Request;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

test('statamic users contribute roles, groups and the super flag', function () {
    Role::make('editor')->save();

    $user = User::make()->email('editor@example.com')->assignRole('editor');
    $user->save();

    expect(Hooks::userAttributes($user, 'web'))->toBe([
        'enduser.roles' => 'editor',
    ]);
});

test('super users are flagged', function () {
    $user = User::make()->email('admin@example.com')->makeSuper();
    $user->save();

    expect(Hooks::userAttributes($user, 'web'))->toBe([
        'enduser.super' => true,
    ]);
});

test('non-statamic users contribute nothing', function () {
    expect(Hooks::userAttributes(new stdClass, 'web'))->toBe([]);
});

test('entry requests are named by collection and blueprint', function () {
    Collection::make('pages')->save();

    $entry = tap(Entry::make()->collection('pages')->slug('about'))->save();

    $request = Request::create('/about');
    Content::capture($request, $entry);

    expect(Content::spanName($request))->toStartWith('GET entry:pages');

    $attributes = Content::attributes($request);

    expect($attributes['statamic.type'])->toBe('entry')
        ->and($attributes['statamic.collection'])->toBe('pages')
        ->and($attributes['statamic.entry.id'])->toBe((string) $entry->id());
});

test('requests without statamic data keep the default name', function () {
    $request = Request::create('/api/things');

    expect(Content::spanName($request))->toBeNull();
});

test('the site is always attached as an attribute', function () {
    $request = Request::create('/whatever');

    expect(Content::attributes($request))->toHaveKey('statamic.site');
});
