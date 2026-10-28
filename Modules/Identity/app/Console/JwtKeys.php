<?php

declare(strict_types=1);

namespace Modules\Identity\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

final class JwtKeys extends Command
{
    protected $signature = 'identity:jwt-keys {--kid=k1 : A new key identifier} {--refresh-dummy : Refresh only the dummy password hash after Argon changes}';

    protected $description = 'Generate raw Ed25519 keys without printing or overwriting secrets';

    public function handle(): int
    {
        if (! extension_loaded('sodium') || ! in_array('argon2id', password_algos(), true)) {
            $this->error('Sodium and Argon2id are required. Rebuild the app image.');

            return self::FAILURE;
        }

        $kid = (string) $this->option('kid');
        if (! preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $kid)) {
            $this->error('Invalid key identifier.');

            return self::FAILURE;
        }

        $directory = storage_path('jwt');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        chmod($directory, 0700);

        if (! $this->option('refresh-dummy')) {
            if (file_exists($directory.'/'.$kid.'.private') || file_exists($directory.'/'.$kid.'.public')) {
                $this->error('Keys already exist. Use a new kid for rotation.');

                return self::FAILURE;
            }
            $pair = sodium_crypto_sign_keypair();
            $private = fopen($directory.'/'.$kid.'.private', 'x');
            $public = fopen($directory.'/'.$kid.'.public', 'x');
            if ($private === false || $public === false) {
                throw new \RuntimeException('Cannot create key files.');
            }
            chmod($directory.'/'.$kid.'.private', 0600);
            chmod($directory.'/'.$kid.'.public', 0600);
            fwrite($private, sodium_crypto_sign_secretkey($pair));
            fwrite($public, sodium_crypto_sign_publickey($pair));
            fclose($private);
            fclose($public);
            sodium_memzero($pair);
        }

        file_put_contents($directory.'/dummy.hash', Hash::make(bin2hex(random_bytes(32))), LOCK_EX);
        chmod($directory.'/dummy.hash', 0600);
        $this->info('Key material prepared in storage/jwt. Never commit this directory.');

        return self::SUCCESS;
    }
}
