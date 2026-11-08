<?php

declare(strict_types=1);

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Task 8 / ADR 0001: Chat depends on Identity only through CurrentUser; others see only Chat Contracts/DTOs/Events. */
it('lets Chat reference Identity only through the CurrentUser contract', function (): void {
    $violations = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/Modules/Chat', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        preg_match_all('/Modules\\\\Identity(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*/', (string) file_get_contents($file->getPathname()), $matches);
        foreach ($matches[0] as $reference) {
            if ($reference !== 'Modules\\Identity\\Contracts\\CurrentUser') {
                $violations[] = $file->getPathname().' -> '.$reference;
            }
        }
    }
    expect($violations)->toBe([]);
});

it('exposes only Chat Contracts DTOs and Events to other modules', function (): void {
    $violations = [];
    foreach (glob(dirname(__DIR__, 2).'/Modules/*', GLOB_ONLYDIR) ?: [] as $directory) {
        if (basename($directory) === 'Chat') {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/Modules\\\\Chat(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*/', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[0] as $reference) {
                if (! preg_match('/^Modules\\\\Chat\\\\(?:Contracts|DTOs|Events)\\\\/', $reference)) {
                    $violations[] = $file->getPathname().' -> '.$reference;
                }
            }
        }
    }
    expect($violations)->toBe([]);
});

it('creates no foreign key from Chat tables to another module', function (): void {
    $chatTables = ['conversations'];
    $violations = [];
    foreach (glob(dirname(__DIR__, 2).'/Modules/Chat/database/migrations/*.php') ?: [] as $migration) {
        $code = (string) file_get_contents($migration);
        preg_match_all("/->(?:constrained|references|on)\\(\\s*'([a-z_]+)'/", $code, $matches);
        foreach ($matches[1] as $table) {
            if (! in_array($table, $chatTables, true)) {
                $violations[] = basename($migration).' -> '.$table;
            }
        }
        if (preg_match('/->foreign(Id|Uuid)?\\(\\s*\'(customer_id|assigned_agent_id|sender_id|user_id|agent_id)\'/', $code) === 1) {
            $violations[] = basename($migration).' -> FK on a cross-module id';
        }
    }
    expect($violations)->toBe([]);
});

it('keeps chat: Redis keys and Lua inside Modules\Chat\State', function (): void {
    $violations = [];
    $root = dirname(__DIR__, 2);
    foreach ([...glob($root.'/Modules/*/app', GLOB_ONLYDIR) ?: [], $root.'/app'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Modules/Chat/app/State/')) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match("/['\"]chat:(support-queue|presence|conn:|unread:|typing)/", $code) === 1 || preg_match('/->(evalSha|eval)\\(/', $code) === 1 && str_contains($file->getPathname(), '/Modules/Chat/')) {
                $violations[] = $file->getPathname();
            }
        }
    }
    expect($violations)->toBe([]);
});

arch('chat broadcasts are dispatched after commit')
    ->expect(['Modules\Chat\Events\MessageSent', 'Modules\Chat\Events\ConversationUpdated', 'Modules\Chat\Events\QueueUpdated'])
    ->toImplement([ShouldBroadcast::class, ShouldDispatchAfterCommit::class]);

arch('public chat events for Task 9 are dispatched after commit')
    ->expect(['Modules\Chat\Events\MessagePersisted', 'Modules\Chat\Events\ConversationClaimed', 'Modules\Chat\Events\ConversationClosed'])
    ->toImplement(ShouldDispatchAfterCommit::class);

it('never casts a user id to int in channel definitions', function (): void {
    foreach ([dirname(__DIR__, 2).'/routes/channels.php', dirname(__DIR__, 2).'/Modules/Chat/routes/channels.php'] as $file) {
        expect((string) file_get_contents($file))->not->toMatch('/\\(int\\)\\s*\\$user->id/');
    }
});
