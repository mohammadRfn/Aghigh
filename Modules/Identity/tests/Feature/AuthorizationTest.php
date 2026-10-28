<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Identity\Models\User;
use Tests\Support\IdentityTesting;

uses(RefreshDatabase::class);

beforeEach(fn () => IdentityTesting::configure());

dataset('identity roles', ['customer', 'support_agent', 'admin', 'anonymous']);

it('enforces the deliberate profile authorization matrix', function (string $role, bool $self): void {
    $actor = User::factory()->create(['role' => $role === 'anonymous' ? 'customer' : $role]);
    $target = $self ? $actor : User::factory()->create();
    if ($role !== 'anonymous') {
        $this->withToken(IdentityTesting::access($actor));
    }
    $expected = $role === 'anonymous' ? 401 : (($self || $role === 'admin') ? 200 : 403);
    $this->getJson('/api/v1/users/'.$target->id)->assertStatus($expected);
})->with('identity roles')->with([true, false]);

it('enforces the role-change authorization matrix', function (string $role): void {
    $actor = User::factory()->create(['role' => $role === 'anonymous' ? 'customer' : $role]);
    $target = User::factory()->create();
    if ($role !== 'anonymous') {
        $this->withToken(IdentityTesting::access($actor));
    }
    $expected = match ($role) { 'anonymous' => 401, 'admin' => 200, default => 403 };
    $this->patchJson('/api/v1/users/'.$target->id.'/role', ['role' => 'support_agent'])->assertStatus($expected);
    expect($target->fresh()->role->value)->toBe($role === 'admin' ? 'support_agent' : 'customer');
})->with('identity roles');

it('checks permission before target existence', function (string $role, string $method): void {
    $actor = User::factory()->create(['role' => $role === 'anonymous' ? 'customer' : $role]);
    if ($role !== 'anonymous') {
        $this->withToken(IdentityTesting::access($actor));
    }
    $path = '/api/v1/users/'.Str::uuid7().($method === 'PATCH' ? '/role' : '');
    $expected = match ($role) { 'anonymous' => 401, 'admin' => 404, default => 403 };
    $this->json($method, $path, ['role' => 'admin'])->assertStatus($expected);
})->with('identity roles')->with(['GET', 'PATCH']);
