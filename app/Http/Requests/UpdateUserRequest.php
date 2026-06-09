<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization rules (role IDs in this codebase: 1 Admin, 2 Operator,
     * 3 Bendahara, 4 Pendaftar, 5 Operator Pondok, 6 Teller):
     *   - Pendaftar (4) may only update their OWN record and cannot change
     *     their role.
     *   - Administrator (1) may update anyone, including promoting/demoting
     *     to/from role 1.
     *   - Operator (2) may update users (route middleware enforces this)
     *     but cannot promote anyone to role 1, and cannot edit a user
     *     whose current role is 1.
     *   - Roles 3, 5, 6 are blocked from this endpoint by route middleware.
     *
     * The target is resolved from the route binding ({user}) rather than the
     * request body, which previously allowed passing an arbitrary id.
     */
    public function authorize(): bool
    {
        $actor = $this->user('sanctum');
        if (!$actor) {
            return false;
        }

        $target = $this->route('user');
        if (!$target instanceof User) {
            return false;
        }

        $actorRole = (int) $actor->role;
        $requestedRole = $this->input('role');

        // Pendaftar can only edit their own profile and may not change role.
        if ($actorRole === 4) {
            if ((int) $actor->id !== (int) $target->id) {
                return false;
            }
            if ($requestedRole !== null && (int) $requestedRole !== 4) {
                return false;
            }
            return true;
        }

        // Only Administrator (1) and Operator (2) may update other users.
        if (!in_array($actorRole, [1, 2], true)) {
            return false;
        }

        // Operator (2) cannot promote anyone to Administrator (1) and
        // cannot edit an existing Administrator account.
        if ($actorRole !== 1) {
            if ($requestedRole !== null && (int) $requestedRole === 1) {
                return false;
            }
            if ((int) $target->role === 1) {
                return false;
            }
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
        $targetId = optional($this->route('user'))->id;

        return [
            'institutionId' => ['nullable', 'int', 'exists:institutions,id'],
            'name' => ['nullable', 'string'],
            'email' => [
                'nullable', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($targetId),
            ],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'phone' => ['nullable', 'string'],
            // Whitelist allowed role IDs (see role map above).
            'role' => ['nullable', 'int', Rule::in([1, 2, 3, 4, 5, 6])],
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
            'statusMessage' => 'Akses ditolak. Anda tidak diizinkan mengubah pengguna ini.',
        ], 403));
    }
}
