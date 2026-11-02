<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Shared\Outbox\Outbox;

uses(RefreshDatabase::class);

it('records an unprocessed row with the payload', function (): void {
    $payload = ['order_id' => 'agg-1', 'occurred_at' => '2026-01-01T00:00:00.000000Z'];

    $id = app(Outbox::class)->record('order', 'agg-1', 'OrderReserved', $payload);
    $row = DB::table('outbox')->where('id', $id)->first();

    expect($row->aggregate_type)->toBe('order')
        ->and($row->aggregate_id)->toBe('agg-1')
        ->and($row->type)->toBe('OrderReserved')
        ->and(json_decode($row->payload, true))->toBe($payload)
        ->and((int) $row->attempts)->toBe(0)
        ->and($row->processed_at)->toBeNull();
});

it('gives every row its own id', function (): void {
    $outbox = app(Outbox::class);

    expect($outbox->record('order', 'a', 'OrderPaid', []))->not->toBe($outbox->record('order', 'a', 'OrderPaid', []))
        ->and(DB::table('outbox')->count())->toBe(2);
});

it('rolls the row back with the surrounding transaction', function (): void {
    expect(fn () => DB::transaction(function (): void {
        app(Outbox::class)->record('order', 'a', 'OrderPaid', []);
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect(DB::table('outbox')->count())->toBe(0);
});
