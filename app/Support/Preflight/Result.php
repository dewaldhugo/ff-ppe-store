<?php

declare(strict_types=1);

namespace App\Support\Preflight;

final readonly class Result
{
    public const string PASS = 'pass';

    public const string WARN = 'warn';

    public const string FAIL = 'fail';

    public function __construct(
        public string $check,
        public string $status,
        public string $message,
    ) {}

    public static function pass(string $check, string $message = 'ok'): self
    {
        return new self($check, self::PASS, $message);
    }

    public static function warn(string $check, string $message): self
    {
        return new self($check, self::WARN, $message);
    }

    public static function fail(string $check, string $message): self
    {
        return new self($check, self::FAIL, $message);
    }

    public function failed(): bool
    {
        return $this->status === self::FAIL;
    }
}
