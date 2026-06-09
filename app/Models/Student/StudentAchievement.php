<?php

namespace App\Models\Student;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class StudentAchievement extends Model
{
    protected $table = 'student_achievements';
    protected $fillable = [
        'userId',
        'level',
        'type',
        'champ',
        'name',
        'file',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'int',
            'champ' => 'int',
            'type' => 'int'
        ];
    }

    /**
     * The certificate image is stored as a path on disk; expose it as a
     * download URL.
     *
     *  - Records persisted under `student-files/...` go through a signed
     *    10-minute URL that hits FileDownloadController.
     *  - Legacy records that still hold a public-disk path resolve to
     *    `Storage::url()` so the app keeps rendering during migration.
     *
     * The setter strips any inbound URL prefix so callers may pass either
     * an absolute URL or a bare path.
     */
    protected function file(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): string {
                if (!$value) {
                    return '';
                }

                if (str_starts_with($value, 'student-files/')) {
                    return URL::temporarySignedRoute(
                        'student.achievement.download',
                        now()->addMinutes(10),
                        ['achievement' => $this->getKey()]
                    );
                }

                return url(Storage::url($value));
            },
            set: fn (string $value) => Str::chopStart($value, url(Storage::url(''))),
        );
    }
}
