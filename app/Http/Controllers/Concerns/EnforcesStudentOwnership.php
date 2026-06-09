<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Enforce that a Pendaftar (role 4) can only view records that belong to
 * themselves when the controller is keyed by `userId` (StudentPersonal,
 * StudentParent, StudentAddress, StudentProgram, StudentOrigin,
 * StudentAchievement, StudentFile, StudentVerification).
 *
 * Privileged tiers (Administrator, Operator, Bendahara, Operator Pondok,
 * Teller) are allowed to read any record.
 *
 * Usage in a controller's show() method:
 *
 *   public function show(StudentPersonal $personal) {
 *       $this->ensureCanViewStudentRecord($personal);
 *       // ...return response
 *   }
 */
trait EnforcesStudentOwnership
{
    protected function ensureCanViewStudentRecord(object $resource): void
    {
        $actor = auth('sanctum')->user() ?? request()->user();
        if (!$actor) {
            $this->denyAccess();
        }

        $actorRole = (int) $actor->role;

        // Privileged tiers may view any record. (Per access matrix:
        // 1 Admin, 2 Operator, 3 Bendahara, 5 Op. Pondok, 6 Teller.)
        if (in_array($actorRole, [1, 2, 3, 5, 6], true)) {
            return;
        }

        // Pendaftar (4) may only view records they own.
        if ($actorRole === 4) {
            $ownerId = $resource->userId ?? null;
            if ($ownerId !== null && (int) $ownerId === (int) $actor->id) {
                return;
            }
        }

        $this->denyAccess();
    }

    private function denyAccess(): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'statusMessage' => 'Akses ditolak. Anda tidak diizinkan melihat data milik pengguna lain.',
        ], 403));
    }
}
