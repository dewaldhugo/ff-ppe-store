<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A UK VAT number: format only. It is not checked against HMRC.
 *
 * Accepts an optional GB or XI prefix, then 9 digits, 12 digits (branch traders),
 * GD and 3 digits (government departments) or HA and 3 digits (health authorities).
 */
final class VatNumber implements ValidationRule
{
    private const string PATTERN = '/^(?:GB|XI)?(?:\d{9}|\d{12}|GD\d{3}|HA\d{3})$/';

    /**
     * Strips spaces and hyphens and upper-cases the value, so "gb 123 4567 82" is stored as "GB123456782".
     * An empty value becomes null.
     */
    public static function normalise(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = strtoupper((string) preg_replace('/[\s\-]+/', '', $value));

        return $clean === '' ? null : $clean;
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail('Enter a valid UK VAT number, for example GB123456789, or leave it blank.');
        }
    }
}
