<?php

declare(strict_types=1);

namespace App\Admin\Extensions;

use App\Domain\Accounts\CustomerLogo;
use Illuminate\Database\Eloquent\Model;
use Lunar\Admin\Support\Extending\EditPageExtension;
use Lunar\Models\Customer;

/**
 * Loads the company logo into the Edit Customer form and saves it on submit. The field is not a
 * Customer attribute, so it is taken out of the data before Lunar updates the customer.
 */
final class CustomerLogoEditExtension extends EditPageExtension
{
    public function beforeFill(array $data): array
    {
        $record = $this->caller?->getRecord();

        if ($record instanceof Customer) {
            $data[CustomerLogoResourceExtension::FIELD] = app(CustomerLogo::class)->path($record);
        }

        return $data;
    }

    public function beforeUpdate(array $data, Model $record): array
    {
        // A customer with no login has no section, so the field is missing: leave the logo alone.
        if (! array_key_exists(CustomerLogoResourceExtension::FIELD, $data)) {
            return $data;
        }

        $state = $data[CustomerLogoResourceExtension::FIELD];
        unset($data[CustomerLogoResourceExtension::FIELD]);

        if ($record instanceof Customer) {
            // A single upload gives a path; guard against a list all the same.
            $path = is_array($state) ? (array_values($state)[0] ?? null) : $state;

            app(CustomerLogo::class)->set($record, is_string($path) ? $path : null);
        }

        return $data;
    }
}
