<?php

declare(strict_types=1);

namespace App\Domain\Checkout;

use RuntimeException;

/**
 * The order could not be placed, with a message that is safe to show the customer as it is.
 */
final class CheckoutRejected extends RuntimeException {}
