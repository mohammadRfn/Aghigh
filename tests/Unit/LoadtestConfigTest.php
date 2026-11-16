<?php

declare(strict_types=1);

/*
 | Task 10: static checks on the load-test wiring (plain PHP, no application boot).
 | The point of most of them is production safety: a stray .env.loadtest on a server must never be able to switch
 | the app to APP_ENV=loadtest, so only the overlay compose.loadtest.yaml may reference it.
 */

function loadtestRepoFile(string $path): string
{
    $full = dirname(__DIR__, 2).'/'.$path;

    return is_file($full) ? (string) file_get_contents($full) : '';
}

it('keeps every loadtest setting out of the base and production compose files', function (): void {
    foreach (['compose.yaml', 'compose.prod.yml', 'compose.prod.yaml'] as $file) {
        $compose = loadtestRepoFile($file);
        if ($compose === '') {
            continue; // compose.prod.yml arrives with Task 11; the rule already applies to it
        }
        expect($compose)->not->toContain('.env.loadtest')
            ->and($compose)->not->toContain('APP_ENV: loadtest')
            ->and($compose)->not->toContain('compose.loadtest');
    }
    expect(loadtestRepoFile('compose.yaml'))->not->toBe('');
});

it('loads .env.loadtest only from the overlay, and only as an optional file', function (): void {
    $overlay = loadtestRepoFile('compose.loadtest.yaml');

    expect($overlay)->toContain('path: .env.loadtest')
        ->and($overlay)->toContain('required: false')
        ->and(substr_count($overlay, 'required: false'))->toBe(substr_count($overlay, 'path: .env.loadtest'));
});

it('uses the overlay only in the loadtest make targets', function (): void {
    $makefile = loadtestRepoFile('Makefile');

    expect($makefile)->toContain('LT := $(DC) -f compose.yaml -f compose.loadtest.yaml');
    foreach (preg_split('/\R/', $makefile) ?: [] as $line) {
        if (str_starts_with($line, 'DC :=')) {
            expect($line)->not->toContain('loadtest');
        }
    }
});

it('ignores the loadtest env files and results in git and in the image', function (): void {
    $git = loadtestRepoFile('.gitignore');
    $docker = loadtestRepoFile('.dockerignore');

    expect($git)->toContain('.env.loadtest')
        ->and($git)->toContain('loadtests/results/*')
        ->and($git)->toContain('!loadtests/results/.gitkeep')
        ->and($docker)->toContain('.env.loadtest')
        ->and($docker)->toContain('loadtests/results')
        ->and(loadtestRepoFile('.env.loadtest.example'))->toContain('APP_ENV=loadtest');
});

it('mounts k6 scripts read-only, results writable, pins the image and binds Traefik to loopback by default', function (): void {
    $compose = loadtestRepoFile('compose.yaml');

    expect($compose)->toContain('./loadtests:/scripts:ro')
        ->and($compose)->toContain('./loadtests/results:/results')
        ->and($compose)->toContain('${HTTP_BIND:-127.0.0.1}:${HTTP_PORT:-8000}:80')
        ->and($compose)->toMatch('/\$\{K6_IMAGE:-grafana\/k6:\d+\.\d+\.\d+\}/');
});

it('labels every threshold entry provisional or baseline, and a baseline names its date and commit', function (): void {
    $config = json_decode(loadtestRepoFile('loadtests/config/thresholds.json'), true, 512, JSON_THROW_ON_ERROR);
    $entries = [];
    foreach ($config['scenarios'] as $name => $scenario) {
        $entries[$name] = $scenario;
        foreach ($scenario['variants'] ?? [] as $variant => $override) {
            if (isset($override['status'])) {
                $entries[$name.'/'.$variant] = $override + ['baseline' => $scenario['baseline']];
            }
        }
    }

    expect(array_keys($config['scenarios']))->toEqualCanonicalizing(['catalog', 'order-flood', 'spike', 'soak', 'websocket-chat', 'auth-abuse', 'metrics-overhead']);
    foreach ($entries as $name => $entry) {
        expect($entry['status'])->toBeIn(['provisional', 'baseline'], $name)
            ->and($entry['baseline'])->toHaveKeys(['date', 'commit'], $name);
        if ($entry['status'] === 'baseline') {
            expect($entry['baseline']['date'])->toMatch('/^\d{4}-\d{2}-\d{2}$/', $name)
                ->and($entry['baseline']['commit'])->toMatch('/^[0-9a-f]{7,40}$/', $name);
        }
    }
});
