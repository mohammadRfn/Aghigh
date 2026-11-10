<?php

declare(strict_types=1);

/*
 | Task 9 (runs INSIDE the app container: it needs PHP and the backend network, where Prometheus lives).
 | Extracts every PromQL query from the provisioned dashboards and the alert rules, runs each one against
 | Prometheus (instant query, now) and prints the empty ones. Empty is only allowed for queries matched by
 | docker/observability/verify-allowlist.txt (regex | reason). Loki panels are skipped (LogQL, not PromQL).
 | Exit 1 when a non-allowlisted query is empty, when a query errors, or when nothing could be extracted.
 */

$root = dirname(__DIR__);
$prometheus = getenv('PROMETHEUS_URL') ?: 'http://prometheus:9090';
$dashboards = glob($root.'/docker/observability/grafana/dashboards/*.json') ?: [];
$rules = glob($root.'/docker/observability/prometheus/rules/*.rules.yml') ?: [];
$allowlistFile = $root.'/docker/observability/verify-allowlist.txt';

/** @var array<int, array{regex: string, reason: string}> $allowlist */
$allowlist = [];
foreach (file($allowlistFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#')) {
        continue;
    }
    [$regex, $reason] = array_pad(array_map('trim', explode(' | ', $line, 2)), 2, '(no reason given)');
    $allowlist[] = ['regex' => $regex, 'reason' => $reason];
}

/** @var array<string, string> $queries expr => origin */
$queries = [];
foreach ($dashboards as $file) {
    $json = json_decode((string) file_get_contents($file), true);
    if (! is_array($json)) {
        fwrite(STDERR, "cannot parse {$file}\n");
        exit(1);
    }
    $walk = function (array $panels) use (&$walk, &$queries, $file): void {
        foreach ($panels as $panel) {
            foreach ($panel['panels'] ?? [] as $nested) {
                $walk([$nested]);
            }
            $datasource = $panel['datasource']['uid'] ?? 'prometheus';
            foreach ($panel['targets'] ?? [] as $target) {
                $uid = $target['datasource']['uid'] ?? $datasource;
                if ($uid !== 'prometheus' || ! isset($target['expr'])) {
                    continue;
                }
                $queries[(string) $target['expr']] = basename($file).' / '.($panel['title'] ?? '?');
            }
        }
    };
    $walk($json['panels'] ?? []);
}
foreach ($rules as $file) {
    foreach (file($file) ?: [] as $line) {
        if (preg_match('/^\s*expr:\s*(.+?)\s*$/', $line, $m) === 1) {
            $queries[trim($m[1], '"\'')] = basename($file);
        }
    }
}
if ($queries === []) {
    fwrite(STDERR, "no queries extracted\n");
    exit(1);
}

$substitute = static fn (string $expr): string => str_replace(
    ['$__rate_interval', '$__interval', '${request_id}', '$request_id'],
    ['5m', '1m', 'none', 'none'],
    $expr,
);

$empty = [];
$errors = [];
$ok = 0;
foreach ($queries as $expr => $origin) {
    $run = $substitute($expr);
    // Alert rules end in a threshold (`> 0.8`, `== 0`): empty then means "not firing", the healthy state.
    // Check the series behind the threshold instead, so a missing metric still fails.
    if (str_ends_with($origin, '.rules.yml')) {
        $run = preg_replace('/\s*(==|!=|>=|<=|>|<)\s*-?[0-9.]+\s*$/', '', $run) ?? $run;
    }
    $url = $prometheus.'/api/v1/query?query='.rawurlencode($run);
    $context = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $context);
    $body = is_string($raw) ? json_decode($raw, true) : null;
    if (! is_array($body) || ($body['status'] ?? '') !== 'success') {
        $errors[] = sprintf("%s\n    %s\n    -> %s", $origin, $expr, is_array($body) ? ($body['error'] ?? 'error') : 'no answer from '.$prometheus);

        continue;
    }
    $result = $body['data']['result'] ?? [];
    if ($result === []) {
        $empty[] = [$expr, $origin];
    } else {
        $ok++;
    }
}

printf("queries: %d total, %d non-empty, %d empty, %d errors\n", count($queries), $ok, count($empty), count($errors));
$failed = false;
foreach ($empty as [$expr, $origin]) {
    $reason = null;
    foreach ($allowlist as $entry) {
        if (@preg_match('/'.$entry['regex'].'/', $expr) === 1) {
            $reason = $entry['reason'];
            break;
        }
    }
    if ($reason !== null) {
        printf("  EMPTY (allowed: %s)\n    %s\n    %s\n", $reason, $origin, $expr);
    } else {
        $failed = true;
        printf("  EMPTY (NOT allowed)\n    %s\n    %s\n", $origin, $expr);
    }
}
foreach ($errors as $error) {
    $failed = true;
    echo "  ERROR {$error}\n";
}
exit($failed ? 1 : 0);
