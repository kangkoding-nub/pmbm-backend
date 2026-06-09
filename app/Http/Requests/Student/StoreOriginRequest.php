<?php

namespace App\Http\Requests\Student;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\AuthorizesStudentOwnedResource;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreOriginRequest extends FormRequest
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
            'name' => 'required|string',
            'npsn' => 'nullable|string',
            'address' => 'required|string',
        ];
    }

    public function attributes(): array
    {
        return [
            'userId' => 'ID Pengguna',
            'name' => 'Nama Sekolah/Madrasah Asal',
            'npsn' => 'NPSN Sekolah/Madrasah Asal',
            'address' => 'Alamat Sekolah/Madrasah Asal',
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
