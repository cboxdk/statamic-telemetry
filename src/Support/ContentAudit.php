<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Support;

use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User as StatamicUser;
use Statamic\Entries\Entry;
use Statamic\Events;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Sites\Site as StatamicSite;
use Statamic\Statamic;
use Throwable;
use WeakMap;

/**
 * Turns a Statamic content event into one audit record: what changed (type,
 * id, title, handle, collection, site, url, edit link, the fields that
 * moved), how (created / updated / published / unpublished / deleted /
 * uploaded / …) and who (the signed-in user, from where).
 *
 * The counter next to it stays bounded — type × action. This is the row
 * behind it, sent as a structured `statamic.content.change` event to the
 * logs backend, where cardinality is free and a per-item history belongs.
 *
 * Statamic fires several events for one save — `EntryCreated` then
 * `EntrySaved`, `AssetCreated`/`AssetSaved`/`AssetUploaded` for one upload —
 * and re-saves descendants and localisations as a side effect. The record
 * keeps to what an editor did: a `*Created` marks the object and its
 * `*Saved` becomes the "created" row, and an entry saved only because
 * another one was (Statamic's own `isInitial()`) is not a row. Publish
 * transitions and changed fields come from Statamic's dirty state, which
 * still holds the pre-save values when the saved event fires.
 */
final class ContentAudit
{
    public const EVENT = 'statamic.content.change';

    /** Long values are cut here; an audit row is a summary, not a copy. */
    private const MAX_VALUE = 200;

    private const MAX_FIELDS = 25;

    /** Objects a `*Created` event announced: their next save is the creation. @var WeakMap<object, bool> */
    private WeakMap $created;

    /** Objects already recorded as created, so a trailing `AssetUploaded` is not a second row. @var WeakMap<object, bool> */
    private WeakMap $recordedCreated;

    /** What a site save changed, for the record that follows. @var WeakMap<object, list<string>> */
    private WeakMap $siteChanges;

    /**
     * The sites as they were before the CP saved them, by handle, and until
     * when that holds. Statamic saves every site at once and fires
     * `SiteSaved` for each, with no dirty state to tell which one changed.
     *
     * @var array{at: float, sites: array<string, array<array-key, mixed>>}|null
     */
    private ?array $sitesBefore = null;

    /**
     * The sites of the last save recorded without a snapshot, and when:
     * every `SiteSaved` of that one batch after the first is not a row.
     *
     * @var WeakMap<object, float>
     */
    private WeakMap $blindBatch;

    public function __construct()
    {
        $this->created = new WeakMap;
        $this->recordedCreated = new WeakMap;
        $this->siteChanges = new WeakMap;
        $this->blindBatch = new WeakMap;
    }

    /**
     * Remember the sites before a CP save of them — called when the route
     * that saves them matches, before its controller runs.
     */
    public function snapshotSites(): void
    {
        $sites = [];

        foreach (Site::all() as $site) {
            $sites[(string) $site->handle()] = (array) $site->rawConfig();
        }

        $this->sitesBefore = ['at' => microtime(true), 'sites' => $sites];
    }

    /**
     * Note a `*Created` event. Returns true when it was one (the caller
     * records nothing for it — the save that follows is the row).
     */
    public function noteCreated(object $event): bool
    {
        if (! str_ends_with(class_basename($event), 'Created') || $event instanceof Events\SubmissionCreated) {
            return false;
        }

        $subject = self::subject($event);

        if ($subject !== null) {
            $this->created[$subject] = true;
        }

        return true;
    }

    /**
     * The audit record for a content event, or null when it is not one worth
     * a row (a no-op save, a working-copy cleanup, the upload of an asset
     * whose creation is already recorded).
     *
     * @param  string  $type  the counter's type label (entry, asset, nav_tree, …)
     * @param  string  $action  the counter's action label (saved, deleted, uploaded, …)
     * @return array<string, scalar>|null
     */
    public function record(object $event, string $type, string $action): ?array
    {
        $subject = self::subject($event);

        if ($subject === null) {
            return null;
        }

        // A term save fires TermSaved (with the dirty state) and then
        // LocalizedTermSaved for the same change — the first one is the row.
        if ($event instanceof Events\LocalizedTermSaved || $event instanceof Events\LocalizedTermDeleted) {
            return null;
        }

        $action = $this->action($event, $subject, $action);

        if ($action === null) {
            return null;
        }

        $attributes = [
            'statamic.content.type' => $type === 'localized_term' ? 'term' : $type,
            'statamic.content.action' => $action,
            ...self::describe($subject, $event),
            ...self::origin(),
            ...self::actor(),
        ];

        if ($action === 'updated' && config('statamic-telemetry.audit.changed_fields', true)) {
            $changed = $this->siteChanges[$subject] ?? self::changedFields($subject);

            if ($changed !== []) {
                $attributes['statamic.content.changed'] = self::cut(implode(', ', $changed));
            }
        }

        /** @var array<string, scalar> */
        return array_filter($attributes, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function action(object $event, object $subject, string $action): ?string
    {
        if ($event instanceof Events\RevisionSaved || $event instanceof Events\RevisionDeleted) {
            // Deleting the working copy is Statamic tidying up after a publish.
            if ($event instanceof Events\RevisionDeleted) {
                return null;
            }

            return self::call($subject, 'action') ?? 'revision';
        }

        if ($event instanceof Events\AssetUploaded && isset($this->recordedCreated[$subject])) {
            return null;
        }

        // Every `*Saved` is decided here — the counter's label for an entry
        // save is its status snapshot, not what the save did.
        if (! str_ends_with(class_basename($event), 'Saved')) {
            return $action;
        }

        // An origin entry re-saving its localisations, a new entry
        // propagating to the other sites: side effects of the save that is
        // the row.
        if ($event instanceof Events\EntrySaved) {
            try {
                if (! $event->isInitial()) {
                    return null;
                }
            } catch (Throwable) {
                // No initiator: treat it as the edit.
            }
        }

        if (isset($this->created[$subject])) {
            unset($this->created[$subject]);
            $this->recordedCreated[$subject] = true;

            return 'created';
        }

        if ($subject instanceof StatamicSite) {
            return $this->siteAction($subject);
        }

        if ($subject instanceof Entry) {
            $was = $subject->getOriginal('published');
            $is = $subject->published();

            if ($was === false && $is === true) {
                return 'published';
            }

            if ($was === true && $is === false) {
                return 'unpublished';
            }
        }

        // With a cache store that hands back the cached instance, Statamic's
        // own lookup at the start of save() syncs the original away before
        // any event fires — then nothing looks changed. "saved" says exactly
        // that much; an edit is never dropped for it.
        return self::changedFields($subject) === [] ? 'saved' : 'updated';
    }

    /**
     * One `SiteSaved` per site per save: with a snapshot from before the CP
     * save, only the sites that changed are rows, naming what changed; a
     * save made from code (no snapshot) is one row for the whole batch.
     */
    private function siteAction(StatamicSite $site): ?string
    {
        $snapshot = $this->sitesBefore !== null && microtime(true) - $this->sitesBefore['at'] < 60
            ? $this->sitesBefore['sites']
            : null;

        if ($snapshot === null) {
            // Statamic dispatches the batch in one loop over its current
            // site objects: the first of them is the row, the rest are that
            // same save.
            if (isset($this->blindBatch[$site]) && microtime(true) - $this->blindBatch[$site] < 1.0) {
                return null;
            }

            $now = microtime(true);
            foreach (Site::all() as $member) {
                $this->blindBatch[$member] = $now;
            }
            // This one is recorded: its next `SiteSaved` is a new save.
            unset($this->blindBatch[$site]);

            return 'saved';
        }

        $before = $snapshot[(string) $site->handle()] ?? null;

        if ($before === null) {
            return 'created';
        }

        $now = (array) $site->rawConfig();
        $changed = [];

        foreach (array_unique([...array_keys($now), ...array_keys($before)]) as $key) {
            if (json_encode($now[$key] ?? null) !== json_encode($before[$key] ?? null)) {
                $changed[] = (string) $key;
            }
        }

        if ($changed === []) {
            return null;
        }

        $this->siteChanges[$site] = array_slice($changed, 0, self::MAX_FIELDS);

        return 'updated';
    }

    /**
     * The event's content object — the one it was constructed with (`$entry`,
     * `$asset`, `$tree`, `$revision`, …), not the `authenticatedUser` or
     * `initiator` every Statamic event also carries. AssetReplaced carries
     * the new asset.
     */
    public static function subject(object $event): ?object
    {
        if ($event instanceof Events\AssetReplaced) {
            return is_object($event->newAsset) ? $event->newAsset : null;
        }

        $constructor = (new \ReflectionClass($event))->getConstructor();

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $value = $event->{$parameter->getName()} ?? null;

            if (is_object($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function describe(object $subject, object $event): array
    {
        $collection = self::handleOf(self::call($subject, 'collection'));
        $taxonomy = self::call($subject, 'taxonomyHandle') ?? self::handleOf(self::call($subject, 'taxonomy'));
        $container = self::call($subject, 'containerHandle') ?? self::handleOf(self::call($subject, 'container'));
        $globalSet = self::handleOf(self::call($subject, 'globalSet'));
        $form = self::handleOf(self::call($subject, 'form'));
        // A term's title, url and edit link live on its localisations.
        $view = self::call($subject, 'inDefaultLocale');
        $view = is_object($view) ? $view : $subject;

        $attributes = [
            'statamic.content.id' => self::scalar(self::call($subject, 'id')),
            'statamic.content.handle' => self::scalar(self::call($subject, 'handle')) ?? $globalSet,
            'statamic.content.title' => self::title($view),
            'statamic.collection' => $collection,
            'statamic.taxonomy' => is_string($taxonomy) ? $taxonomy : null,
            'statamic.asset.container' => is_string($container) ? $container : null,
            'statamic.form' => $form,
            'statamic.blueprint' => self::blueprint($subject),
            'statamic.site' => self::scalar(self::call($subject, 'locale')),
            'statamic.content.path' => self::path($subject),
            'statamic.content.url' => self::scalar(self::call($view, 'uri')),
            'statamic.content.edit_url' => self::editUrl($view),
        ];

        if ($subject instanceof StatamicSite) {
            $attributes['statamic.site'] = self::scalar($subject->handle());
            $attributes['statamic.site.locale'] = self::scalar($subject->locale());
            $attributes['statamic.content.url'] = self::scalar(self::call($subject, 'url'));
        }

        if ($subject instanceof Entry) {
            $attributes['statamic.content.status'] = self::scalar(self::call($subject, 'status'));
            $attributes['statamic.content.slug'] = self::scalar(self::call($subject, 'slug'));
        }

        if ($event instanceof Events\RevisionSaved) {
            // `collections/pages/en/<entry id>` — the entry the revision is of.
            $key = self::scalar(self::call($subject, 'key'));
            $attributes['statamic.content.id'] = is_string($key) ? Str::afterLast($key, '/') : null;
            $attributes['statamic.revision.key'] = $key;
            $attributes['statamic.revision.message'] = self::cut(self::scalar(self::call($subject, 'message')));
        }

        if ($event instanceof Events\AssetUploaded && is_string($event->originalFilename ?? null)) {
            $attributes['statamic.asset.original_filename'] = self::cut($event->originalFilename);
        }

        return $attributes;
    }

    /**
     * Where the change came from: the control panel, the REST/GraphQL API, a
     * console command (a deploy, `please`, a queued job) or the site itself.
     *
     * @return array<string, string>
     */
    private static function origin(): array
    {
        if (app()->runningInConsole()) {
            $command = $_SERVER['argv'][1] ?? null;

            return array_filter([
                'statamic.content.origin' => 'console',
                'process.command' => is_string($command) ? self::cut($command) : null,
            ], static fn (?string $value): bool => $value !== null);
        }

        $origin = 'web';

        try {
            if (Statamic::isCpRoute()) {
                $origin = 'cp';
            } elseif (Statamic::isApiRoute()) {
                $origin = 'api';
            }
        } catch (Throwable) {
            // Outside a routed request: keep "web".
        }

        return ['statamic.content.origin' => $origin];
    }

    /**
     * Who: the signed-in user's id (the base package's `user.id`) and, for
     * an audit trail a person can read, their name — on by default here
     * because these are the site's own editors, and switchable off with
     * `statamic-telemetry.audit.actor_name`. Never the email.
     *
     * @return array<string, string>
     */
    private static function actor(): array
    {
        try {
            $user = User::current();
        } catch (Throwable) {
            return [];
        }

        if (! $user instanceof StatamicUser) {
            return [];
        }

        $id = self::scalar(self::call($user, 'id'));
        $name = config('statamic-telemetry.audit.actor_name', true) ? self::scalar(self::call($user, 'name')) : null;
        // A "name" that is an email address (some sites store it that way)
        // stays out, like the email itself.
        $name = is_string($name) && str_contains($name, '@') ? null : $name;

        return array_filter([
            'user.id' => is_scalar($id) ? (string) $id : null,
            'user.name' => is_scalar($name) ? self::cut((string) $name) : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * The top-level fields a save changed, from Statamic's own dirty state.
     *
     * @return list<string>
     */
    private static function changedFields(object $subject): array
    {
        if (! method_exists($subject, 'getCurrentDirtyStateAttributes') || ! method_exists($subject, 'getOriginal')) {
            return [];
        }

        try {
            /** @var array<string, mixed> $now */
            $now = $subject->getCurrentDirtyStateAttributes();
            /** @var array<string, mixed> $before */
            $before = (array) $subject->getOriginal();
        } catch (Throwable) {
            return [];
        }

        // Assets keep their fields under `data`.
        if (isset($now['data']) && is_array($now['data'])) {
            $nowData = $now['data'];
            $beforeData = is_array($before['data'] ?? null) ? $before['data'] : [];
            unset($now['data'], $before['data']);
            $now = [...$now, ...$nowData];
            $before = [...$before, ...$beforeData];
        }

        $changed = [];

        foreach (array_unique([...array_keys($now), ...array_keys($before)]) as $key) {
            if (in_array($key, ['updated_at', 'updated_by'], true)) {
                continue;
            }

            if (json_encode($now[$key] ?? null) !== json_encode($before[$key] ?? null)) {
                $changed[] = (string) $key;
            }
        }

        return array_slice($changed, 0, self::MAX_FIELDS);
    }

    private static function title(object $subject): ?string
    {
        // A user is known by their name here, never by their email — which
        // is what Statamic's User::title() falls back to.
        if ($subject instanceof StatamicUser) {
            $name = self::scalar(self::call($subject, 'name'));

            return is_string($name) && $name !== '' && ! str_contains($name, '@') ? self::cut($name) : null;
        }

        foreach (['title', 'name', 'basename'] as $method) {
            $value = self::scalar(self::call($subject, $method));

            if (is_string($value) && $value !== '') {
                return self::cut($value);
            }
        }

        // Entries without a title field, global variables, trees.
        $value = self::scalar(self::call($subject, 'get', 'title'));

        return is_string($value) && $value !== '' ? self::cut($value) : null;
    }

    private static function blueprint(object $subject): ?string
    {
        if (str_ends_with($subject::class, 'Blueprint')) {
            $namespace = self::scalar(self::call($subject, 'namespace'));

            return is_string($namespace) && $namespace !== '' ? $namespace : null;
        }

        return self::handleOf(self::call($subject, 'blueprint'));
    }

    private static function path(object $subject): ?string
    {
        // Assets and folders are known by their path; entries' file paths
        // are storage detail, not what an editor recognises.
        if (! str_contains($subject::class, 'Asset')) {
            return null;
        }

        $path = self::scalar(self::call($subject, 'path'));

        return is_string($path) ? self::cut($path) : null;
    }

    private static function editUrl(object $subject): ?string
    {
        $url = self::scalar(self::call($subject, 'editUrl'));

        if (! is_string($url) || $url === '') {
            return null;
        }

        return str_starts_with($url, 'http') ? $url : url($url);
    }

    private static function handleOf(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_object($value)) {
            $handle = self::scalar(self::call($value, 'handle'));

            return is_string($handle) ? $handle : null;
        }

        return null;
    }

    /**
     * Call a getter if the object has it; a throwing or missing one is null.
     * Statamic's fluent accessors are getters when called without arguments.
     */
    private static function call(object $subject, string $method, mixed ...$arguments): mixed
    {
        if (! method_exists($subject, $method)) {
            return null;
        }

        try {
            return $subject->{$method}(...$arguments);
        } catch (Throwable) {
            return null;
        }
    }

    private static function scalar(mixed $value): string|int|float|bool|null
    {
        if (is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return null;
    }

    private static function cut(string|int|float|bool|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Str::limit((string) $value, self::MAX_VALUE, '…');
    }
}
