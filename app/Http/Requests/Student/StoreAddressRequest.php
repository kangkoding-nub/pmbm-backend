<?php

namespace App\Http\Requests\Student;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\AuthorizesStudentOwnedResource;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreAddressRequest extends FormRequest
{
    use AuthorizesStudentOwnedResource;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'userId' => 'required|exists:users,id',
            'province' => 'required|string',
            'city' => 'required|string',
            'district' => 'required|string',
            'village' => 'required|string',
            'street' => 'required|string',
            'rt' => 'nullable|string',
            'rw' => 'nullable|string',
            'postal' => 'nullable|string',
        ];
    }

    public function attributes(): array
    {
        return [
            'userId' => 'ID Pengguna',
            'province' => 'Provinsi',
            'city' => 'Kota/Kabupaten',
            'district' => 'Kecamatan',
            'village' => 'Desa/Kelurahan',
            'street' => 'Jalan',
            'rt' => 'RT',
            'rw' => 'RW',
            'postal' => 'Kodepos',
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge([
            'createdBy' => $this->user()->id,
            'updatedBy' => $this->user()->id,
        ]);
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'statusMessage' => $validator->errors()->first(),
        ], 422));
    }
}
