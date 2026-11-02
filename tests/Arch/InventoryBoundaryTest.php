<?php

declare(strict_types=1);

it('exposes only Inventory Contracts DTOs and Events to other modules', function (): void {
    $violations = [];
    foreach (glob(dirname(__DIR__, 2).'/Modules/*', GLOB_ONLYDIR) ?: [] as $directory) {
        if (basename($directory) === 'Inventory') {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/Modules\\\\Inventory(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*/', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[0] as $reference) {
                if (! preg_match('/^Modules\\\\Inventory\\\\(?:Contracts|DTOs|Events)\\\\/', $reference)) {
                    $violations[] = $file->getPathname().' -> '.$reference;
                }
            }
        }
    }
    expect($violations)->toBe([]);
});

it('never imports Ordering: orders are referenced by id only', function (): void {
    $violations = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/Modules/Inventory')) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && preg_match('/Modules\\\\Ordering\b/', (string) file_get_contents($file->getPathname())) === 1) {
            $violations[] = $file->getPathname();
        }
    }
    expect($violations)->toBe([]);
});

arch('every stock strategy is final and implements StockStrategy')
    ->expect('Modules\Inventory\Strategies')
    ->toImplement('Modules\Inventory\Contracts\StockStrategy')
    ->toBeFinal();
