<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Audit;

/**
 * The outcome of one audit-log read: the page of rows to show, the facet
 * option lists for the filters, and whether anything went wrong or was cut
 * short. A backend error never throws into the CP — it arrives here as a
 * message, with an empty page, so the view always renders.
 */
final class AuditResult
{
    /**
     * @param  list<AuditRow>  $rows  the current page, newest first
     * @param  list<string>  $types  every type seen in the window (facet)
     * @param  list<string>  $actions  every action seen in the window (facet)
     * @param  list<string>  $sites  every site seen in the window (facet)
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $types,
        public readonly array $actions,
        public readonly array $sites,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly bool $capped,
        public readonly ?string $error = null,
    ) {}

    public static function failed(string $error): self
    {
        return new self([], [], [], [], 0, 1, 0, false, $error);
    }

    public function totalPages(): int
    {
        return $this->perPage > 0 ? max(1, (int) ceil($this->total / $this->perPage)) : 1;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
