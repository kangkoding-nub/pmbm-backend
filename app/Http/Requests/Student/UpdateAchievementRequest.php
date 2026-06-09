<?php

namespace App\Http\Requests\Student;

use App\Http\Requests\Concerns\AuthorizesStudentOwnedResource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateAchievementRequest extends FormRequest
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
            'level' => 'required',
            'champ' => 'required',
            'type' => 'required',
            'name' => 'required|string',
            'image' => 'image|mimes:jpeg,jpg,png,gif|max:2048',
        ];
    }

    public function attributes():array
    {
        return [
            'level' => 'Tingkat',
            'champ' => 'Juara',
            'type' => 'Jenis',
            'name' => 'Nama',
            'image' => 'Sertifikat'
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge([
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
