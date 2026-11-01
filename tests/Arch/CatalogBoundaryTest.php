<?php

declare(strict_types=1);

it('exposes only Catalog Contracts DTOs and Events to other modules', function (): void {
    $violations = [];
    foreach (glob(dirname(__DIR__, 2).'/Modules/*', GLOB_ONLYDIR) ?: [] as $directory) {
        if (basename($directory) === 'Catalog') {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/Modules\\\\Catalog(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*/', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[0] as $reference) {
                if (! preg_match('/^Modules\\\\Catalog\\\\(?:Contracts|DTOs|Events)\\\\/', $reference)) {
                    $violations[] = $file->getPathname().' -> '.$reference;
                }
            }
        }
    }
    expect($violations)->toBe([]);
});
