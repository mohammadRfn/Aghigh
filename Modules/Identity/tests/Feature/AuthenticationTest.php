<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Modules\Identity\Jobs\SendIdentityMail;
use Modules\Identity\Mail\IdentityMail;
use Modules\Identity\Models\User;
use Modules\Identity\Services\IdentityMessages;
use Tests\Support\IdentityTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    IdentityTesting::configure();
    Queue::fake();
    Mail::fake();
});

it('preserves the Identity ping', function (): void {
    $this->getJson('/api/v1/identity/ping')->assertOk()->assertExactJson(['module' => 'Identity', 'status' => 'ok']);
});

it('registers once without enumerating case-insensitive emails', function (): void {
    $data = ['name' => 'Alice', 'email' => 'Alice@example.com', 'password' => 'A-long-safe-passphrase!', 'password_confirmation' => 'A-long-safe-passphrase!'];
    $first = $this->postJson('/api/v1/auth/register', $data)->assertStatus(202);
    $second = $this->postJson('/api/v1/auth/register', [...$data, 'email' => 'alice@example.com'])->assertStatus(202);
    expect($first->json())->toBe($second->json());
    $this->assertDatabaseCount('users', 1);
    $user = User::query()->firstOrFail();
    expect($user->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7/')
        ->and($user->password)->toStartWith('$argon2id$')
        ->and($user->email_verified_at)->toBeNull();
    Queue::assertPushed(SendIdentityMail::class, 2);
    expect(DB::table('identity_mail_outbox')->pluck('purpose')->all())->toBe(['verify', 'exists']);
});

it('verifies signed links and reveals unverified status only after a correct password', function (): void {
    $user = User::factory()->unverified()->create();
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(401);
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'A-long-test-passphrase!'])->assertStatus(403)->assertJsonPath('type', config('app.url').'/problems/email-not-verified');
    $url = URL::temporarySignedRoute('api.v1.auth.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->getJson($url)->assertOk();
    expect($user->fresh()->email_verified_at)->not->toBeNull();
    $this->getJson(str_replace('signature=', 'signature=invalid', $url))->assertStatus(403);
});

it('makes unknown and incorrect login credentials indistinguishable', function (): void {
    $user = User::factory()->create();
    $known = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'incorrect'])->assertStatus(401)->assertHeader('Content-Type', 'application/problem+json');
    $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.com', 'password' => 'incorrect'])->assertStatus(401);
    foreach (['type', 'title', 'status', 'detail', 'instance'] as $field) {
        expect($known->json($field))->toBe($unknown->json($field));
    }
    // Request IDs intentionally differ. Timing requires measurement in the target container.
});

it('keeps forgot and resend responses enumeration-safe', function (string $endpoint): void {
    $user = User::factory()->unverified()->create();
    $known = $this->postJson('/api/v1/auth/'.$endpoint, ['email' => $user->email])->assertStatus(202);
    $unknown = $this->postJson('/api/v1/auth/'.$endpoint, ['email' => 'absent@example.com'])->assertStatus(202);
    expect($known->json())->toBe($unknown->json());
})->with(['forgot-password', 'email/resend']);

it('rejects invalid passwords locally', function (string $password): void {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Alice', 'email' => 'alice@example.com', 'password' => $password, 'password_confirmation' => $password,
    ])->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json');
})->with(['short', str_repeat('z', 129), 'password123456', 'some-ALICE-passphrase']);

it('logs in, rotates its cookie and logs out without exposing refresh credentials as JSON', function (): void {
    $user = User::factory()->create();
    $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'A-long-test-passphrase!'])->assertOk()->assertJsonMissingPath('refresh_token');
    $cookie = $login->getCookie('aghigh_refresh', false);
    expect($cookie->isHttpOnly())->toBeTrue()->and($cookie->getSameSite())->toBe('strict')->and($cookie->getPath())->toBe('/api/v1/auth');
    $raw = $cookie->getValue();
    expect(DB::table('refresh_tokens')->value('token_hash'))->toBe(hash('sha256', $raw))->not->toBe($raw);
    $rotated = $this->withUnencryptedCookie('aghigh_refresh', $raw)->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/auth/refresh')->assertOk()->assertJsonMissingPath('refresh_token');
    expect($rotated->getCookie('aghigh_refresh', false)->getValue())->not->toBe($raw);
    $this->withToken($rotated->json('access_token'))->getJson('/api/v1/me')->assertOk();
    $this->postJson('/api/v1/auth/logout')->assertOk();
    $this->getJson('/api/v1/me')->assertStatus(401);
});

it('requires an allowed origin for refresh and logout', function (string $endpoint, ?string $origin): void {
    if ($origin !== null) {
        $this->withHeader('Origin', $origin);
    }
    $this->postJson('/api/v1/auth/'.$endpoint)->assertStatus(403)->assertJsonPath('type', config('app.url').'/problems/origin-not-allowed');
})->with(['refresh', 'logout'])->with([null, 'null', 'https://untrusted.example']);

it('consumes password reset tokens exactly once and revokes existing access', function (): void {
    $user = User::factory()->create(['email' => 'alice@example.com']);
    $access = IdentityTesting::access($user);
    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(202);
    $reset = DB::table('password_reset_tokens')->first();
    $data = ['id' => $reset->id, 'token' => IdentityMessages::resetToken($reset->id), 'password' => 'Another-strong-phrase!42', 'password_confirmation' => 'Another-strong-phrase!42'];
    $this->postJson('/api/v1/auth/reset-password', $data)->assertOk();
    expect(Hash::check($data['password'], $user->fresh()->password))->toBeTrue();
    $this->postJson('/api/v1/auth/reset-password', $data)->assertStatus(401);
    $this->withToken($access)->getJson('/api/v1/me')->assertStatus(401);
});

it('locks accounts with a growing duration and recovers after the deadline', function (): void {
    $user = User::factory()->create();
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'incorrect'])->assertStatus(401);
    }
    expect($user->fresh()->failed_login_count)->toBe(5)->and($user->fresh()->locked_until->isFuture())->toBeTrue();
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'A-long-test-passphrase!'])->assertStatus(401);
    $this->travel(901)->seconds();
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'A-long-test-passphrase!'])->assertOk();
    expect($user->fresh()->failed_login_count)->toBe(0);
});

it('renders limiter errors as problem json with Retry-After', function (): void {
    config(['identity.rates.login' => 2]);
    $data = ['email' => 'missing@example.com', 'password' => 'wrong'];
    $this->postJson('/api/v1/auth/login', $data)->assertStatus(401);
    $this->postJson('/api/v1/auth/login', $data)->assertStatus(401);
    $this->postJson('/api/v1/auth/login', $data)->assertStatus(429)->assertHeader('Content-Type', 'application/problem+json')->assertHeader('Retry-After')->assertJsonPath('status', 429);
});

it('sends an outbox message idempotently on the default queue', function (): void {
    $user = User::factory()->unverified()->create();
    app(IdentityMessages::class)->queue($user, 'verify');
    $id = DB::table('identity_mail_outbox')->value('id');
    $job = new SendIdentityMail($id);
    expect($job->queue)->toBe('default')->and($job->afterCommit)->toBeTrue()->and($job->timeout)->toBeLessThan(90);
    $job->handle();
    $job->handle();
    Mail::assertSent(IdentityMail::class, 1);
});
