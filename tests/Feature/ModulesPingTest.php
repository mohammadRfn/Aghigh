<?php

declare(strict_types=1);

it('serves the ping route of every module under /api/v1', function (string $slug): void {
    $this->getJson("/api/v1/{$slug}/ping")
        ->assertOk()
        ->assertJson(['status' => 'ok']);
})->with(['shared', 'identity', 'catalog', 'inventory', 'ordering', 'payment', 'chat', 'notification']);
