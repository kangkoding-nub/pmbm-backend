<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Student\StudentAchievement;
use App\Models\Student\StudentFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Streams private student documents (KK, KTP, akta, ijazah, SKL, KIP,
 * passport photo, achievement certificates).
 *
 * Authorization model — Opt A (signed temporary URL):
 *
 *   - Authorization happens UPSTREAM, when a URL is minted inside
 *     {@see \App\Http\Resources\Student\FileResource} or
 *     {@see \App\Http\Resources\Student\AchievementResource}. The Resource
 *     classes are returned only from authenticated endpoints that already
 *     enforce ownership via {@see \App\Http\Controllers\Concerns\EnforcesStudentOwnership}.
 *   - The minted URL is signed and expires after 10 minutes. The `signed`
 *     middleware on the route (set in routes/api.php) verifies the
 *     signature and rejects tampered or expired URLs.
 *   - This download endpoint therefore needs no further auth check — the
 *     signed URL itself IS the capability.
 *
 * Files live on the `student-files` disk which is NOT exposed by the web
 * server, so a leaked filesystem path alone is useless.
 */
class FileDownloadController extends Controller
{

    /**
     * Map slot identifier (used in the signed URL) to the database column
     * on student_files that stores the on-disk path.
     */
    private const FILE_SLOTS = [
        'photo'  => 'filePhoto',
        'kk'     => 'fileKk',
        'ktp'    => 'fileKtp',
        'akta'   => 'fileAkta',
        'ijazah' => 'fileIjazah',
        'skl'    => 'fileSkl',
        'kip'    => 'fileKip',
    ];

    public function downloadFile(Request $request, StudentFile $file, string $slot): BinaryFileResponse
    {
        if (!array_key_exists($slot, self::FILE_SLOTS)) {
            throw new NotFoundHttpException('Slot berkas tidak dikenal.');
        }

        $column = self::FILE_SLOTS[$slot];
        $path = $file->getRawOriginal($column);
        if (!$path) {
            throw new NotFoundHttpException('Berkas belum diunggah.');
        }

        return $this->streamFromDisk($path, basename($path));
    }

    public function downloadAchievement(Request $request, StudentAchievement $achievement): BinaryFileResponse
    {
        $path = $achievement->getRawOriginal('file');
        if (!$path) {
            throw new NotFoundHttpException('Berkas belum diunggah.');
        }

        return $this->streamFromDisk($path, basename($path));
    }

    /**
     * Pick the right disk for a given relative path and stream the file
     * inline so browsers can render images directly.
     *
     * Path discriminator (transitional):
     *   - "images/files/..."        → legacy public disk
     *   - "images/achievement/..."  → legacy public disk
     *   - "student-files/..."       → new private disk
     */
    private function streamFromDisk(string $path, string $filename): BinaryFileResponse
    {
        $disk = $this->resolveDisk($path);
        // Strip the disk prefix used by the new private layout.
        $relativePath = $disk === 'student-files'
            ? preg_replace('|^student-files/|', '', $path, 1)
            : $path;

        if (!Storage::disk($disk)->exists($relativePath)) {
            throw new NotFoundHttpException('Berkas tidak ditemukan.');
        }

        $absolute = Storage::disk($disk)->path($relativePath);

        return response()->file($absolute, [
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    private function resolveDisk(string $path): string
    {
        return str_starts_with($path, 'student-files/') ? 'student-files' : 'public';
    }
}
