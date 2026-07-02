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
class RecordFormSubmission
{
    public function handle(SubmissionCreated $event): void
    {
        if (! config('statamic-telemetry.instrument.forms', true)) {
            return;
        }

        try {
            $form = $event->submission->form()->handle();
        } catch (Throwable) {
            $form = 'unknown';
        }

        Telemetry::counter('statamic.forms.submissions', 'Form submissions created')
            ->inc(1, ['form' => $form]);
    }
}
