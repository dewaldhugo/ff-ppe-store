<?php

declare(strict_types=1);

namespace App\Domain\Cart;

use RuntimeException;

/**
 * A cart change Lunar refused (not enough stock, item no longer on sale), with a
 * message that is safe to show the customer as it is.
 */
final class CartRejected extends RuntimeException {}
