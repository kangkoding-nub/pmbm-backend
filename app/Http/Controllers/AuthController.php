<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginStoreRequest;
use App\Http\Requests\StoreRegisterRequest;
use App\Http\Resources\UserResource;
use App\Jobs\SendWhatsAppMessage;
use App\Models\User;
use App\Models\User\Otp;
use Exception;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(StoreRegisterRequest $request)
    {
        try {
            return ($user = User::create($request->all()))
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Pendaftaran berhasil.',
                    'result' => [
                        'user' => $user->toArray()
                    ]
                ]) : throw new Exception('Pendaftaran gagal.');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 422);
        }
    }

    public function login(LoginStoreRequest $request)
    {
        try {
            $credentials = $request->only(['email', 'password']);
            $user = User::where('email', $credentials['email'])->first();

            if (!$user) {
                throw new Exception('Nama pengguna/kata sandi salah.', 401);
            }

            if (!$this->verifyPassword($user, $credentials['password'])) {
                throw new Exception('Nama pengguna/kata sandi salah.', 401);
            }

            Auth::login($user);

            if ($user->phone_verified_at !== null) {
                return response([
                    'status' => 'success',
                    'statusMessage' => 'Berhasil masuk, anda akan dialihkan dalam 2 detik.',
                    'result' => [
                        'user' => $user->toArray(),
                        // Use a generic token name instead of the user's
                        // email — token names land in personal_access_tokens
                        // and may surface in audit logs.
                        'token' => $user->createToken('web-session')->plainTextToken
                    ]
                ]);
            }

            return response([
                'status' => 'success',
                'statusMessage' => 'Berhasil masuk, anda akan dialihkan dalam 2 detik.',
                'result' => [
                    'user' => $user->toArray()
                ]
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 401);
        }
    }

    /**
     * Verify a user's password against the stored credential.
     *
     * Supports lazy migration: if a stored password is still in the legacy
     * Crypt::encryptString format, decrypt it once, compare, and re-hash with
     * bcrypt so subsequent logins use the secure hash.
     */
    private function verifyPassword(User $user, string $plain): bool
    {
        $stored = $user->getAttributes()['password'] ?? null;
        if (!$stored) {
            return false;
        }

        // Already hashed (bcrypt / argon2) — happy path.
        if (preg_match('/^\$(2y|2a|2b|argon2i|argon2id)\$/', $stored)) {
            return Hash::check($plain, $stored);
        }

        // Legacy Crypt::encryptString payload — decrypt once, verify, then rehash.
        try {
            $legacyPlain = Crypt::decryptString($stored);
        } catch (DecryptException $e) {
            return false;
        }

        if (!hash_equals($legacyPlain, $plain)) {
            return false;
        }

        // Lazy migrate to bcrypt. The mutator on the model will hash this value.
        $user->password = $plain;
        $user->saveQuietly();

        return true;
    }

    public function phoneVerify(Request $request)
    {
        try {
            $otp = Otp::whereEmail($request->email)
                ->where('expires_at', '>=', Carbon::now())
                ->first();
            if ($otp === null) {
                throw new Exception('Kode Verifikasi salah/kadaluarsa.', 442);
            }

            if ($request->otp != $otp->token) {
                throw new Exception('Kode Verifikasi salah/kadaluarsa.', 442);
            }

            $user = User::whereEmail($request->email)->first();
            // Mark phone as verified WITHOUT re-touching the password column.
            // The password mutator would otherwise re-hash an already-hashed
            // value or, worse, decrypt+re-encrypt legacy values.
            $user->phone_verified_at = Carbon::now();
            $user->save();
            $otp->delete();

            return response([
                'status' => 'success',
                'statusMessage' => 'Berhasil masuk, anda akan dialihkan dalam 2 detik.',
                'statusCode' => 200,
                'result' => [
                    'user' => $user->toArray(),
                    // Generic token name; do not include PII like email.
                    'token' => $user->createToken('web-session')->plainTextToken
                ]
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 422);
        }
    }

    public function getPhoneVerify(Request $request)
    {
        try {
            $otp = Otp::whereEmail($request->email)->get();
            $otp->map(function ($item) {
                $item->delete();
            });
            $code = [
                'email' => $request->email,
                'token' => mt_rand(100000, 999999),
                'expires_at' => Carbon::now()->addMinutes(10),
            ];
            $user = User::whereEmail($request->email)->first();
            $otp = Otp::create($code);
            $message = "*PMBM YAYASAN DARUL HIKMAH*" . PHP_EOL . PHP_EOL;
            $message .= "Halo, $user->name." . PHP_EOL;
            $message .= "Kode OTP Anda adalah: *$otp->token*" . PHP_EOL;
            $message .= "Kode ini berlaku selama 10 menit. Jangan berikan kode ini kepada siapapun." . PHP_EOL;
            SendWhatsAppMessage::dispatch($user->phone, $message);
            return response([
                'status' => 'success',
                'statusMessage' => 'Kode Verifikasi berhasil dikirim ke nomer anda',
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 422);
        }
    }

    public function logout(Request $request)
    {
        try {
            $accessToken = $request->user()->currentAccessToken();
            if ($accessToken instanceof PersonalAccessToken) {
                $accessToken->delete();
                return response([
                    'status' => 'success',
                    'statusMessage' => 'Berhasil keluar.',
                ]);
            } else {
                return throw new Exception('Terjadi kesalahan server', 500);
            }
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }

    public function profile(Request $request)
    {
        try {
            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => new UserResource($request->user('sanctum'))
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }
}
