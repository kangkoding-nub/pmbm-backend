<?php

namespace App\Http\Requests\Student;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\AuthorizesStudentOwnedResource;
use Illuminate\Http\Exceptions\HttpResponseException;

class StorePersonalRequest extends FormRequest
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
            'nik' => 'required|string|max_digits:16',
            'nisn' => 'nullable',
            'gender' => 'required|in:1,2',
            'birthPlace' => 'required|string',
            'birthDate' => 'required|date|date_format:Y-m-d',
            'phone' => 'required|string',
            'birthNumber' => 'required|string',
            'sibling' => 'required|string',
        ];
    }

    public function attributes(): array
    {
        return [
            'userId' => 'ID Pengguna',
            'name' => 'Nama Lengkap',
            'nik' => 'NIK',
            'nisn' => 'NISN',
            'gender' => 'Jenis Kelamin',
            'birthPlace' => 'Tempat Lahir',
            'birthDate' => 'Tanggal Lahir',
            'phone' => 'Nomor HP',
            'birthNumber' => 'Anak Ke',
            'sibling' => 'Jumlah Saudara',
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
