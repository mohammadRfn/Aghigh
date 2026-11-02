<?php

declare(strict_types=1);

it('exposes only Ordering Contracts DTOs and Events to other modules', function (): void {
    $violations = [];
    foreach (glob(dirname(__DIR__, 2).'/Modules/*', GLOB_ONLYDIR) ?: [] as $directory) {
        if (basename($directory) === 'Ordering') {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/Modules\\\\Ordering(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*/', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[0] as $reference) {
                if (! preg_match('/^Modules\\\\Ordering\\\\(?:Contracts|DTOs|Events)\\\\/', $reference)) {
                    $violations[] = $file->getPathname().' -> '.$reference;
                }
            }
        }
    }
    expect($violations)->toBe([]);
});

it('changes order status only through the state machine', function (): void {
    $violations = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/Modules/Ordering/app')) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getFilename() === 'OrderStateMachine.php') {
            continue;
        }
        if (str_contains((string) file_get_contents($file->getPathname()), "table('orders')")) {
            $violations[] = $file->getPathname();
        }
    }
    expect($violations)->toBe([]);
});
