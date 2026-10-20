<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Tests\Integration\WithTelemetryUiTestCase;
use Cbox\StatamicTelemetry\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');
uses(WithTelemetryUiTestCase::class)->in('Integration');
