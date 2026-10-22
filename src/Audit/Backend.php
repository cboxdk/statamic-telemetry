<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Audit;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The one place that knows how to reach the logs backend — telemetryd or an
 * LGTM stack, both over the Loki HTTP API. The audit reader and the doctor
 * page share it so the URL, bearer token, tenant header and timeout are
 * resolved once, from the `cp.audit` config.
 */
final class Backend
{
    private const DEFAULT_TIMEOUT = 5;

    public function configured(): bool
    {
        return $this->url() !== null;
    }

    public function url(): ?string
    {
        $url = config('statamic-telemetry.cp.audit.url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function hasToken(): bool
    {
        return $this->token() !== null;
    }

    public function hasTenant(): bool
    {
        return $this->tenant() !== null;
    }

    /** A configured HTTP client: JSON, optional bearer token and tenant, timeout. */
    public function request(): PendingRequest
    {
        return Http::acceptJson()
            ->when($this->token(), fn (PendingRequest $http, string $token) => $http->withToken($token))
            ->when($this->tenant(), fn (PendingRequest $http, string $tenant) => $http->withHeaders(['X-Scope-OrgID' => $tenant]))
            ->timeout((int) config('statamic-telemetry.cp.audit.timeout', self::DEFAULT_TIMEOUT));
    }

    /** Join the backend URL with a Loki API path. */
    public function endpoint(string $path): string
    {
        return rtrim((string) $this->url(), '/').'/'.ltrim($path, '/');
    }

    private function token(): ?string
    {
        $token = config('statamic-telemetry.cp.audit.token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function tenant(): ?string
    {
        $tenant = config('statamic-telemetry.cp.audit.tenant');

        return is_string($tenant) && $tenant !== '' ? $tenant : null;
    }
}
