<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\SafetyJogger;

/**
 * The endpoint allowlist. The API client can only build a request from one
 * of these cases, so it is impossible to call `/logout`, `DELETE /profile`,
 * orders, addresses or the shopping cart through it.
 */
enum Endpoint: string
{
    case Login = 'login';
    case ProductList = 'product';
    case ProductDetail = 'product/{id}';
    case Stock = 'stock';

    public function method(): string
    {
        return $this === self::Login ? 'POST' : 'GET';
    }

    /**
     * The path relative to the base URL. Only a positive integer can be
     * substituted, so a path can never be steered to another resource.
     */
    public function path(?int $id = null): string
    {
        if ($this !== self::ProductDetail) {
            return $this->value;
        }

        if ($id === null || $id < 1) {
            throw new \InvalidArgumentException('A product detail request needs a positive product id.');
        }

        return str_replace('{id}', (string) $id, $this->value);
    }
}
