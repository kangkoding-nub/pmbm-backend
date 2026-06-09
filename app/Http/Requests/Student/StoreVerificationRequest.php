<?php

namespace App\Http\Requests\Student;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\AuthorizesStudentOwnedResource;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreVerificationRequest extends FormRequest
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
            'userId' => 'exists:users,id',
            'twins' => 'required|int',
            'twinsName' => 'nullable|string',
            'graduate' => 'required|int',
            'student' => 'required|int',
            'domicile' => 'required|int',
            'teacherSon' => 'required|int',
            'sibling' => 'required|int',
            'siblingInstitution' => 'nullable|exists:institutions,id',
            'siblingName' => 'nullable|string',
        ];
    }

    public function attributes(): array
    {
        return [
            'userId' => 'ID Pengguna',
            'twins' => 'Kolom Saudara Kembar',
            'twinsName' => 'Nama Saudara Kembar',
            'graduate' => 'Lulusan Lembaga Yayasan Darul Hikmah',
            'student' => 'Santri Pondok Yayasan Darul Hikmah',
            'teacherSon' => 'Putra/Putri Guru/Karyawan Yayasan Darul Hikmah',
            'sibling' => 'Saudara kandung',
            'siblingInstitution' => 'Lembaga Saudara Kandung',
            'siblingName' => 'Nama Saudara Kandung',
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge([
            'createdBy' => $this->user('sanctum')->id,
            'updatedBy' => $this->user('sanctum')->id,
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
