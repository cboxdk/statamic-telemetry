<?php

declare(strict_types=1);

test('the bundled dashboard can be exported for provisioning', function () {
    $directory = sys_get_temp_dir().'/statamic-telemetry-dashboards-'.uniqid();

    $this->artisan('statamic-telemetry:dashboards', ['--export' => $directory])
        ->assertSuccessful();

    $file = $directory.'/telemetry-statamic.json';

    expect($file)->toBeFile();

    $dashboard = json_decode((string) file_get_contents($file), true);

    expect($dashboard['uid'])->toBe('cbox-tel-statamic')
        ->and($dashboard['tags'])->toContain('telemetry')
        ->and($dashboard['panels'])->not->toBeEmpty();

    array_map('unlink', glob($directory.'/*') ?: []);
    rmdir($directory);
});
