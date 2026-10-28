<?php

declare(strict_types=1);

it('exposes only CurrentUser to other feature modules', function (): void {
    $allowed = 'Modules\\Identity\\Contracts\\CurrentUser';
    $violations = [];

    foreach (glob(dirname(__DIR__, 2).'/Modules/*', GLOB_ONLYDIR) ?: [] as $directory) {
        if (basename($directory) === 'Identity') {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/Modules\\\\Identity(?:\\\\[A-Za-z_]+)*/', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[0] as $reference) {
                if ($reference !== $allowed) {
                    $violations[] = $file->getPathname().' -> '.$reference;
                }
            }
        }
    }

    expect($violations)->toBe([]);
});
