<?php

declare(strict_types=1);

it('exposes only CurrentUser to other feature modules', function (): void {
    $root = dirname(__DIR__, 2).'/Modules';
    foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
        if (basename($directory) === 'Identity') {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            preg_match_all('/Modules\\\\Identity\\\\[A-Za-z_\\\\]+/', $source, $matches);
            foreach ($matches[0] as $reference) {
                expect($reference)->toBe('Modules\\Identity\\Contracts\\CurrentUser');
            }
        }
    }
});
