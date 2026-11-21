<?php

declare(strict_types=1);

namespace App\Admin\Extensions;

use Illuminate\Database\Eloquent\Model;
use Lunar\Admin\Support\Extending\EditPageExtension;
use Lunar\Models\Customer;

/**
 * Loads the supplier number into the Edit Customer form and saves it on submit, in the customer's
 * meta beside the industry and the logo. The field is not a Customer attribute, so it is taken out of
 * the data before Lunar updates the customer.
 */
final class CustomerSupplierNumberEditExtension extends EditPageExtension
{
    public function beforeFill(array $data): array
    {
        $record = $this->caller?->getRecord();

        if ($record instanceof Customer) {
            $data[CustomerSupplierNumberResourceExtension::FIELD] = (string) ($record->meta[CustomerSupplierNumberResourceExtension::META_KEY] ?? '');
        }

        return $data;
    }

    public function beforeUpdate(array $data, Model $record): array
    {
        // A customer with no login has no section, so the field is missing: leave the number alone.
        if (! array_key_exists(CustomerSupplierNumberResourceExtension::FIELD, $data)) {
            return $data;
        }

        $value = trim((string) $data[CustomerSupplierNumberResourceExtension::FIELD]);
        unset($data[CustomerSupplierNumberResourceExtension::FIELD]);

        if ($record instanceof Customer) {
            $meta = (array) $record->meta;

            if ($value === '') {
                unset($meta[CustomerSupplierNumberResourceExtension::META_KEY]);
            } else {
                $meta[CustomerSupplierNumberResourceExtension::META_KEY] = $value;
            }

            $record->meta = $meta;
            // Quietly: meta is not searched, and Filament saves the record straight after.
            $record->saveQuietly();
        }

        return $data;
    }
}
