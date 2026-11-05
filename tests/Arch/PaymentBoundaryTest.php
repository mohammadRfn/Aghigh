<?php

declare(strict_types=1);

it('exposes only Payment Contracts DTOs and Events to other modules', function (): void {
    $violations = [];
    foreach (glob(dirname(__DIR__, 2).'/Modules/*', GLOB_ONLYDIR) ?: [] as $directory) {
        if (basename($directory) === 'Payment') {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/tests/')) {
                continue;
            }
            preg_match_all('/Modules\\\\Payment(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*/', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[0] as $reference) {
                if (! preg_match('/^Modules\\\\Payment\\\\(?:Contracts|DTOs|Events)\\\\/', $reference)) {
                    $violations[] = $file->getPathname().' -> '.$reference;
                }
            }
        }
    }
    expect($violations)->toBe([]);
});

it('never imports Ordering from Payment: orders are referenced by id only', function (): void {
    $violations = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/Modules/Payment')) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'Modules\\Ordering')) {
            $violations[] = $file->getPathname();
        }
    }
    expect($violations)->toBe([]);
});
