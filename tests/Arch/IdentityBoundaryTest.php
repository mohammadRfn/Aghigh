<?php

declare(strict_types=1);

it('exposes only CurrentUser and UserDirectory to other feature modules', function (): void {
    $allowed = ['Modules\\Identity\\Contracts\\CurrentUser', 'Modules\\Identity\\Contracts\\UserDirectory'];
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
                if (! in_array($reference, $allowed, true)) {
                    $violations[] = $file->getPathname().' -> '.$reference;
                }
            }
        }
    }

    expect($violations)->toBe([]);
});
