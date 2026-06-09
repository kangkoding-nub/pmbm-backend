<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allow the request to proceed only if the authenticated user's role is in
 * the configured whitelist.
 *
 * Usage:
 *   ->middleware('role:1')        // only role 1 (super admin)
 *   ->middleware('role:1,2')      // role 1 OR 2
 *
 * Role IDs in this codebase:
 *   1 = Admin Yayasan (super admin)
 *   2 = Admin Lembaga
 *   3 = Operator / Bendahara
 *   4 = Pendaftar (student)
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'statusMessage' => 'Tidak terautentikasi.',
            ], 401);
        }

        $allowed = array_map('intval', $roles);

        if (!in_array((int) $user->role, $allowed, true)) {
            return response()->json([
                'status' => 'error',
                'statusMessage' => 'Akses ditolak. Hak akses Anda tidak memenuhi.',
            ], 403);
        }

        return $next($request);
    }
}
