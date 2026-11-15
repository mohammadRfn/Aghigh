<?php

declare(strict_types=1);

use Modules\Shared\Metrics\Catalog;
use Modules\Shared\Metrics\Names;

/*
 | Static checks of the Task 9 stack (dashboards, alert rules, Prometheus, compose, Traefik, env examples).
 | They need no Docker and no running stack: they catch a dashboard or alert that queries a metric the app no longer
 | exports, a mistyped label, an unpinned image, a published port on 0.0.0.0 or a default secret, in CI, before
 | `make obs-verify` ever runs. They do NOT prove the PromQL returns data: that is what make obs-verify is for.
 */

function obsRoot(): string
{
    return dirname(__DIR__, 2);
}

function obsRead(string $relative): string
{
    $path = obsRoot().'/'.$relative;
    expect(is_file($path))->toBeTrue("missing file: {$relative}");

    return (string) file_get_contents($path);
}

/** @return array<string, list<string>> metric name => allowed label names */
function obsKnownMetrics(): array
{
    $known = [];
    foreach (Catalog::all() as $definition) {
        $known[$definition->name] = $definition->labelNames;
    }
    foreach ([Names::SCRAPE_QUEUE_PENDING, Names::SCRAPE_QUEUE_DELAYED, Names::SCRAPE_QUEUE_RESERVED, Names::SCRAPE_QUEUE_OLDEST_AGE, Names::SCRAPE_QUEUE_ESTIMATED_WAIT] as $name) {
        $known[$name] = ['queue'];
    }
    foreach ([Names::SCRAPE_QUEUE_FAILED_STORED, Names::SCRAPE_QUEUE_FAILED_RECENT, Names::SCRAPE_REVERB_CONNECTIONS, Names::SCRAPE_REVERB_UP, Names::SCRAPE_BACKEND_UP, Names::SCRAPE_SERIES, Names::SCRAPE_ENABLED] as $name) {
        $known[$name] = [];
    }

    return $known;
}

/** @return list<string> problems found in one PromQL expression */
function obsExprProblems(string $expr): array
{
    $known = obsKnownMetrics();
    $problems = [];
    preg_match_all('/\b((?:aghigh|reverb)_[a-z0-9_]+)\b(\{[^}]*\})?/', $expr, $hits, PREG_SET_ORDER);

    foreach ($hits as $hit) {
        $name = $hit[1];
        $base = $name;
        foreach (['_bucket', '_sum', '_count'] as $suffix) {
            $stripped = str_ends_with($name, $suffix) ? substr($name, 0, -strlen($suffix)) : null;
            if ($stripped !== null && ! isset($known[$name]) && Catalog::get($stripped)?->type === 'histogram') {
                $base = $stripped;
            }
        }
        if (! isset($known[$base])) {
            $problems[] = "unknown metric {$name}";

            continue;
        }
        preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)\s*(?:=~|!~|!=|=)/', $hit[2] ?? '', $labels);
        foreach ($labels[1] as $label) {
            if (! in_array($label, [...$known[$base], 'le', 'job', 'instance', 'stack'], true)) {
                $problems[] = "{$base}: undeclared label {$label}";
            }
        }
    }

    return $problems;
}

/** @return list<string> every PromQL expression of every dashboard (panels, rows, template variables) */
function obsDashboardExprs(): array
{
    $exprs = [];
    $walk = function (mixed $node) use (&$walk, &$exprs): void {
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if (in_array($key, ['expr', 'query', 'definition'], true) && is_string($value)) {
                $exprs[] = $value;
            }
            $walk($value);
        }
    };
    foreach (glob(obsRoot().'/docker/observability/grafana/dashboards/*.json') ?: [] as $file) {
        $walk(json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR));
    }

    return $exprs;
}

/** @return list<array{name: string, text: string}> */
function obsAlertBlocks(): array
{
    $blocks = [];
    $current = null;
    foreach (preg_split('/\R/', obsRead('docker/observability/prometheus/rules/aghigh.rules.yml')) ?: [] as $line) {
        if (preg_match('/^\s*-\s*alert:\s*(\S+)\s*$/', $line, $m) === 1) {
            if ($current !== null) {
                $blocks[] = $current;
            }
            $current = ['name' => $m[1], 'text' => ''];

            continue;
        }
        if ($current !== null) {
            $current['text'] .= $line."\n";
        }
    }
    if ($current !== null) {
        $blocks[] = $current;
    }

    return $blocks;
}

it('ships the six dashboards as valid JSON with unique uids, a request_id variable and queries', function (): void {
    $uids = [];
    foreach (['api', 'db', 'orders', 'queues', 'redis', 'websocket'] as $name) {
        $dashboard = json_decode(obsRead("docker/observability/grafana/dashboards/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);

        expect($dashboard['uid'] ?? '')->toBeString()->not->toBe('')
            ->and($dashboard['title'] ?? '')->not->toBe('')
            ->and(array_column($dashboard['templating']['list'] ?? [], 'name'))->toContain('request_id');
        $uids[] = $dashboard['uid'];
    }

    expect($uids)->toBe(array_values(array_unique($uids)))->and(obsDashboardExprs())->not->toBe([]);
});

it('only queries app metrics and labels that the app really exports (dashboards)', function (): void {
    foreach (obsDashboardExprs() as $expr) {
        expect(obsExprProblems($expr))->toBe([], "dashboard query: {$expr}");
    }
});

it('only alerts on app metrics and labels that the app really exports (rules)', function (): void {
    foreach (obsAlertBlocks() as $block) {
        preg_match('/^\s*expr:\s*(.+)$/m', $block['text'], $m);
        expect($m[1] ?? '')->not->toBe('')->and($m[1] ?? '')->not->toMatch('/^[|>]/', "{$block['name']}: expr must stay on ONE line (scripts/obs-query-check.php)")
            ->and(obsExprProblems($m[1] ?? ''))->toBe([], "{$block['name']}: {$m[1]}");
    }
});

it('gives every alert a unique name, a severity, a summary and a runbook that exists in docs/observability.md', function (): void {
    $blocks = obsAlertBlocks();
    $docs = obsRead('docs/observability.md');
    $names = array_column($blocks, 'name');

    expect($blocks)->not->toBe([])->and($names)->toBe(array_values(array_unique($names)));

    foreach ($blocks as $block) {
        expect($block['text'])->toMatch('/severity:\s*(critical|warning|info)\b/', "{$block['name']}: severity")
            ->and($block['text'])->toMatch('/summary:\s*".{10,}"/', "{$block['name']}: summary")
            ->and($block['text'])->toContain('runbook: "docs/observability.md#'.strtolower($block['name']).'"')
            ->and($docs)->toContain('**'.strtolower($block['name']).'**');
    }
});

it('only unit-tests alerts that exist', function (): void {
    $defined = array_column(obsAlertBlocks(), 'name');
    preg_match_all('/alertname:\s*(\w+)/', obsRead('docker/observability/prometheus/rules/aghigh.rules.test.yml'), $m);

    expect($m[1])->not->toBe([]);
    foreach (array_unique($m[1]) as $name) {
        expect($defined)->toContain($name);
    }
});

it('scrapes the app with a bearer token read from a file, never an inline credential', function (): void {
    $prometheus = obsRead('docker/observability/prometheus/prometheus.yml');

    expect($prometheus)->toContain('metrics_path: /internal/metrics')
        ->and($prometheus)->toContain('credentials_file: /etc/prometheus/secrets/metrics_token')
        ->and($prometheus)->not->toMatch('/^\s*credentials:/m')
        ->and($prometheus)->toContain('/etc/prometheus/rules/aghigh.rules.yml');
});

it('denies /internal at the edge with a higher-priority router than the app router', function (): void {
    $traefik = obsRead('docker/traefik/dynamic.yml');

    expect($traefik)->toContain('internal-deny:')->and($traefik)->toContain('PathPrefix(`/internal`)')
        ->and($traefik)->toMatch('/internal-deny:.*?priority:\s*100/s')->and($traefik)->toContain('deny-all')
        ->and($traefik)->toContain('192.0.2.1/32'); // TEST-NET-1: nothing real can match, so the allow-list denies everyone
});

/**
 * compose.yaml services (name => body text). A `<<: *anchor` merge pulls in the text of the top-level `x-...: &anchor`
 * block, because that is where the GlitchTip image, profile, restart and logging live. Line based on purpose: no
 * YAML parser is a project dependency, and a regex over the whole file silently skips services after a comment.
 *
 * @return array<string, string>
 */
function obsComposeServices(): array
{
    $anchors = [];
    $services = [];
    $topKey = null;
    $anchor = null;
    $current = null;

    foreach (preg_split('/\R/', obsRead('compose.yaml')) ?: [] as $line) {
        if ($line !== '' && $line[0] !== ' ' && $line[0] !== '#') {
            $topKey = strtok($line, ':');
            $current = null;
            $anchor = preg_match('/&([\w-]+)/', $line, $m) === 1 ? $m[1] : null;
            if ($anchor !== null) {
                $anchors[$anchor] = '';
            }

            continue;
        }
        if ($topKey === 'services' && preg_match('/^  ([a-z0-9][a-z0-9-]*):\s*$/', $line, $m) === 1) {
            $current = $m[1];
            $services[$current] = '';

            continue;
        }
        if ($topKey === 'services' && $current !== null) {
            $services[$current] .= $line."\n";
        } elseif ($anchor !== null) {
            $anchors[$anchor] .= $line."\n";
        }
    }
    foreach ($services as $name => $body) {
        if (preg_match('/<<:\s*\*([\w-]+)/', $body, $m) === 1) {
            $services[$name] = $body."\n".($anchors[$m[1]] ?? '');
        }
    }

    return $services;
}

it('pins every observability image, publishes only on loopback and sets limits, restart and logging', function (): void {
    $checked = [];
    $missing = [];
    foreach (obsComposeServices() as $name => $body) {
        if (! str_contains($body, 'profiles: [observability]')) {
            continue;
        }
        $checked[] = $name;

        preg_match('/^\s+image:\s*(.+)$/m', $body, $image);
        $default = preg_match_all('/\$\{[A-Z_]+:-([^}]*)\}/', $image[1] ?? '', $defaults) > 0 ? end($defaults[1]) : ($image[1] ?? '');
        expect($default)->toMatch('~^[\w./-]+(:[\w.-]+|@sha256:[0-9a-f]{64})$~', "{$name}: image must carry an exact tag or digest")
            ->and($default)->not->toEndWith(':latest');

        foreach (['mem_limit:', 'restart:', 'logging: *logging'] as $needle) {
            if (! str_contains($body, $needle)) {
                $missing[] = "{$name}: {$needle}";
            }
        }

        if (preg_match('/^\s+ports:\R((?:\s+- .*\R)+)/m', $body."\n", $ports) === 1) {
            foreach (preg_split('/\R/', trim($ports[1])) ?: [] as $port) {
                expect(trim($port))->toStartWith('- "127.0.0.1:', "{$name}: published ports must bind to loopback only");
            }
        }
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (str_contains($line, '/var/run/docker.sock')) {
                expect(trim($line))->toMatch('/:ro"?$/', "{$name}: the Docker socket must be mounted read-only");
            }
        }
    }

    expect($missing)->toBe([]);
    expect($checked)->toContain('prometheus', 'grafana', 'loki', 'alloy', 'glitchtip', 'glitchtip-worker', 'glitchtip-migrate')
        ->and(count($checked))->toBeGreaterThanOrEqual(13);
});

it('ships no default secret in the env examples', function (): void {
    $value = function (string $file, string $key): ?string {
        return preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', obsRead($file), $m) === 1 ? trim($m[1]) : null;
    };

    foreach (['.env.example', '.env.docker.example'] as $file) {
        expect($value($file, 'METRICS_ENABLED'))->not->toBeNull("{$file}: METRICS_ENABLED")
            ->and($value($file, 'METRICS_DRIVER'))->toBe('redis')
            ->and($value($file, 'REDIS_METRICS_DB'))->not->toBeNull()
            ->and($value($file, 'METRICS_TOKEN'))->toBe('', "{$file}: METRICS_TOKEN must be generated (make obs-keys), never defaulted")
            ->and($value($file, 'SENTRY_LARAVEL_DSN'))->toBe('');
    }
    foreach (['GRAFANA_ADMIN_PASSWORD', 'GLITCHTIP_SECRET_KEY', 'GLITCHTIP_DB_PASSWORD', 'DB_MONITOR_PASSWORD'] as $key) {
        expect($value('.env.docker.example', $key))->toBe('', ".env.docker.example: {$key} must be generated, never defaulted");
    }
});

it('keeps the metrics token and local env files out of git', function (): void {
    $root = obsRoot();
    if (! is_dir($root.'/.git') || ! function_exists('exec')) {
        $this->markTestSkipped('not a git checkout');
    }
    exec('git -C '.escapeshellarg($root).' ls-files -- .env .env.docker docker/observability/secrets 2>&1', $tracked, $code);
    if ($code !== 0) {
        $this->markTestSkipped('git not available');
    }

    expect($tracked)->toBe(['docker/observability/secrets/.gitignore']);
});
