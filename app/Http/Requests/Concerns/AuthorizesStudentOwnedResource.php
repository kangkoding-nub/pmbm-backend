<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Reusable authorization for endpoints that operate on a student-owned
 * record (anything keyed by `userId`).
 *
 * Rules:
 *   - Pendaftar (role 4) may act ONLY on records that belong to themselves
 *     (the userId in the body — for stores — or in the route-bound model —
 *     for updates/deletes — must match their own user id).
 *   - Operator (role 3), Admin Lembaga (role 2), and Admin Yayasan (role 1)
 *     may act on any student record.
 *   - Anyone else, or unauthenticated requests, are denied.
 *
 * The trait reads `userId` from the resolved route binding when available,
 * falling back to the request body, so it works for both Store* and Update*
 * FormRequest classes without further wiring.
 */
trait AuthorizesStudentOwnedResource
{
    public function authorize(): bool
    {
        $actor = $this->user('sanctum');
        if (!$actor) {
            return false;
        }

        $actorRole = (int) $actor->role;

        // Privileged tiers can manage any student record.
        if (in_array($actorRole, [1, 2, 3], true)) {
            return true;
        }

        // Pendaftar must own the target record.
        if ($actorRole !== 4) {
            return false;
        }

        $targetUserId = $this->resolveTargetUserId();
        if ($targetUserId === null) {
            return false;
        }

        return (int) $actor->id === (int) $targetUserId;
    }

    /**
     * Resolve the userId of the target record. Prefers the route-bound model
     * (which carries the persisted owner) over request body input, since the
     * body cannot be trusted to identify ownership.
     */
    protected function resolveTargetUserId(): ?int
    {
        // 1. If the route is bound to an Eloquent model exposing a userId
        //    attribute (StudentPersonal, StudentFile, etc.), use that.
        foreach ($this->route()?->parameters() ?? [] as $param) {
            if (is_object($param) && isset($param->userId)) {
                return (int) $param->userId;
            }
        }

        // 2. Otherwise fall back to the body (Store* requests).
        $bodyUserId = $this->input('userId');
        return $bodyUserId !== null ? (int) $bodyUserId : null;
    }

    protected function failedAuthorization()
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'statusMessage' => 'Akses ditolak. Anda tidak diizinkan mengakses data milik pengguna lain.',
        ], 403));
    }
}
