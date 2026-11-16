<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\SafetyJogger;

/**
 * The few RESPONSE headers worth showing the vendor when its API answers
 * inconsistently (date, server, via, age, cache and request identifiers,
 * anything that names a region, country or currency).
 *
 * Only response headers are ever passed in. A deny list wins over the allow
 * list, so a header that could carry a credential is never kept even if its
 * name looks harmless. Values are cut short.
 */
final class ResponseHeaders
{
    private const array EXACT = [
        'date', 'server', 'via', 'age', 'cache-control', 'etag', 'expires', 'last-modified', 'vary', 'retry-after',
        'cf-ray', 'cf-cache-status', 'age', 'x-cache', 'x-cache-hits', 'x-served-by',
    ];

    /** Header name prefixes. */
    private const array PREFIXES = ['x-cache', 'x-request', 'x-correlation', 'x-trace', 'x-served', 'x-amz', 'x-azure', 'x-envoy', 'cf-'];

    /** Fragments anywhere in the header name. */
    private const array FRAGMENTS = ['region', 'currency', 'country', 'locale', 'geo', 'pop', 'node', 'cache', 'request-id', 'requestid'];

    private const array DENIED_FRAGMENTS = ['authorization', 'cookie', 'secret', 'token', 'password', 'passwd', 'key', 'username', 'credential', 'session', 'auth'];

    private const int MAX_VALUE = 200;

    /**
     * @param  array<array-key, mixed>  $headers  Response headers, name => string|list<string>.
     * @return array<string, string> Lower case name => value, sorted by name.
     */
    public static function allowed(array $headers): array
    {
        $kept = [];

        foreach ($headers as $name => $value) {
            $name = strtolower(trim((string) $name));

            if ($name === '' || ! self::isAllowed($name)) {
                continue;
            }

            $value = is_array($value) ? implode(', ', array_map(strval(...), $value)) : (string) $value;
            $kept[$name] = mb_substr(trim($value), 0, self::MAX_VALUE);
        }

        ksort($kept);

        return $kept;
    }

    public static function isAllowed(string $name): bool
    {
        foreach (self::DENIED_FRAGMENTS as $fragment) {
            if (str_contains($name, $fragment)) {
                return false;
            }
        }

        if (in_array($name, self::EXACT, true)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        foreach (self::FRAGMENTS as $fragment) {
            if (str_contains($name, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
