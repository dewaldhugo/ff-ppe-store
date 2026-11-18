<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') instanceof User;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'That password is not correct.',
            'password.different' => 'Choose a password you have not used for this account just now.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'current_password' => 'current password',
            'password' => 'new password',
        ];
    }
}
