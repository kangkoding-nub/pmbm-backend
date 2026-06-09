<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\EnforcesStudentOwnership;
use App\Http\Requests\Student\StoreAddressRequest;
use App\Http\Requests\Student\UpdateAddressRequest;
use App\Http\Resources\Student\AddressResource;
use App\Models\Student\StudentAddress;
use Exception;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    use EnforcesStudentOwnership;

    public function index(Request $request)
    {
        try {
            $addresses = new StudentAddress();
            $addresses = $request->has('userId') ? $addresses->whereUserid($request->userId) : $addresses;
            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => AddressResource::collection($addresses->get())
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(StoreAddressRequest $request)
    {
        try {
            return ($address = StudentAddress::create($request->all()))
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data tempat tinggal berhasil disimpan',
                    'result' => new AddressResource($address)
                ]) : throw new Exception('Data tempat tinggal gagal disimpan');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 422);
        }
    }

    public function show(StudentAddress $address)
    {
        $this->ensureCanViewStudentRecord($address);
        try {
            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => new AddressResource($address)
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(UpdateAddressRequest $request, StudentAddress $address)
    {
        try {
            return ($address->update(array_filter($request->all())))
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data tempat tinggal berhasil diperbarui',
                    'result' => new AddressResource($address)
                ]) : throw new Exception('Data tempat tinggal gagal diperbarui');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 422);
        }
    }

    public function destroy(StudentAddress $address)
    {
        try {
            return ($address->delete())
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data tempat tinggal berhasil dihapus',
                    'result' => new AddressResource($address)
                ]) : throw new Exception('Data tempat tinggal gagal dihapus');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ],422);
        }
    }
}
