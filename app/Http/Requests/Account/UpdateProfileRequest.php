<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Domain\Accounts\VatNumber;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') instanceof User;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : $this->input('email'),
            'vat_number' => VatNumber::normalise($this->input('vat_number')),
        ]);
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
            'vat_number' => ['nullable', 'string', 'max:20', new VatNumber],
            'phone' => ['nullable', 'string', 'regex:/^[0-9+() \-]{7,20}$/'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($this->user('web')?->getKey())],
            // Changing the email address changes how the customer signs in, so it needs the password.
            'confirm_password' => Rule::when($this->emailChanged(), ['required', 'string', 'current_password:web'], ['nullable']),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email address already exists.',
            'phone.regex' => 'Enter a phone number using digits, spaces, brackets, + or -.',
            'confirm_password.required' => 'Enter your current password to change your email address.',
            'confirm_password.current_password' => 'That password is not correct.',
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
            'vat_number' => 'VAT number',
            'email' => 'email address',
            'confirm_password' => 'current password',
        ];
    }

    private function emailChanged(): bool
    {
        $current = $this->user('web')?->email;
        $new = $this->input('email');

        return is_string($new) && is_string($current) && mb_strtolower($new) !== mb_strtolower($current);
    }
}
