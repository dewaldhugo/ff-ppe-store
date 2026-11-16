<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\SafetyJogger;

use Illuminate\Support\Str;

/**
 * Whether a Safety Jogger product detail is in catalogue scope: an article type
 * from `article_types` AND an EN ISO 20345 or 20347 norm. The mapper decides
 * scope with exactly this class, and the source uses it to avoid re-fetching
 * products the mapper would drop anyway, so the two cannot drift apart.
 */
final readonly class SafetyJoggerScope
{
    public const string SAFETY_NORM = 'EN ISO 20345';

    public const string OCCUPATIONAL_NORM = 'EN ISO 20347';

    /**
     * @param  array<string, mixed>  $config  The `vendorsync.safety_jogger` config.
     */
    public function __construct(private array $config) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function inScope(array $data): bool
    {
        return $this->articleType($data) !== null && $this->family(self::values($data['norms'] ?? [])) !== null;
    }

    /**
     * The first of the product's article types that is in scope, lower case.
     *
     * @param  array<string, mixed>  $data
     */
    public function articleType(array $data): ?string
    {
        foreach (self::names($data['articletype'] ?? []) as $type) {
            if (in_array($type, $this->config['article_types'] ?? [], true)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * `safety` (EN ISO 20345), `occupational` (EN ISO 20347 only) or null.
     *
     * @param  list<string>  $norms
     */
    public function family(array $norms): ?string
    {
        $contains = fn (string $norm): bool => collect($norms)->contains(fn (string $value): bool => str_contains($value, $norm));

        return match (true) {
            $contains(self::SAFETY_NORM) => 'safety',
            $contains(self::OCCUPATIONAL_NORM) => 'occupational',
            default => null,
        };
    }

    /**
     * The `name` (code) of each property.
     *
     * @return list<string>
     */
    public static function names(mixed $properties, bool $lower = true): array
    {
        $names = [];

        foreach (is_array($properties) ? $properties : [] as $property) {
            $name = trim((string) ($property['name'] ?? ''));

            if ($name !== '') {
                $names[] = $lower ? Str::lower($name) : $name;
            }
        }

        return $names;
    }

    /**
     * The `value` of each property, blanks removed.
     *
     * @return list<string>
     */
    public static function values(mixed $properties): array
    {
        $values = [];

        foreach (is_array($properties) ? $properties : [] as $property) {
            $value = trim((string) ($property['value'] ?? ''));

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }
}
