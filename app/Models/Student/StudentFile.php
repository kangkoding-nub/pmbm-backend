<?php

namespace App\Models\Student;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class StudentFile extends Model
{
    protected $table = 'student_files';
    protected $fillable = [
        'userId',
        'filePhoto',
        'fileKk',
        'fileKtp',
        'numberAkta',
        'fileAkta',
        'numberIjazah',
        'fileIjazah',
        'numberSkl',
        'fileSkl',
        'numberKip',
        'fileKip',
    ];
    protected $appends = ['filePhoto', 'fileKk', 'fileKtp', 'fileAkta', 'fileIjazah', 'fileSkl', 'fileKip'];

    /**
     * Maps the slot identifier exposed in the signed download URL to the
     * underlying database column.
     */
    private const SLOT_FOR_COLUMN = [
        'filePhoto'  => 'photo',
        'fileKk'     => 'kk',
        'fileKtp'    => 'ktp',
        'fileAkta'   => 'akta',
        'fileIjazah' => 'ijazah',
        'fileSkl'    => 'skl',
        'fileKip'    => 'kip',
    ];

    public function getFilePhotoAttribute(): string
    {
        return $this->urlForColumn('filePhoto');
    }

    public function getFileKkAttribute(): string
    {
        return $this->urlForColumn('fileKk');
    }

    public function getFileKtpAttribute(): string
    {
        return $this->urlForColumn('fileKtp');
    }

    public function getFileAktaAttribute(): string
    {
        return $this->urlForColumn('fileAkta');
    }

    public function getFileIjazahAttribute(): string
    {
        return $this->urlForColumn('fileIjazah');
    }

    public function getFileSklAttribute(): string
    {
        return $this->urlForColumn('fileSkl');
    }

    public function getFileKipAttribute(): string
    {
        return $this->urlForColumn('fileKip');
    }

    /**
     * Build the download URL for a given file column.
     *
     * Files stored on the new private disk (path prefixed with
     * `student-files/`) are served through a signed, 10-minute URL that
     * tunnels through {@see \App\Http\Controllers\Student\FileDownloadController}.
     * Legacy files still living on the public disk fall through to the
     * direct `Storage::url()` so the app keeps working during migration.
     */
    private function urlForColumn(string $column): string
    {
        $path = $this->attributes[$column] ?? null;
        if (!$path) {
            return '';
        }

        if (str_starts_with($path, 'student-files/')) {
            return URL::temporarySignedRoute(
                'student.file.download',
                now()->addMinutes(10),
                [
                    'file' => $this->getKey(),
                    'slot' => self::SLOT_FOR_COLUMN[$column],
                ]
            );
        }

        // Legacy public-disk path (transitional).
        return url(Storage::url($path));
    }
}
