<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Domain\Accounts\Postcode;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A saved delivery address (My Account, Addresses). UK addresses only, like checkout.
 */
final class AddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') instanceof User;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'postcode' => Postcode::normalise($this->input('postcode')) ?? $this->input('postcode'),
            'make_default' => $this->boolean('make_default'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:60'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'line_one' => ['required', 'string', 'max:150'],
            'line_two' => ['nullable', 'string', 'max:150'],
            'city' => ['required', 'string', 'max:100'],
            'county' => ['nullable', 'string', 'max:100'],
            'postcode' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if (preg_match(Postcode::PATTERN, (string) $value) !== 1) {
                    $fail('Enter a valid UK postcode.');
                }
            }],
            'phone' => ['nullable', 'string', 'regex:/^[0-9+() \-]{7,20}$/'],
            'delivery_instructions' => ['nullable', 'string', 'max:500'],
            'make_default' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['phone.regex' => 'Enter a phone number using digits, spaces, brackets, + or -.'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'label' => 'name for this address',
            'first_name' => 'first name',
            'last_name' => 'last name',
            'company_name' => 'company name',
            'line_one' => 'address line 1',
            'line_two' => 'address line 2',
            'city' => 'town or city',
            'delivery_instructions' => 'delivery instructions',
        ];
    }
}
