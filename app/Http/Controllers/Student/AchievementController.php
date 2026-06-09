<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\EnforcesStudentOwnership;
use App\Http\Requests\Student\StoreAchievementRequest;
use App\Http\Requests\Student\UpdateAchievementRequest;
use App\Http\Resources\Student\AchievementResource;
use App\Models\Student\StudentAchievement;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AchievementController extends Controller
{
    use EnforcesStudentOwnership;

    /** Disk used for sensitive applicant achievement images (private). */
    private const DISK = 'student-files';

    /** Subdirectory inside the disk where uploads are stored. */
    private const FOLDER = 'achievements';

    public function index(Request $request)
    {
        try {
            $achievements = new StudentAchievement();
            $achievements = $request->has('userId') ? $achievements->whereUserid($request->userId) : $achievements;
            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => AchievementResource::collection($achievements->get())
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }

    public function store(StoreAchievementRequest $request)
    {
        try {
            if ($request->hasFile('image')) {
                $stored = Storage::disk(self::DISK)->putFileAs(
                    self::FOLDER,
                    $request->file('image'),
                    $request->file('image')->hashName()
                );
                $request->merge(['file' => 'student-files/' . $stored]);
            }
            return ($achievement = StudentAchievement::create($request->all()))
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data Prestasi berhasil ditambahkan',
                    'result' => new AchievementResource($achievement)
                ]) : throw new Exception('Data Prestasi gagal ditambahkan');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 422);
        }
    }

    public function show(StudentAchievement $achievement)
    {
        $this->ensureCanViewStudentRecord($achievement);
        try {
            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => new AchievementResource($achievement)
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }

    public function update(UpdateAchievementRequest $request, StudentAchievement $achievement)
    {
        try {
            if ($request->hasFile('image')) {
                $this->deleteStoredFile($achievement->getRawOriginal('file'));
                $stored = Storage::disk(self::DISK)->putFileAs(
                    self::FOLDER,
                    $request->file('image'),
                    $request->file('image')->hashName()
                );
                $request->merge(['file' => 'student-files/' . $stored]);
            }
            return $achievement->update($request->all())
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data Prestasi berhasil diperbarui',
                    'result' => new AchievementResource($achievement)
                ]) : throw new Exception('Data Prestasi gagal diperbarui');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 422);
        }
    }

    public function destroy(StudentAchievement $achievement)
    {
        try {
            $this->deleteStoredFile($achievement->getRawOriginal('file'));
            return $achievement->delete()
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data Prestasi berhasil dihapus',
                    'result' => new AchievementResource($achievement)
                ]) : throw new Exception('Data Prestasi gagal dihapus');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Remove a previously-stored file regardless of which disk it lives on.
     * Records created before the private-disk migration still carry paths
     * relative to the public disk.
     */
    private function deleteStoredFile(?string $path): void
    {
        if (!$path) {
            return;
        }
        if (str_starts_with($path, 'student-files/')) {
            Storage::disk(self::DISK)->delete(substr($path, strlen('student-files/')));
            return;
        }
        Storage::disk('public')->delete($path);
    }
}
