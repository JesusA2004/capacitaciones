<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class RegistrarPushTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'in:ios,android,web'],
            'device_name' => ['nullable', 'string', 'max:150'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ];
    }
}
