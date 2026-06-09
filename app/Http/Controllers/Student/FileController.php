<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\EnforcesStudentOwnership;
use App\Http\Requests\Student\StoreFileRequest;
use App\Http\Requests\Student\UpdateFileRequest;
use App\Http\Resources\Student\FileResource;
use App\Models\Student\StudentFile;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    use EnforcesStudentOwnership;

    /** Disk used for sensitive applicant documents (private). */
    private const DISK = 'student-files';

    /** Subdirectory inside the disk where uploads are stored. */
    private const FOLDER = 'documents';

    public function index(Request $request)
    {
        try {
            $files = new StudentFile();
            $files = $request->has('userId') ? $files->whereUserid($request->userId) : $files;
            return response([
                'status' => "success",
                'statusMessage' => '',
                'result' => FileResource::collection($files->get())
            ]);
        } catch (Exception $e) {
            return response([
                'status' => "error",
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }
    public function store(StoreFileRequest $request)
    {
        try {
            $request->merge($this->storeUploads($request, [
                'imagePhoto'  => 'filePhoto',
                'imageKk'     => 'fileKk',
                'imageKtp'    => 'fileKtp',
                'imageAkta'   => 'fileAkta',
                'imageIjazah' => 'fileIjazah',
                'imageSkl'    => 'fileSkl',
                'imageKip'    => 'fileKip',
            ]));

            $data = $request->except(['imagePhoto', 'imageKk', 'imageKtp', 'imageAkta', 'imageIjazah', 'imageSkl', 'imageKip']);
            return ($file = StudentFile::create(array_filter($data, function($value) {
                return !is_null($value) && $value !== '';
            })))
                ? response([
                    'status' => "success",
                    'statusMessage' => "Berkas berhasil disimpan",
                    'result' => new FileResource($file)
                ]) : throw new Exception('Berkas gagal disimpan');
        } catch (Exception $e) {
            return response([
                'status' => "error",
                'statusMessage' => $e->getMessage()
            ], 422);
        }
    }
    public function show(StudentFile $file)
    {
        $this->ensureCanViewStudentRecord($file);
        try {
            return response([
                'status' => "success",
                'statusMessage' => '',
                'result' => new FileResource($file)
            ]);
        } catch (Exception $e) {
            return response([
                'status' => "error",
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }
    public function update(UpdateFileRequest $request, StudentFile $file)
    {
        try {
            $request->merge($this->storeUploads($request, [
                'imagePhoto'  => 'filePhoto',
                'imageKk'     => 'fileKk',
                'imageKtp'    => 'fileKtp',
                'imageAkta'   => 'fileAkta',
                'imageIjazah' => 'fileIjazah',
                'imageSkl'    => 'fileSkl',
                'imageKip'    => 'fileKip',
            ], $file));

            $data = $request->except(['imagePhoto', 'imageKk', 'imageKtp', 'imageAkta', 'imageIjazah', 'imageSkl', 'imageKip']);
            return $file = $file->update(array_filter($data, function($value) {
                return !is_null($value) && $value !== '';
            }))
                ? response([
                    'status' => "success",
                    'statusMessage' => "Berkas berhasil diperbarui",
                    'result' => new FileResource($file)
                ]) : throw new Exception('Berkas gagal diperbarui');
        } catch (Exception $e) {
            return response([
                'status' => "error",
                'statusMessage' => $e->getMessage()
            ], 422);
        }
    }
    public function destroy(StudentFile $file)
    {
        try {
            foreach (['filePhoto', 'fileKk', 'fileKtp', 'fileAkta', 'fileIjazah', 'fileSkl', 'fileKip'] as $column) {
                $this->deleteStoredFile($file->getRawOriginal($column));
            }
            return $file->delete()
                ? response([
                    'status' => "success",
                    'statusMessage' => "Berkas berhasil dihapus",
                    'result' => new FileResource($file)
                ]) : throw new Exception('Berkas gagal dihapus');
        } catch (Exception $e) {
            return response([
                'status' => "error",
                'statusMessage' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Persist any uploaded files to the private disk and return a map of
     * column => relative path that the caller can merge back into the
     * request payload.
     *
     * The returned path is prefixed with `student-files/` so that older
     * code paths and the migration command can disambiguate new (private)
     * vs legacy (public disk) records.
     *
     * @param  array<string,string>  $map        request field => DB column
     * @param  StudentFile|null      $existing   to delete prior file on replace
     * @return array<string,string>
     */
    private function storeUploads(Request $request, array $map, ?StudentFile $existing = null): array
    {
        $patches = [];
        foreach ($map as $field => $column) {
            if (!$request->hasFile($field)) {
                continue;
            }

            // Replace: clean up the previous upload (works for both legacy
            // public-disk paths and new private-disk paths).
            if ($existing) {
                $this->deleteStoredFile($existing->getRawOriginal($column));
            }

            $uploaded = $request->file($field);
            $stored = Storage::disk(self::DISK)->putFileAs(
                self::FOLDER,
                $uploaded,
                $uploaded->hashName()
            );
            $patches[$column] = 'student-files/' . $stored;
        }
        return $patches;
    }

    /**
     * Remove a previously-stored file regardless of which disk it lives on.
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
        // Legacy entries created before the migration to the private disk.
        Storage::disk('public')->delete($path);
    }
}
