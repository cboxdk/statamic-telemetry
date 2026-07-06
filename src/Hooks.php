<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry;

use Cbox\StatamicTelemetry\Support\CacheKeys;
use Cbox\StatamicTelemetry\Support\Content;
use Cbox\Telemetry\TelemetryManager;
use Statamic\Contracts\Auth\User as StatamicUser;

/**
 * Registers the Statamic resolvers on the telemetry manager.
 *
 * laravel-telemetry's resolver hooks are single-slot — the last
 * registration wins. This addon registers during boot, so an app that
 * registers its own resolver afterwards replaces the Statamic one. To
 * compose instead of replace, delegate to the public methods here:
 *
 *     Telemetry::resolveUserUsing(fn ($user, $guard) => [
 *         ...Hooks::userAttributes($user, $guard),
 *         'enduser.plan' => $user->plan ?? null,
 *     ]);
 */
final class Hooks
{
    /**
     * Idempotent — tests re-run it against Telemetry::fake(), since the
     * fake replaces the manager the boot-time registrations landed on.
     */
    public static function register(?TelemetryManager $telemetry = null): void
    {
        $telemetry ??= app(TelemetryManager::class);

        if (! config('statamic-telemetry.enabled', true)) {
            return;
        }

        if (config('statamic-telemetry.instrument.user', true)) {
            $telemetry->resolveUserUsing(self::userAttributes(...));
        }

        if (config('statamic-telemetry.instrument.content', true)) {
            // The logical route (entry:{collection}.{blueprint} /
            // term:{taxonomy} / taxonomy:{handle}) replaces the useless
            // /{segments?} catch-all as http.route — on the span and the
            // request metric — so route tables and latency histograms
            // group by content. Frontend 404s become `not_found`. Bounded:
            // collections and taxonomies are a fixed set. The span name
            // derives from it ("METHOD " + route), so no separate name
            // resolver is needed.
            $telemetry->resolveRouteUsing(fn ($request, $response) => Content::route($request, $response));
            $telemetry->enrichRequestsUsing(fn ($request, $response) => Content::attributes($request));
        }

        if (config('statamic-telemetry.instrument.stache', true)) {
            $telemetry->classifyCacheKeysUsing(CacheKeys::classify(...));
        }
    }

    /**
     * Roles, groups and the super flag for Statamic users — both the file
     * and eloquent drivers resolve to the Statamic user contract. Non-
     * Statamic users (a plain admin guard model) contribute nothing.
     *
     * @return array<string, scalar|null>
     */
    public static function userAttributes(mixed $user, ?string $guard): array
    {
        if (! $user instanceof StatamicUser) {
            return [];
        }

        return array_filter([
            'enduser.super' => (bool) $user->isSuper(),
            'enduser.roles' => $user->roles()->map->handle()->sort()->values()->implode(','),
            'enduser.groups' => $user->groups()->map->handle()->sort()->values()->implode(','),
        ], fn ($value) => $value !== '' && $value !== false);
    }
}
