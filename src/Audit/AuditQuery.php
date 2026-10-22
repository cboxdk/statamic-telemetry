<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Audit;

use Illuminate\Http\Request;

/**
 * The filters an editor picked on the audit page, normalised: the type,
 * action and site facets, a free-text needle, the look-back window and the
 * page. Built from the request's query string, so a shared URL reproduces the
 * exact view.
 */
final class AuditQuery
{
    /** Windows offered in the UI, in the order shown. Value → label. */
    public const WINDOWS = [
        '1h' => 'Last hour',
        '24h' => 'Last 24 hours',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
    ];

    public function __construct(
        public readonly string $type = '',
        public readonly string $action = '',
        public readonly string $site = '',
        public readonly string $search = '',
        public readonly string $window = '24h',
        public readonly int $page = 1,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $default = (string) config('statamic-telemetry.cp.audit.window', '24h');
        $default = isset(self::WINDOWS[$default]) ? $default : '24h';
        $window = (string) $request->query('window', $default);

        return new self(
            type: trim((string) $request->query('type', '')),
            action: trim((string) $request->query('action', '')),
            site: trim((string) $request->query('site', '')),
            search: trim((string) $request->query('q', '')),
            window: isset(self::WINDOWS[$window]) ? $window : $default,
            page: max(1, (int) $request->query('page', 1)),
        );
    }

    /** The look-back window as seconds. */
    public function windowSeconds(): int
    {
        if (preg_match('/^(\d+)([mhd])$/', $this->window, $m) !== 1) {
            return 86_400;
        }

        return (int) $m[1] * match ($m[2]) {
            'm' => 60,
            'h' => 3_600,
            'd' => 86_400,
        };
    }

    public function hasFilters(): bool
    {
        return $this->type !== '' || $this->action !== '' || $this->site !== '' || $this->search !== '';
    }

    /** Does a row pass every active facet and the free-text search? */
    public function matches(AuditRow $row): bool
    {
        return ($this->type === '' || $row->type === $this->type)
            && ($this->action === '' || $row->action === $this->action)
            && ($this->site === '' || $row->site === $this->site)
            && $row->matches(mb_strtolower($this->search));
    }
}
