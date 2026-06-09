<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\EnforcesStudentOwnership;
use App\Http\Requests\Student\StoreParentRequest;
use App\Http\Requests\Student\UpdateParentRequest;
use App\Http\Resources\Student\ParentResource;
use App\Models\Student\StudentParent;
use Exception;
use Illuminate\Http\Request;

class ParentController extends Controller
{
    use EnforcesStudentOwnership;

    public function index(Request $request)
    {
        try {
            $parents = new StudentParent();
            $parents = $request->has('userId') ? $parents->whereUserid($request->get('userId')) : $parents;
            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => ParentResource::collection($parents->get())
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(StoreParentRequest $request)
    {
        try {
            return ($parent = StudentParent::create($request->all()))
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data Orangtua berhasil disimpan',
                    'result' => new ParentResource($parent)
                ]) : throw new Exception('Data Orangtua gagal disimpan');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 422);
        }
    }

    public function show(StudentParent $parent)
    {
        $this->ensureCanViewStudentRecord($parent);
        try {
            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => new ParentResource($parent)
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(UpdateParentRequest $request, StudentParent $parent)
    {
        try {
            return ($parent->update(array_filter($request->all())))
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data Orangtua berhasil diperbarui',
                    'result' => new ParentResource($parent)
                ]): throw new Exception('Data Orangtua gagal diperbarui');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 422);
        }
    }

    public function destroy(StudentParent $parent)
    {
        try {
            return ($parent->delete())
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data Orangtua berhasil dihapus',
                    'result' => new ParentResource($parent)
                ]) : throw new Exception('Data Orangtua gagal dihapus');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 422);
        }
    }
}
