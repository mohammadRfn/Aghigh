<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;

/*
 | Task 11: outside `local` the Horizon dashboard opens only for requests that carry the header Traefik's admin
 | entrypoint adds (docker/traefik/prod/dynamic/routers.yml). The public entrypoint strips it and denies /horizon.
 */
it('keeps the Horizon dashboard closed without the admin edge token', function (): void {
    config(['horizon.admin_token' => 'edge-token-for-tests']);

    $this->get('/horizon')->assertForbidden();
    $this->get('/horizon', ['X-Admin-Edge-Token' => 'wrong'])->assertForbidden();
});

it('keeps the Horizon dashboard closed when no token is configured', function (): void {
    config(['horizon.admin_token' => null]);

    $this->get('/horizon', ['X-Admin-Edge-Token' => ''])->assertForbidden();
    request()->headers->set('X-Admin-Edge-Token', 'anything');
    expect(Gate::check('viewHorizon'))->toBeFalse();
});

it('accepts the token that the admin entrypoint adds', function (): void {
    config(['horizon.admin_token' => 'edge-token-for-tests']);
    request()->headers->set('X-Admin-Edge-Token', 'edge-token-for-tests');

    expect(Gate::check('viewHorizon'))->toBeTrue();
});
