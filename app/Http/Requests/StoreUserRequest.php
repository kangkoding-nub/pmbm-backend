<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Only Administrator (1) and Operator (2) may create users. Route
     * middleware also enforces this; this is defense-in-depth.
     */
    public function authorize(): bool
    {
        $actor = $this->user('sanctum');
        if (!$actor) {
            return false;
        }

        $actorRole = (int) $actor->role;
        if (!in_array($actorRole, [1, 2], true)) {
            return false;
        }

        // Only Administrator (1) may mint another Administrator. Operator
        // (2) and below cannot create or promote users to role 1.
        $targetRole = (int) $this->input('role');
        if ($actorRole !== 1 && $targetRole === 1) {
            return false;
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'institutionId' => ['nullable', 'int', 'exists:institutions,id'],
            'name' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => ['required', 'string'],
            // Whitelist allowed role IDs to prevent privilege escalation via
            // arbitrary integers.
            //   1 = Administrator
            //   2 = Operator
            //   3 = Bendahara
            //   4 = Pendaftar
            //   5 = Operator Pondok
            //   6 = Teller
            'role' => ['required', 'int', Rule::in([1, 2, 3, 4, 5, 6])],
        ];
    }

    public function attributes(): array
    {
        return [
            'institutionId' => 'ID Lembaga',
            'name' => 'Nama Pengguna',
            'email' => 'Alamat Email',
            'password' => 'Kata Sandi',
            'phone' => 'No. Telepon',
            'role' => 'Hak Akses',
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge([
            'phone_verified_at' => now(),
        ]);
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'statusMessage' => $validator->errors()->first(),
        ], 422));
    }

    protected function failedAuthorization()
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'statusMessage' => 'Akses ditolak. Anda tidak diizinkan membuat pengguna dengan hak akses tersebut.',
        ], 403));
    }
}
