<?php

declare(strict_types=1);

namespace Modules\Chat\Exceptions;

use Modules\Shared\Contracts\ProblemException;
use Modules\Shared\Contracts\ProblemHeaders;
use RuntimeException;

final class ChatProblem extends RuntimeException implements ProblemException, ProblemHeaders
{
    /**
     * @param  array<string, mixed>  $extensions
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $message,
        private readonly int $status = 422,
        private readonly string $type = 'chat-validation',
        private readonly array $extensions = [],
        private readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('Conversation not found.', 404, 'not-found');
    }

    public static function forbidden(string $message = 'This action is not allowed for your role.'): self
    {
        return new self($message, 403, 'forbidden');
    }

    public static function closed(): self
    {
        return new self('The conversation is closed.', 409, 'conversation-closed');
    }

    public function problemStatus(): int
    {
        return $this->status;
    }

    public function problemType(): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function problemExtensions(): array
    {
        return $this->extensions;
    }

    /** @return array<string, string> */
    public function problemHeaders(): array
    {
        return $this->headers;
    }
}
