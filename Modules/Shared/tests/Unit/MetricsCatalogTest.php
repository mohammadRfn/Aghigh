<?php

declare(strict_types=1);

use Modules\Shared\Http\Controllers\MetricsController;
use Modules\Shared\Metrics\Catalog;
use Modules\Shared\Metrics\LabelValue;
use Modules\Shared\Metrics\Names;

/** @return array<string, string> constant name => metric name */
function catalogNamesConstants(): array
{
    /** @var array<string, string> $all */
    $all = (new ReflectionClass(Names::class))->getConstants();

    return $all;
}

it('declares every writable metric in the Catalog and no scrape-time metric', function (): void {
    foreach (catalogNamesConstants() as $constant => $name) {
        if (str_starts_with($constant, 'SCRAPE_')) {
            expect(Catalog::get($name))->toBeNull("{$name} is computed at scrape time and must not be writable");

            continue;
        }
        expect(Catalog::get($name))->not->toBeNull("{$constant} ({$name}) is not declared in Catalog");
    }

    expect(array_keys(Catalog::all()))->each->toBeIn(array_values(catalogNamesConstants()));
});

it('keeps the naming scheme: aghigh_ prefix, snake case, unique, _total for counters, _seconds for histograms', function (): void {
    $names = array_values(catalogNamesConstants());
    expect($names)->toBe(array_values(array_unique($names)));

    foreach ($names as $name) {
        // reverb_* is the one scrape-time metric that mirrors Reverb's own naming
        expect($name)->toMatch('/^(aghigh|reverb)_[a-z0-9]+(_[a-z0-9]+)*$/');
    }
    foreach (Catalog::all() as $definition) {
        expect($definition->name)->toStartWith('aghigh_')
            ->and($definition->help)->not->toBe('')
            ->and($definition->type)->toBeIn(['counter', 'gauge', 'histogram']);
        if ($definition->type === 'counter') {
            expect($definition->name)->toEndWith('_total');
        }
        if ($definition->type === 'histogram') {
            expect($definition->name)->toEndWith('_seconds');
        }
    }
});

it('never declares a label that can carry an unbounded value (ADR 0010)', function (): void {
    $forbidden = ['id', 'user', 'user_id', 'email', 'ip', 'url', 'path', 'uri', 'request_id', 'order_id', 'conversation_id', 'key', 'token', 'body'];

    foreach (Catalog::all() as $definition) {
        expect($definition->labelNames)->toBe(array_values(array_unique($definition->labelNames)));
        foreach ($definition->labelNames as $label) {
            expect($label)->toMatch('/^[a-z][a-z0-9_]*$/')->and($forbidden)->not->toContain($label);
        }
        expect(count($definition->labelNames))->toBeLessThanOrEqual(3);
    }
});

it('gives every histogram strictly ascending positive buckets and no other type any', function (): void {
    foreach (Catalog::all() as $definition) {
        if ($definition->type !== 'histogram') {
            expect($definition->buckets)->toBe([]);

            continue;
        }
        expect($definition->buckets)->not->toBe([]);
        $sorted = $definition->buckets;
        sort($sorted);
        expect($definition->buckets)->toBe($sorted)->and($definition->buckets)->toBe(array_values(array_unique($definition->buckets)))
            ->and($definition->buckets[0])->toBeGreaterThan(0.0);
    }
});

it('LabelValue::clean keeps values printable, short and never empty', function (): void {
    expect(LabelValue::clean(null))->toBe('none')
        ->and(LabelValue::clean(''))->toBe('none')
        ->and(LabelValue::clean('   '))->toBe('none')
        ->and(LabelValue::clean(true))->toBe('true')
        ->and(LabelValue::clean(false))->toBe('false')
        ->and(LabelValue::clean(42))->toBe('42')
        ->and(LabelValue::clean(1.5))->toBe('1.5')
        ->and(LabelValue::clean('api.v1.ordering.orders.store'))->toBe('api.v1.ordering.orders.store')
        ->and(LabelValue::clean('/api/v1/orders/{order}'))->toBe('/api/v1/orders/{order}')
        ->and(LabelValue::clean('a "quoted" value'))->toBe('a_quoted_value')
        ->and(LabelValue::clean("line1\nline2"))->toBe('line1_line2')
        ->and(LabelValue::clean('سفارش-۱'))->toBe('سفارش-۱')
        ->and(mb_strlen(LabelValue::clean(str_repeat('x', 500))))->toBe(LabelValue::MAX_LENGTH);
});

it('LabelValue::slug builds a bounded lower-case identifier', function (): void {
    expect(LabelValue::slug('Support_Agent'))->toBe('support_agent')
        ->and(LabelValue::slug('orders-ip'))->toBe('orders_ip')
        ->and(LabelValue::slug('  '))->toBe('none')
        ->and(LabelValue::slug(''))->toBe('none')
        ->and(strlen(LabelValue::slug(str_repeat('a', 100))))->toBe(40);
});

it('counts only sample lines in an exposition', function (): void {
    $body = "# HELP a x\n# TYPE a counter\na{l=\"1\"} 1\n\na{l=\"2\"} 2\n# HELP b y\nb 3\n";

    expect(MetricsController::countSamples($body))->toBe(3)
        ->and(MetricsController::countSamples(''))->toBe(0)
        ->and(MetricsController::countSamples("# only comments\r\n# TYPE x gauge\r\n"))->toBe(0);
});
