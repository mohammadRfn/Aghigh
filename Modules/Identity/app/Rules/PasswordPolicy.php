<?php

declare(strict_types=1);

namespace Modules\Identity\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class PasswordPolicy implements ValidationRule
{
    public function __construct(private string $email) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || mb_strlen($value) < 12 || mb_strlen($value) > 128) {
            $fail('Use a password between 12 and 128 characters.');

            return;
        }
        $password = mb_strtolower($value);
        $local = mb_strtolower(explode('@', $this->email, 2)[0]);
        $denied = file(module_path('Identity', 'resources/common-passwords.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($denied === false) {
            throw new \RuntimeException('Password policy unavailable.');
        }
        if (in_array($password, $denied, true) || ($local !== '' && str_contains($password, $local))) {
            $fail('Choose a less common password that does not contain your email name.');
        }
    }
}
