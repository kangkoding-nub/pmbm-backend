<?php

namespace App\Http\Requests\Student;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\AuthorizesStudentOwnedResource;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreParentRequest extends FormRequest
{
    use AuthorizesStudentOwnedResource;


    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        if ($this->request->get('fatherStatus') === '1') {
            $fatherRules = [
                'fatherNik' => 'required|string|',
                'fatherBirthPlace' => 'required|string',
                'fatherBirthDate' => 'required|string',
                'fatherStudy' => 'required|string',
                'fatherJob' => 'required|string',
                'fatherPhone' => 'required|string',
            ];
        } else {
            $fatherRules = [
                'fatherNik' => 'nullable',
                'fatherBirthPlace' => 'nullable',
                'fatherBirthDate' => 'nullable',
                'fatherStudy' => 'nullable',
                'fatherJob' => 'nullable',
                'fatherPhone' => 'nullable',
            ];
        }
        if ($this->request->get('motherStatus') === '1') {
            $motherRules = [
                'motherNik' => 'required|string',
                'motherBirthPlace' => 'required|string',
                'motherBirthDate' => 'required|string',
                'motherStudy' => 'required|string',
                'motherJob' => 'required|string',
                'motherPhone' => 'required|string',
            ];
        } else {
            $motherRules = [
                'motherNik' => 'nullable',
                'motherBirthPlace' => 'nullable',
                'motherBirthDate' => 'nullable',
                'motherStudy' => 'nullable',
                'motherJob' => 'nullable',
                'motherPhone' => 'nullable',
            ];
        }
        $defaultRules = [
            'userId' => 'required|exists:users,id',
            'numberKk' => 'required|string',
            'headFamily' => 'required|string',
            'fatherStatus' => 'required|string',
            'fatherName' => 'required|string',
            'motherStatus' => 'required|string',
            'motherName' => 'required|string',
            'guardStatus' => 'required|string',
            'guardName' => 'required|string',
            'guardNik' => 'required|string',
            'guardBirthPlace' => 'required|string',
            'guardBirthDate' => 'required|string',
            'guardStudy' => 'required|string',
            'guardJob' => 'required|string',
            'guardPhone' => 'required|string',
        ];
        return [...$defaultRules, ...$fatherRules, ...$motherRules];
    }

    public function attributes(): array
    {
        return [
            'userId' => 'ID Pengguna',
            'numberKk' => 'Nomor Kartu Keluarga',
            'headFamily' => 'Kepala Keluarga',
            'fatherStatus' => 'Status Ayah Kandung',
            'fatherName' => 'Nama Ayah Kandung',
            'fatherNik' => 'NIK Ayah Kandung',
            'fatherBirthPlace' => 'Tempat Lahir Ayah',
            'fatherBirthDate' => 'Tanggal Lahir Ayah',
            'fatherStudy' => 'Pendidikan Terakhir Ayah',
            'fatherJob' => 'Pekerjaan Ayah',
            'fatherPhone' => 'Nomor HP Ayah',
            'motherStatus' => 'Status Ibu Kandung',
            'motherName' => 'Nama Ibu Kandung',
            'motherNik' => 'NIK Ibu Kandung',
            'motherBirthPlace' => 'Tempat Lahir Ibu',
            'motherBirthDate' => 'Tanggal Lahir Ibu',
            'motherStudy' => 'Pendidikan Terakhir Ibu',
            'motherJob' => 'Pekerjaan Ibu',
            'motherPhone' => 'Nomor HP Ibu',
            'guardStatus' => 'Status Wali',
            'guardName' => 'Nama Wali',
            'guardNik' => 'NIK Wali',
            'guardBirthPlace' => 'Tempat Lahir Wali',
            'guardBirthDate' => 'Tanggal Lahir Wali',
            'guardStudy' => 'Pendidikan Terakhir Wali',
            'guardJob' => 'Pekerjaan Wali',
            'guardPhone' => 'Nomor HP Wali',
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
