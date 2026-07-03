<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Cbox\Telemetry\Facades\Telemetry;
use Statamic\Events;

/**
 * Security-relevant auth events as one counter with a bounded, explicit
 * event label. 2FA failures and impersonation are the audit signals;
 * registrations and password changes round out the picture. No user ids
 * on the metric — the request trace carries enduser.* for that.
 */
class RecordAuthEvent extends GuardedListener
{
    private const EVENTS = [
        Events\ImpersonationStarted::class => 'impersonation_started',
        Events\ImpersonationEnded::class => 'impersonation_ended',
        Events\UserRegistered::class => 'user_registered',
        Events\UserPasswordChanged::class => 'password_changed',
        Events\TwoFactorAuthenticationEnabled::class => 'two_factor_enabled',
        Events\TwoFactorAuthenticationDisabled::class => 'two_factor_disabled',
        Events\TwoFactorAuthenticationChallenged::class => 'two_factor_challenged',
        Events\TwoFactorAuthenticationFailed::class => 'two_factor_failed',
        Events\ValidTwoFactorAuthenticationCodeProvided::class => 'two_factor_passed',
        Events\TwoFactorRecoveryCodeReplaced::class => 'two_factor_recovery_code_replaced',
    ];

    protected function handleEvent(object $event): void
    {
        if (! config('statamic-telemetry.instrument.auth', true)) {
            return;
        }

        $label = self::EVENTS[$event::class] ?? null;

        if ($label === null) {
            return;
        }

        Telemetry::counter('statamic.auth.events', 'Statamic auth and security events')
            ->inc(1, ['event' => $label]);
    }

    /**
     * @return list<class-string>
     */
    public static function events(): array
    {
        return array_keys(self::EVENTS);
    }
}
