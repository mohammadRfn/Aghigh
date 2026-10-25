<?php

declare(strict_types=1);

// Modules may talk to each other only through Contracts, Events and DTOs.
// Everything else (models, HTTP layer, actions, jobs, ...) is private.

$modules = array_map(
    'basename',
    glob(dirname(__DIR__, 2).'/Modules/*', GLOB_ONLYDIR) ?: [],
);

$kernel = 'Shared';
$featureModules = array_values(array_diff($modules, [$kernel]));
$privateLayers = ['Models', 'Http', 'Actions', 'Jobs', 'Listeners', 'Policies', 'Providers', 'Exceptions'];

foreach ($featureModules as $module) {
    $forbidden = [];

    foreach (array_diff($featureModules, [$module]) as $other) {
        foreach ($privateLayers as $layer) {
            $forbidden[] = "Modules\\{$other}\\{$layer}";
        }
    }

    if ($forbidden === []) {
        continue;
    }

    arch("{$module} does not reach into other modules' internals")
        ->expect("Modules\\{$module}")
        ->not->toUse($forbidden);
}

if (in_array($kernel, $modules, true) && $featureModules !== []) {
    arch('Shared kernel does not depend on feature modules')
        ->expect("Modules\\{$kernel}")
        ->not->toUse(array_map(fn (string $m): string => "Modules\\{$m}", $featureModules));
}

arch('all application code declares strict types')
    ->expect(['App', 'Modules'])
    ->toUseStrictTypes();
