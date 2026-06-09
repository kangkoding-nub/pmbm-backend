<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        try {
            $users = new User();
            $users = $request->has('institutionId') ? $users->whereInstitutionid($request->institutionId) : $users;
            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => UserResource::collection($users->get())
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(StoreUserRequest $request)
    {
        try {
            // Capture the plain-text password BEFORE the model mutator hashes
            // it, so the UserObserver can deliver it to the user once via
            // WhatsApp. This is the only legitimate place the plaintext is
            // held, and only for the duration of this request.
            $plainPassword = $request->input('password');

            $user = new User($request->all());
            $user->plainPassword = $plainPassword;
            $saved = $user->save();

            return $saved
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data Pengguna berhasil ditambahkan.',
                    'result' => new UserResource($user)
                ]) : throw new Exception('Data Pengguna gagal ditambahkan.');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 422);
        }
    }

    public function show(User $user)
    {
        try {
            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => new UserResource($user)
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        try {
            // Whitelist via FormRequest::validated(); strip nulls/empty so a
            // PATCH with sparse fields works as expected. We never accept
            // `id`, `phone_verified_at`, etc. from the body.
            $payload = array_filter(
                $request->validated(),
                fn ($value) => $value !== null && $value !== ''
            );

            // Pendaftar (role 4) editing themselves: lock the role field even
            // if it slipped past the FormRequest authorize() check.
            $actor = $request->user('sanctum');
            if ($actor && (int) $actor->role === 4) {
                unset($payload['role'], $payload['institutionId']);
            }

            $passwordChanged = array_key_exists('password', $payload);
            $roleChanged = array_key_exists('role', $payload)
                && (int) $payload['role'] !== (int) $user->role;

            $updated = $user->update($payload);

            // Invalidate every active session/token for this user when their
            // credentials or privilege level changes. The actor's CURRENT
            // token is preserved if they are editing themselves so they do
            // not get logged out mid-request; for everyone else, every token
            // is wiped.
            if ($updated && ($passwordChanged || $roleChanged)) {
                $currentToken = $actor?->currentAccessToken();
                $isSelfEdit = $actor && (int) $actor->id === (int) $user->id;
                $preserveId = $isSelfEdit && $currentToken ? $currentToken->id : null;

                $tokensQuery = $user->tokens();
                if ($preserveId !== null) {
                    $tokensQuery->where('id', '!=', $preserveId);
                }
                $tokensQuery->delete();
            }

            return $updated
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data Pengguna berhasil diperbarui.',
                    'result' => new UserResource($user)
                ]) : throw new Exception('Data Pengguna gagal diperbarui.');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 422);
        }
    }

    public function destroy(User $user)
    {
        try {
            return $user->delete()
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data Pengguna berhasil dihapus.',
                    'result' => new UserResource($user)
                ]) : throw new Exception('Data Pengguna gagal dihapus.');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 422);
        }
    }
}
