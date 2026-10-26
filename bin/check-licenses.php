#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Dependency license gate.
 *
 * Reads composer.lock (runtime AND dev packages) and the npm lock for the
 * JavaScript the addon bundles into resources/dist, and fails when a
 * package does not declare at least one permissive license. Self-contained:
 * no composer plugins, no network, no vendor/ or node_modules/ required.
 *
 * A package with multiple licenses passes if ANY of them is permissive
 * (composer.lock stores the license field as an array; multiple entries
 * follow SPDX dual-licensing OR semantics).
 *
 * Weak-copyleft licenses (e.g. MPL-2.0, LGPL) are intentionally NOT on the
 * allowlist — they require an explicit entry in $exceptions with a
 * justification.
 */
$allowlist = [
    'MIT',
    'BSD-2-Clause',
    'BSD-3-Clause',
    'BSD-4-Clause',
    'Apache-2.0',
    'ISC',
    '0BSD',
    'Unlicense',
    'CC0-1.0',
    'WTFPL',
];

/**
 * Explicitly excepted packages: 'vendor/pkg' (or an npm name) => 'justification'.
 * Use this for weak-copyleft (MPL-2.0, LGPL-*) or unusual licenses after
 * review — never silently extend the allowlist.
 *
 * @var array<string, string> $exceptions
 */
$exceptions = [
    // The host CMS this addon extends. It is a peer dependency of every
    // Statamic site (licensed by the site under Statamic's own terms) — the
    // addon never redistributes it.
    'statamic/cms' => 'host CMS, peer dependency of the installing site; not redistributed',
    // Pulled in by statamic/cms for asset metadata; tri-licensed, and the
    // MPL-2.0 option is a file-level weak copyleft that an unmodified
    // dependency does not propagate. Not something this addon itself uses.
    'james-heinrich/getid3' => 'transitive via statamic/cms; MPL-2.0 option, unmodified, not used by the addon',
];

$root = dirname(__DIR__);

/**
 * @param  list<string>  $licenses
 */
$permissive = static function (array $licenses) use ($allowlist): bool {
    $allowed = array_flip(array_map('strtoupper', $allowlist));
    foreach ($licenses as $license) {
        // npm lock files use SPDX expressions ("MIT OR Apache-2.0"); any permissive alternative passes.
        foreach (preg_split('/\s+OR\s+/i', trim($license, '() ')) ?: [] as $alternative) {
            if (isset($allowed[strtoupper(trim($alternative))])) {
                return true;
            }
        }
    }

    return false;
};

/** @var list<array{name: string, licenses: list<string>}> $packages */
$packages = [];

// Composer — runtime and dev.
$lockFile = $root.'/composer.lock';
if (! is_file($lockFile)) {
    fwrite(STDERR, "composer.lock not found at {$lockFile}\n");
    exit(1);
}
/** @var array{packages?: list<array<string, mixed>>, "packages-dev"?: list<array<string, mixed>>} $lock */
$lock = json_decode((string) file_get_contents($lockFile), true, 512, JSON_THROW_ON_ERROR);
foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
    $name = (string) ($package['name'] ?? '');
    if ($name !== '') {
        $packages[] = ['name' => $name, 'licenses' => array_map('strval', (array) ($package['license'] ?? []))];
    }
}

// npm — only what the compiled CP assets bundle (the runtime dependencies of
// package.json and their transitive closure); dev tooling never ships.
require __DIR__.'/npm-runtime.php';
foreach (npmRuntimePackages($root) as $npm) {
    $packages[] = ['name' => 'npm:'.$npm['name'], 'licenses' => $npm['license'] !== '' ? [$npm['license']] : []];
}

$offenders = [];
$excepted = [];
$checked = 0;

foreach ($packages as $package) {
    $checked++;

    if ($permissive($package['licenses'])) {
        continue;
    }

    $declared = implode(' OR ', $package['licenses']) ?: 'no license declared';

    if (isset($exceptions[$package['name']])) {
        $excepted[] = sprintf('%s [%s] — exception: %s', $package['name'], $declared, $exceptions[$package['name']]);

        continue;
    }

    $offenders[] = sprintf('%s [%s]', $package['name'], $declared);
}

foreach ($excepted as $line) {
    echo "EXCEPTION: {$line}\n";
}

if ($offenders !== []) {
    fwrite(STDERR, "Non-permissive licenses found:\n");
    foreach ($offenders as $offender) {
        fwrite(STDERR, "  - {$offender}\n");
    }
    fwrite(STDERR, sprintf(
        "\n%d offender(s) out of %d package(s) checked. Add an entry to \$exceptions in bin/check-licenses.php with a justification, or remove the dependency.\n",
        count($offenders),
        $checked,
    ));
    exit(1);
}

echo sprintf("License check passed: %d packages checked (%d exception(s)).\n", $checked, count($excepted));
exit(0);
