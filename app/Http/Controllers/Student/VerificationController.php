<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\EnforcesStudentOwnership;
use App\Http\Requests\Student\StoreVerificationRequest;
use App\Http\Requests\Student\UpdateVerificationRequest;
use App\Http\Resources\Student\VerificationResource;
use App\Models\Student\StudentVerification;
use Exception;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    use EnforcesStudentOwnership;

    public function index(Request $request)
    {
        try {
            $verifications = new StudentVerification();
            $verifications = $request->has('userId') ? $verifications->whereUserid($request->userId) : $verifications;
            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => VerificationResource::collection($verifications->get())
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 500);
        }
    }
    public function store(StoreVerificationRequest $request)
    {
        try {
            return ($verification = StudentVerification::create($request->all()))
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Verifikasi berhasil disimpan!',
                    'result' => new VerificationResource($verification)
                ]) : throw new Exception('Verifikasi gagal disimpan!');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 422);
        }
    }
    public function show(StudentVerification $verification)
    {
        $this->ensureCanViewStudentRecord($verification);
        try {
            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => new VerificationResource($verification)
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 500);
        }
    }
    public function update(UpdateVerificationRequest $request, StudentVerification $verification)
    {
        try {
            return $verification->update(array_filter($request->all()))
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Verifikasi berhasil diperbarui!',
                    'result' => new VerificationResource($verification)
                ]) : throw new Exception('Verifikasi gagal diperbarui!');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 422);
        }
    }
    public function destroy(StudentVerification $verification)
    {
        try {
            return $verification->delete()
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Verifikasi berhasil dihapus!',
                    'result' => new VerificationResource($verification)
                ]) : throw new Exception('Verifikasi gagal dihapus!');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 422);
        }
    }
}
