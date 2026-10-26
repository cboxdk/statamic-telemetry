<?php

declare(strict_types=1);

/**
 * The npm packages the compiled Control Panel assets actually bundle: the
 * runtime `dependencies` of package.json and their transitive closure,
 * resolved from package-lock.json. Dev tooling (Vite, Tailwind, the
 * `@statamic/cms` shim that resolves to the CP's own window globals) never
 * ships, so it is not listed. Shared by the license gate and the SBOM.
 *
 * @return list<array{name: string, version: string, license: string}>
 */
function npmRuntimePackages(string $root): array
{
    $packageFile = $root.'/package.json';
    $lockFile = $root.'/package-lock.json';

    if (! is_file($packageFile) || ! is_file($lockFile)) {
        return [];
    }

    /** @var array{dependencies?: array<string, string>} $package */
    $package = json_decode((string) file_get_contents($packageFile), true, 512, JSON_THROW_ON_ERROR);
    /** @var array{packages?: array<string, array{version?: string, license?: string, link?: bool, dependencies?: array<string, string>}>} $lock */
    $lock = json_decode((string) file_get_contents($lockFile), true, 512, JSON_THROW_ON_ERROR);
    $entries = $lock['packages'] ?? [];

    $queue = array_keys($package['dependencies'] ?? []);
    $seen = [];
    $out = [];

    while ($queue !== []) {
        $name = array_shift($queue);
        if (isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;

        $entry = $entries['node_modules/'.$name] ?? null;
        if ($entry === null || ($entry['link'] ?? false) === true) {
            continue; // a local `file:` link (the @statamic/cms shim) — not bundled
        }

        $out[] = [
            'name' => $name,
            'version' => (string) ($entry['version'] ?? ''),
            'license' => (string) ($entry['license'] ?? ''),
        ];

        foreach (array_keys($entry['dependencies'] ?? []) as $dependency) {
            $queue[] = $dependency;
        }
    }

    usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

    return $out;
}
