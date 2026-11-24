<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Domain\Accounts\Industries;
use App\Domain\Accounts\Postcode;
use App\Domain\Accounts\VatNumber;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : $this->input('email'),
            'vat_number' => VatNumber::normalise($this->input('vat_number')),
            'address_postcode' => $this->postcode($this->input('address_postcode')),
        ]);
    }

    /**
     * Any postcode is accepted (UK and EU customers). A UK one is tidied (upper case, one space before the last three
     * characters); anything else is kept as typed, trimmed.
     */
    private function postcode(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $uk = Postcode::normalise($value);

        return $uk !== null && preg_match(Postcode::PATTERN, $uk) === 1 ? $uk : trim($value);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'company_name' => ['required', 'string', 'max:150'],
            'address_line_one' => ['required', 'string', 'max:255'],
            'address_line_two' => ['nullable', 'string', 'max:255'],
            'address_city' => ['required', 'string', 'max:100'],
            'address_county' => ['nullable', 'string', 'max:100'],
            'address_postcode' => ['required', 'string', 'max:12'],
            'industry' => ['required', 'string', Rule::in(Industries::slugs())],
            'vat_number' => ['nullable', 'string', 'max:20', new VatNumber],
            'phone' => ['nullable', 'string', 'regex:/^[0-9+() \-]{7,20}$/'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email address already exists. Log in instead.',
            'phone.regex' => 'Enter a phone number using digits, spaces, brackets, + or -.',
            'industry.in' => 'Choose the industry that fits your business best.',
            'industry.required' => 'Choose the industry that fits your business best.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'first_name' => 'first name',
            'last_name' => 'last name',
            'company_name' => 'company name',
            'address_line_one' => 'address line 1',
            'address_line_two' => 'address line 2',
            'address_city' => 'town or city',
            'address_county' => 'county',
            'address_postcode' => 'postcode',
            'vat_number' => 'VAT number',
            'email' => 'email address',
        ];
    }
}
