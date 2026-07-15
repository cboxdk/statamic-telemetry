<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Cbox\Telemetry\Facades\Telemetry;
use Statamic\Events\SubmissionCreated;
use Throwable;

/**
 * Counts created submissions. Listens to SubmissionCreated rather than
 * FormSubmitted — the latter is a halting event where any non-null
 * listener return cancels the submission.
 */
class RecordFormSubmission extends GuardedListener
{
    protected function handleEvent(object $event): void
    {
        if (! $event instanceof SubmissionCreated || ! config('statamic-telemetry.instrument.forms', true)) {
            return;
        }

        // Degrade to 'unknown' rather than dropping the count — a
        // submission still happened even if the form handle is unreadable.
        try {
            $form = $event->submission->form()->handle();
        } catch (Throwable) {
            $form = 'unknown';
        }

        Telemetry::counter('statamic.forms.submissions', 'Form submissions created')
            ->inc(1, ['form' => $form]);

        // A child span so each submission is an individual, drillable row
        // correlated to the request it arrived on.
        Telemetry::tracer()->recordSpan('statamic.forms.submit', 0.0, [
            'statamic.form' => $form,
        ]);
    }
}
