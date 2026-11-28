#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 | Task 11: flag migrations that are unsafe for a zero-downtime deploy (docs/migration-policy.md).
 | Standalone on purpose (no framework, no database): it runs in CI before anything is built.
 |
 |   php scripts/check-migrations.php                 check every migration, exit 1 on a new violation
 |   php scripts/check-migrations.php --json          machine-readable findings
 |   php scripts/check-migrations.php --strict-down   also fail on findings inside down()
 |   php scripts/check-migrations.php path/to/x.php   check only these files
 |
 | Rules (in up(), error):
 |   drop-column            dropColumn / DROP COLUMN            contract phase only, one release after the code stopped using it
 |   rename-column          renameColumn / RENAME COLUMN        add + backfill + switch + drop instead
 |   rename-table           Schema::rename / RENAME TO
 |   change-column          ->change()                          rewrites or locks; add a new column instead
 |   drop-table             Schema::drop* / DROP TABLE          contract phase only
 |   index-not-concurrent   index on an existing large table without CREATE INDEX CONCURRENTLY
 |   fk-not-valid           foreign key on an existing large table without NOT VALID (+ later VALIDATE CONSTRAINT)
 | A table created in the same file is new and empty: indexes and constraints on it are fine.
 |
 | down() is never executed in production (a rollback redeploys the previous image, it never runs
 | migrate:rollback), so findings inside down() are printed as notes and do not fail unless --strict-down.
 |
 | Ignore marker, on the offending line or the line above it, reason mandatory:
 |   // migration-check: ignore[drop-column] contract phase of #123, column unused since release abc123
 |
 | Baseline (scripts/check-migrations-baseline.txt): migrations that were already applied before this check
 | existed. Their findings are listed as "baseline" and never fail. Do not add new migrations to it.
 */

const DEFAULT_LARGE_TABLES = [
    'users', 'refresh_tokens', 'products', 'product_images', 'categories', 'stock_items', 'stock_reservations',
    'orders', 'order_items', 'idempotency_keys', 'payments', 'payment_webhook_events', 'outbox',
    'conversations', 'messages', 'assignments', 'conversation_reads', 'jobs', 'failed_jobs',
];

$root = dirname(__DIR__);
$options = ['json' => false, 'strict-down' => false, 'baseline' => $root.'/scripts/check-migrations-baseline.txt'];
$paths = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $options['json'] = true;
    } elseif ($arg === '--strict-down') {
        $options['strict-down'] = true;
    } elseif (str_starts_with($arg, '--baseline=')) {
        $options['baseline'] = substr($arg, 11);
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "usage: php scripts/check-migrations.php [--json] [--strict-down] [--baseline=FILE] [files...]\n");
        exit(0);
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "unknown option {$arg}\n");
        exit(2);
    } else {
        $paths[] = $arg;
    }
}

$largeEnv = getenv('MIGRATION_LARGE_TABLES');
$largeTables = is_string($largeEnv) && trim($largeEnv) !== ''
    ? array_values(array_filter(array_map('trim', explode(',', $largeEnv))))
    : DEFAULT_LARGE_TABLES;

if ($paths === []) {
    $paths = array_merge(
        glob($root.'/database/migrations/*.php') ?: [],
        glob($root.'/Modules/*/database/migrations/*.php') ?: [],
    );
}
sort($paths);

$baseline = [];
if (is_file($options['baseline'])) {
    foreach (file($options['baseline'], FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '' && ! str_starts_with($line, '#')) {
            $baseline[$line] = true;
        }
    }
}

/** @return list<array{rule: string, line: int, scope: string, message: string, ignored: bool, text: string}> */
function scanMigration(string $file, array $largeTables): array
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        fwrite(STDERR, "cannot read {$file}\n");
        exit(2);
    }
    $source = implode("\n", $lines);
    preg_match_all('/Schema::create\(\s*[\'"](\w+)[\'"]/', $source, $created);
    $createdHere = array_fill_keys($created[1], true);
    $isLarge = static fn (?string $table): bool => $table !== null
        && in_array($table, $largeTables, true) && ! isset($createdHere[$table]);

    $findings = [];
    $scope = 'file';
    $tableContext = null; // table of the enclosing Schema::table(...) closure
    $add = static function (string $rule, int $index, string $message) use (&$findings, &$scope, $lines): void {
        $marker = '/migration-check:\s*ignore\[([a-z-]+(?:\s*,\s*[a-z-]+)*)\]\s*(.*)$/';
        $ignored = false;
        foreach ([$lines[$index], $lines[$index - 1] ?? ''] as $candidate) {
            if (preg_match($marker, $candidate, $m) === 1) {
                $rules = array_map('trim', explode(',', $m[1]));
                if (in_array($rule, $rules, true)) {
                    if (trim($m[2]) === '') {
                        $message .= ' (ignore marker without a reason: not accepted)';
                    } else {
                        $ignored = true;
                    }
                }
            }
        }
        $findings[] = [
            'rule' => $rule, 'line' => $index + 1, 'scope' => $scope,
            'message' => $message, 'ignored' => $ignored, 'text' => trim($lines[$index]),
        ];
    };

    foreach ($lines as $i => $line) {
        $code = preg_replace('~^\s*(//|#|\*|/\*).*$~', '', $line) ?? $line; // whole-line comments are not code
        if (preg_match('/function\s+(up|down)\s*\(/', $code, $m) === 1) {
            $scope = $m[1];
            $tableContext = null;
        }
        if (preg_match('/Schema::table\(\s*[\'"](\w+)[\'"]/', $code, $m) === 1) {
            $tableContext = $m[1];
        } elseif (preg_match('/Schema::create\(/', $code) === 1) {
            $tableContext = null;
        }

        if (preg_match('/->dropColumn\s*\(|\bDROP\s+COLUMN\b/i', $code) === 1) {
            $add('drop-column', $i, 'drops a column: the previous release still reads it during and after the deploy');
        }
        if (preg_match('/->renameColumn\s*\(|\bRENAME\s+COLUMN\b/i', $code) === 1) {
            $add('rename-column', $i, 'renames a column: old and new code cannot both work');
        }
        if (preg_match('/Schema::rename\s*\(|\bRENAME\s+TO\b/i', $code) === 1) {
            $add('rename-table', $i, 'renames a table: old and new code cannot both work');
        }
        if (preg_match('/->change\s*\(\s*\)/', $code) === 1) {
            $add('change-column', $i, 'changes a column in place: possible table rewrite under ACCESS EXCLUSIVE');
        }
        if ($scope !== 'down' && preg_match('/Schema::drop(IfExists)?\s*\(|\bDROP\s+TABLE\b/i', $code) === 1) {
            $add('drop-table', $i, 'drops a table in up(): contract phase only');
        }

        // Indexes: raw SQL without CONCURRENTLY on a large table that already exists.
        if (preg_match('/\bCREATE\s+(?:UNIQUE\s+)?INDEX\s+(?!CONCURRENTLY\b)(?:IF\s+NOT\s+EXISTS\s+)?\S+\s+ON\s+(?:ONLY\s+)?"?(\w+)"?/i', $code, $m) === 1
            && $isLarge($m[1])) {
            $add('index-not-concurrent', $i, "index on large table {$m[1]} without CONCURRENTLY: writes block while it builds");
        }
        // Indexes: schema builder inside Schema::table('<large>').
        if ($isLarge($tableContext)
            && preg_match('/->(?:index|unique|primary|fullText|spatialIndex|rawIndex)\s*\(/', $code) === 1) {
            $add('index-not-concurrent', $i, "builder index on large table {$tableContext}: use CREATE INDEX CONCURRENTLY with \$withinTransaction = false");
        }

        // Foreign keys on an existing large table must be added NOT VALID and validated separately.
        if (preg_match('/\bALTER\s+TABLE\s+(?:ONLY\s+)?"?(\w+)"?.*\bFOREIGN\s+KEY\b/i', $code, $m) === 1
            && $isLarge($m[1]) && preg_match('/\bNOT\s+VALID\b/i', $code) !== 1) {
            $add('fk-not-valid', $i, "foreign key on large table {$m[1]} without NOT VALID: full validating scan under lock");
        }
        if ($isLarge($tableContext) && preg_match('/->(?:foreign|constrained|foreignIdFor)\s*\(/', $code) === 1) {
            $add('fk-not-valid', $i, "builder foreign key on large table {$tableContext}: add it NOT VALID in raw SQL, then VALIDATE CONSTRAINT");
        }

        if (preg_match('/^\s*\}\s*\)\s*;/', $code) === 1) {
            $tableContext = null;
        }
    }

    return $findings;
}

$report = [];
$failures = 0;
foreach ($paths as $path) {
    $absolute = realpath($path);
    if ($absolute === false) {
        fwrite(STDERR, "no such file: {$path}\n");
        exit(2);
    }
    $relative = str_starts_with($absolute, $root.'/') ? substr($absolute, strlen($root) + 1) : $path;
    foreach (scanMigration($absolute, $largeTables) as $finding) {
        $status = 'error';
        if ($finding['ignored']) {
            $status = 'ignored';
        } elseif (isset($baseline[$relative])) {
            $status = 'baseline';
        } elseif ($finding['scope'] === 'down' && ! $options['strict-down']) {
            $status = 'note';
        }
        if ($status === 'error') {
            $failures++;
        }
        $report[] = ['file' => $relative, 'status' => $status] + $finding;
    }
}

if ($options['json']) {
    fwrite(STDOUT, json_encode(['files' => count($paths), 'failures' => $failures, 'findings' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
} else {
    foreach ($report as $f) {
        fwrite(STDOUT, sprintf("%-8s %s:%d [%s] in %s(): %s\n         > %s\n", strtoupper($f['status']), $f['file'], $f['line'], $f['rule'], $f['scope'], $f['message'], $f['text']));
    }
    fwrite(STDOUT, sprintf(
        "check-migrations: %d file(s), %d finding(s), %d failing. Policy: docs/migration-policy.md\n",
        count($paths), count($report), $failures,
    ));
}
exit($failures > 0 ? 1 : 0);
