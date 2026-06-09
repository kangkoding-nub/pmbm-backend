<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\EnforcesStudentOwnership;
use App\Http\Requests\Student\StoreProgramRequest;
use App\Http\Requests\Student\UpdateProgramRequest;
use App\Http\Resources\Student\ProgramResource;
use App\Models\Student\StudentProgram;
use Exception;
use Illuminate\Http\Request;

class ProgramController extends Controller
{
    use EnforcesStudentOwnership;

    public function index(Request $request)
    {
        try {
            $programs = new StudentProgram();
            $programs = $request->has('userId') ? $programs->whereUserid($request->userId) : $programs;
            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => ProgramResource::collection($programs->get())
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 500);
        }
    }
    public function store(StoreProgramRequest $request)
    {
        try {
            return ($program = StudentProgram::create($request->all()))
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data Program Pilihan berhasil disimpan.',
                    'result' => new ProgramResource($program)
                ]) : throw new Exception('Data Program Pilihan gagal disimpan.');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 422);
        }
    }
    public function show(StudentProgram $program)
    {
        $this->ensureCanViewStudentRecord($program);
        try {
            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => new ProgramResource($program)
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 500);
        }
    }
    public function update(UpdateProgramRequest $request, StudentProgram $program)
    {
        try {
            return $program->update(array_filter($request->all()))
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data Program Pilihan berhasil diperbarui.',
                    'result' => new ProgramResource($program)
                ]) : throw new Exception('Data Program Pilihan gagal diperbarui.');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 422);
        }
    }
    public function destroy(StudentProgram $program)
    {
        try {
            return $program->delete()
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data Program Pilihan berhasil dihapus.',
                    'result' => new ProgramResource($program)
                ]) : throw new Exception('Data Program Pilihan gagal dihapus.');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 422);
        }
    }
}
