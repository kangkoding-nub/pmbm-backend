<?php

namespace App\Http\Requests\Student;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\AuthorizesStudentOwnedResource;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreFileRequest extends FormRequest
{
    use AuthorizesStudentOwnedResource;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        // The `image:mimes:...` syntax used previously was malformed — the
        // `image` rule does not accept parameters. Use separate rules so
        // Laravel actually enforces both image type and explicit MIME list.
        $imageRules = ['image', 'mimes:jpeg,png,jpg', 'max:1024'];

        return [
            'userId'        => 'required|exists:users,id',
            'imagePhoto'    => array_merge(['nullable'], $imageRules),
            'imageKk'       => array_merge(['required'], $imageRules),
            'imageKtp'      => array_merge(['nullable'], $imageRules),
            'numberAkta'    => 'nullable|string',
            'imageAkta'     => array_merge(['required'], $imageRules),
            'numberIjazah'  => 'nullable|string',
            'imageIjazah'   => array_merge(['nullable'], $imageRules),
            'numberSkl'     => 'nullable|string',
            'imageSkl'      => array_merge(['nullable'], $imageRules),
            'numberKip'     => 'nullable|string',
            'imageKip'      => array_merge(['nullable'], $imageRules),
        ];
    }

    public function attributes(): array
    {
        return [
            'userId' => 'ID Pengguna',
            'imagePhoto' => 'Pass Photo',
            'imageKk' => 'Foto Kartu Keluarga',
            'imageKtp' => 'Foto KTP Ayah',
            'numberAkta' => 'Nomor Akta',
            'imageAkta' => 'Foto/Scan Akta',
            'numberIjazah' => 'Nomor Ijazah',
            'imageIjazah' => 'Foto/Scan Ijazah',
            'numberSkl' => 'Nomor SKL',
            'imageSkl' => 'Foto/Scan SKL',
            'numberKip' => 'Nomor KIP',
            'imageKip' => 'Foto KIP',
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
