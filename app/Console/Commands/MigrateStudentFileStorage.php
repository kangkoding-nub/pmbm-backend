<?php

namespace App\Console\Commands;

use App\Models\Student\StudentAchievement;
use App\Models\Student\StudentFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Migrate sensitive applicant documents from the publicly-served
 * `public` disk to the private `student-files` disk.
 *
 * The command is idempotent — files already on the private disk are
 * skipped — and supports a `--dry-run` flag for safe inspection on
 * production. Each row is updated independently so a partial failure
 * does not block the rest.
 *
 * Run order suggested for production:
 *   1) php artisan students:migrate-file-storage --dry-run
 *   2) php artisan students:migrate-file-storage
 */
class MigrateStudentFileStorage extends Command
{
    protected $signature = 'students:migrate-file-storage
        {--dry-run : Report what would change but write nothing}
        {--chunk=200 : Number of rows to process per batch}';

    protected $description = 'Move student documents from public disk to the private student-files disk.';

    private const NEW_DISK = 'student-files';
    private const LEGACY_DISK = 'public';
    private const PREFIX = 'student-files/';

    /** Columns on student_files that hold a file path. */
    private const FILE_COLUMNS = [
        'filePhoto',
        'fileKk',
        'fileKtp',
        'fileAkta',
        'fileIjazah',
        'fileSkl',
        'fileKip',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk  = max(1, (int) $this->option('chunk'));

        if ($dryRun) {
            $this->info('DRY RUN — no files will be moved and no rows updated.');
        }

        $stats = [
            'files_migrated'        => 0,
            'files_skipped'         => 0,
            'files_missing'         => 0,
            'achievements_migrated' => 0,
            'achievements_skipped'  => 0,
            'achievements_missing'  => 0,
            'failures'              => 0,
        ];

        $this->migrateStudentFiles($dryRun, $chunk, $stats);
        $this->migrateAchievements($dryRun, $chunk, $stats);

        $this->newLine();
        $this->line(sprintf(
            'StudentFile rows: %d migrated, %d skipped, %d missing on disk.',
            $stats['files_migrated'],
            $stats['files_skipped'],
            $stats['files_missing']
        ));
        $this->line(sprintf(
            'Achievements:    %d migrated, %d skipped, %d missing on disk.',
            $stats['achievements_migrated'],
            $stats['achievements_skipped'],
            $stats['achievements_missing']
        ));
        if ($stats['failures'] > 0) {
            $this->warn("Failures encountered: {$stats['failures']}");
        }

        return self::SUCCESS;
    }

    private function migrateStudentFiles(bool $dryRun, int $chunk, array &$stats): void
    {
        StudentFile::query()
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use ($dryRun, &$stats) {
                foreach ($rows as $row) {
                    $patches = [];
                    foreach (self::FILE_COLUMNS as $column) {
                        $path = $row->getRawOriginal($column);
                        if (!$path) {
                            continue;
                        }
                        if (str_starts_with($path, self::PREFIX)) {
                            $stats['files_skipped']++;
                            continue;
                        }

                        $newPath = $this->moveLegacyFile($path, $dryRun, $stats, 'files');
                        if ($newPath !== null) {
                            $patches[$column] = $newPath;
                        }
                    }

                    if (!empty($patches) && !$dryRun) {
                        DB::table('student_files')
                            ->where('id', $row->id)
                            ->update($patches);
                    }
                }
            });
    }

    private function migrateAchievements(bool $dryRun, int $chunk, array &$stats): void
    {
        StudentAchievement::query()
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use ($dryRun, &$stats) {
                foreach ($rows as $row) {
                    $path = $row->getRawOriginal('file');
                    if (!$path) {
                        continue;
                    }
                    if (str_starts_with($path, self::PREFIX)) {
                        $stats['achievements_skipped']++;
                        continue;
                    }

                    $newPath = $this->moveLegacyFile($path, $dryRun, $stats, 'achievements');
                    if ($newPath !== null && !$dryRun) {
                        DB::table('student_achievements')
                            ->where('id', $row->id)
                            ->update(['file' => $newPath]);
                    }
                }
            });
    }

    /**
     * Copy a single file from the public disk onto the private disk and
     * remove the original on success. Returns the new prefixed path
     * (e.g. "student-files/documents/abc.jpg") or null when the file
     * cannot be migrated.
     */
    private function moveLegacyFile(string $legacyPath, bool $dryRun, array &$stats, string $kind): ?string
    {
        if (!Storage::disk(self::LEGACY_DISK)->exists($legacyPath)) {
            $stats[$kind . '_missing']++;
            $this->warn("  ! Source file missing on public disk: {$legacyPath}");
            return null;
        }

        // Determine destination subfolder + filename. Keep the existing
        // filename so the migration is reproducible and easy to audit.
        $folder = $kind === 'achievements' ? 'achievements' : 'documents';
        $destination = $folder . '/' . basename($legacyPath);

        if ($dryRun) {
            $stats[$kind . '_migrated']++;
            $this->line("  + would move {$legacyPath}  →  " . self::PREFIX . $destination);
            return null;
        }

        try {
            $contents = Storage::disk(self::LEGACY_DISK)->get($legacyPath);
            Storage::disk(self::NEW_DISK)->put($destination, $contents);
            Storage::disk(self::LEGACY_DISK)->delete($legacyPath);
        } catch (\Throwable $e) {
            $stats['failures']++;
            $this->error("  ! Failed to migrate {$legacyPath}: " . $e->getMessage());
            return null;
        }

        $stats[$kind . '_migrated']++;
        return self::PREFIX . $destination;
    }
}
