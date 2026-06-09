<?php

namespace App\Http\Requests\Student;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\AuthorizesStudentOwnedResource;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreProgramRequest extends FormRequest
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
            'userId' => ['required', 'integer', 'exists:users,id'],
            'yearId' => ['required', 'integer', 'exists:master_years,id'],
            'institutionId' => ['required', 'integer', 'exists:institutions,id'],
            'periodId' => ['required', 'integer', 'exists:institution_periods,id'],
            'programId' => ['required', 'integer', 'exists:institution_programs,id'],
            'boardingId' => ['required', 'integer', 'exists:master_boardings,id'],
            'status' => ['required', 'string']
        ];
    }

    public function attributes(): array
    {
        return [
            'userId' => 'ID Pengguna',
            'yearId' => 'ID Tahun Ajaran',
            'institutionId' => 'ID Lembaga',
            'periodId' => 'ID Gelombang',
            'programId' => 'Program Lembaga',
            'boardingId' => 'Program Pondok',
            'status' => 'Status Pendaftar',
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
