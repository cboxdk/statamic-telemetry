#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Deterministic CycloneDX 1.5 SBOM generator.
 *
 * Emits sbom.json from composer.lock (runtime packages only), composer.json
 * (root component) and the npm lock (the JavaScript bundled into the
 * compiled Control Panel assets — ECharts and what it pulls in). Self-
 * contained: no composer plugins, no network, no vendor/ or node_modules/.
 *
 * Determinism: no timestamps, no random UUIDs. The serialNumber is a UUID
 * formatted from the sha256 of the canonical components JSON, so
 * regenerating from the same lock files is byte-identical — which is what
 * lets CI fail on a stale sbom.json.
 */
$root = dirname(__DIR__);
$lockFile = $root.'/composer.lock';
$composerFile = $root.'/composer.json';
$outFile = $root.'/sbom.json';

foreach ([$lockFile, $composerFile] as $required) {
    if (! is_file($required)) {
        fwrite(STDERR, basename($required)." not found at {$required}\n");
        exit(1);
    }
}

require __DIR__.'/npm-runtime.php';

/** @var array{packages?: list<array<string, mixed>>} $lock */
$lock = json_decode((string) file_get_contents($lockFile), true, 512, JSON_THROW_ON_ERROR);

/** @var array{name?: string, description?: string, license?: string|list<string>} $composer */
$composer = json_decode((string) file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);

/**
 * @param  list<string>  $licenses
 * @return list<array{license: array{id: string}}>
 */
function licenseEntries(array $licenses): array
{
    $entries = [];
    foreach ($licenses as $license) {
        $entries[] = ['license' => ['id' => $license]];
    }

    return $entries;
}

$components = [];

foreach ($lock['packages'] ?? [] as $package) {
    $name = (string) ($package['name'] ?? '');
    $version = (string) ($package['version'] ?? '');
    if ($name === '' || $version === '') {
        continue;
    }

    $purl = "pkg:composer/{$name}@{$version}";

    $component = [
        'type' => 'library',
        'bom-ref' => $purl,
        'name' => $name,
        'version' => $version,
        'purl' => $purl,
    ];

    $licenses = array_map('strval', (array) ($package['license'] ?? []));
    if ($licenses !== []) {
        $component['licenses'] = licenseEntries($licenses);
    }

    $description = (string) ($package['description'] ?? '');
    if ($description !== '') {
        $component['description'] = $description;
    }

    $components[$purl] = $component;
}

// The compiled CP assets (resources/dist) ship in the package too, with the
// npm runtime dependencies bundled in.
foreach (npmRuntimePackages($root) as $npm) {
    if ($npm['version'] === '') {
        continue;
    }

    $purl = 'pkg:npm/'.str_replace('@', '%40', $npm['name']).'@'.$npm['version'];
    $component = [
        'type' => 'library',
        'bom-ref' => $purl,
        'name' => $npm['name'],
        'version' => $npm['version'],
        'purl' => $purl,
    ];
    if ($npm['license'] !== '') {
        $component['licenses'] = licenseEntries([$npm['license']]);
    }

    $components[$purl] = $component;
}

ksort($components, SORT_STRING);
$components = array_values($components);

$rootName = (string) ($composer['name'] ?? 'cboxdk/statamic-telemetry');
$rootComponent = [
    'type' => 'library',
    'bom-ref' => "pkg:composer/{$rootName}",
    'name' => $rootName,
];

$rootDescription = (string) ($composer['description'] ?? '');
if ($rootDescription !== '') {
    $rootComponent['description'] = $rootDescription;
}

$rootLicenses = array_map('strval', (array) ($composer['license'] ?? []));
if ($rootLicenses !== []) {
    $rootComponent['licenses'] = licenseEntries($rootLicenses);
}

// Derive a stable serialNumber from the canonical components JSON so the
// output is deterministic for a given set of lock files.
$canonical = json_encode(
    ['metadata' => $rootComponent, 'components' => $components],
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
);
$hash = hash('sha256', $canonical);
$serialNumber = sprintf(
    'urn:uuid:%s-%s-%s-%s-%s',
    substr($hash, 0, 8),
    substr($hash, 8, 4),
    substr($hash, 12, 4),
    substr($hash, 16, 4),
    substr($hash, 20, 12),
);

$bom = [
    'bomFormat' => 'CycloneDX',
    'specVersion' => '1.5',
    'serialNumber' => $serialNumber,
    'version' => 1,
    'metadata' => [
        'component' => $rootComponent,
    ],
    'components' => $components,
];

$json = json_encode($bom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

file_put_contents($outFile, $json);

echo sprintf("Wrote %s (%d components + root).\n", basename($outFile), count($components));
exit(0);
