<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : $this->input('email')]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email', 'max:255']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['email' => 'email address'];
    }
}
