<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Migrate user passwords from the legacy reversible Crypt::encryptString
 * format to a one-way bcrypt hash.
 *
 * The command is idempotent: rows already containing a bcrypt/argon hash are
 * skipped. Run with --dry-run first to preview the impact.
 */
class RehashPasswords extends Command
{
    protected $signature = 'users:rehash-passwords
        {--dry-run : Only report what would change, do not write}
        {--chunk=200 : Number of users to process per batch}';

    protected $description = 'Rehash legacy Crypt-encrypted user passwords with bcrypt.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));

        $hashedCount = 0;
        $alreadyHashed = 0;
        $failed = 0;

        $this->info($dryRun ? 'DRY RUN: no rows will be modified.' : 'Rehashing legacy passwords...');

        User::query()
            ->select(['id', 'email', 'password'])
            ->orderBy('id')
            ->chunkById($chunk, function ($users) use (&$hashedCount, &$alreadyHashed, &$failed, $dryRun) {
                foreach ($users as $user) {
                    $stored = $user->getAttributes()['password'] ?? null;
                    if (!$stored) {
                        continue;
                    }

                    if (preg_match('/^\$(2y|2a|2b|argon2i|argon2id)\$/', $stored)) {
                        $alreadyHashed++;
                        continue;
                    }

                    try {
                        $plain = Crypt::decryptString($stored);
                    } catch (DecryptException $e) {
                        $failed++;
                        $this->warn("  ! Could not decrypt password for user id={$user->id} ({$user->email}): {$e->getMessage()}");
                        continue;
                    }

                    if ($dryRun) {
                        $hashedCount++;
                        continue;
                    }

                    // Bypass the model mutator and Eloquent events to avoid
                    // side effects (e.g. UserObserver) during a maintenance
                    // backfill.
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['password' => Hash::make($plain)]);

                    $hashedCount++;
                }
            });

        $this->newLine();
        $this->line("Already hashed (skipped): {$alreadyHashed}");
        $this->line(($dryRun ? 'Would rehash: ' : 'Rehashed: ') . $hashedCount);
        if ($failed > 0) {
            $this->warn("Failed to decrypt: {$failed}");
        }

        return self::SUCCESS;
    }
}
