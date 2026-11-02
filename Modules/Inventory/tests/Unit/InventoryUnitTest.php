<?php

declare(strict_types=1);

use Modules\Identity\Contracts\CurrentUser;
use Modules\Inventory\Enums\ReservationStatus;
use Modules\Inventory\Exceptions\InventoryProblem;
use Modules\Inventory\Exceptions\OutOfStock;
use Modules\Inventory\Policies\InventoryPolicy;
use Modules\Inventory\Strategies\AtomicStockStrategy;
use Modules\Inventory\Strategies\LockingStockStrategy;
use Modules\Inventory\Strategies\NaiveStockStrategy;

it('describes out of stock as a 409 with the product ids', function (): void {
    $problem = OutOfStock::for('p1');

    expect($problem->problemStatus())->toBe(409)
        ->and($problem->problemType())->toBe('out-of-stock')
        ->and($problem->problemExtensions())->toBe(['product_ids' => ['p1']])
        ->and($problem->getMessage())->toBe('Insufficient stock for the requested quantity.');
});

it('defaults inventory problems to a 422 validation error', function (): void {
    $problem = new InventoryProblem('Bad level.');

    expect($problem->problemStatus())->toBe(422)
        ->and($problem->problemType())->toBe('inventory-validation')
        ->and($problem->problemExtensions())->toBe([]);
});

it('lets only administrators manage inventory', function (string $role, bool $allowed): void {
    $actor = new CurrentUser('11111111-1111-7111-8111-111111111111', $role, true);
    $policy = new InventoryPolicy;

    if ($allowed) {
        $policy->authorize($actor);
        expect($allowed)->toBeTrue();

        return;
    }

    try {
        $policy->authorize($actor);
    } catch (InventoryProblem $problem) {
        expect($problem->problemStatus())->toBe(403)->and($problem->problemType())->toBe('forbidden');

        return;
    }
    $this->fail('Expected a forbidden problem.');
})->with([['admin', true], ['customer', false], ['', false], ['ADMIN', false]]);

it('names the three stock strategies', function (): void {
    expect((new AtomicStockStrategy)->name())->toBe('atomic')
        ->and((new LockingStockStrategy)->name())->toBe('locking')
        ->and((new NaiveStockStrategy)->name())->toBe('naive');
});

it('has exactly the four reservation states', function (): void {
    expect(array_map(fn (ReservationStatus $s): string => $s->value, ReservationStatus::cases()))
        ->toBe(['active', 'released', 'committed', 'expired']);
});
