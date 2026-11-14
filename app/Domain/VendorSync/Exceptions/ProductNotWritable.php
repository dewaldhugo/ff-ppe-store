<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Exceptions;

use RuntimeException;

/**
 * The product cannot be written in contract shape (for example it has no
 * variant that may be created). Reported as an error for that product; the
 * run continues.
 */
final class ProductNotWritable extends RuntimeException {}
