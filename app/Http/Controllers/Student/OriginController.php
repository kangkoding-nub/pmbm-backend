<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\EnforcesStudentOwnership;
use App\Http\Requests\Student\StoreOriginRequest;
use App\Http\Requests\Student\UpdateOriginRequest;
use App\Http\Resources\Student\OriginResource;
use App\Models\Student\StudentOrigin;
use Exception;
use Illuminate\Http\Request;

class OriginController extends Controller
{
    use EnforcesStudentOwnership;

    public function index(Request $request)
    {
        try {
            $origins = new StudentOrigin();
            $origins = $request->has('userId') ? $origins->whereUserid($request->userId) : $origins;
            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => OriginResource::collection($origins->get())
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(StoreOriginRequest $request)
    {
        try {
            return ($origin = StudentOrigin::create($request->all()))
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data Sekolah asal berhasil disimpan',
                    'result' => new OriginResource($origin)
                ]) : throw new Exception('Data Sekolah asal gagal disimpan');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 422);
        }
    }

    public function show(StudentOrigin $origin)
    {
        $this->ensureCanViewStudentRecord($origin);
        try {
            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => new OriginResource($origin)
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 500);
        }
    }
    public function update(UpdateOriginRequest $request, StudentOrigin $origin)
    {
        try {
            return $origin->update(array_filter($request->all()))
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data Sekolah asal berhasil diperbarui',
                    'result' => new OriginResource($origin)
                ]) : throw new Exception('Data Sekolah asal gagal diperbarui');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 422);
        }
    }
    public function destroy(StudentOrigin $origin)
    {
        try {
            return $origin->delete()
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data Sekolah asal berhasil dihapus',
                    'result' => new OriginResource($origin)
                ]) : throw new Exception('Data Sekolah asal gagal dihapus');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 422);
        }
    }
}
