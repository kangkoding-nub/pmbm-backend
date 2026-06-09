<?php

namespace App\Observers;

use App\Jobs\SendWhatsAppMessage;
use App\Models\User;
use Illuminate\Support\Carbon;

class UserObserver
{
    /**
     * Handle the User "created" event.
     */
    public function created(User $user): void
    {
        $message = "*PMBM YAYASAN DARUL HIKMAH*". PHP_EOL . PHP_EOL;
        if ($user->role == 4) {
            if ($user->phone_verified_at == null) {
                $code = mt_rand(100000, 999999);
                $user->otps()->create([
                    'email' => $user->email,
                    'token' => $code,
                    'expires_at' => Carbon::now()->addMinutes(10),
                ]);
                $message .= "Halo, $user->name." . PHP_EOL;
                $message .= "Kode OTP Anda adalah: *$code*" . PHP_EOL;
                $message .= "Kode ini berlaku selama 10 menit. Jangan berikan kode ini kepada siapapun." . PHP_EOL;
            } else {
                // Admin-created account that is already phone-verified — user
                // does not know their password yet, so we deliver it once.
                $message = $this->welcomeMessage($user, $user->plainPassword);
            }
        }
        if ($user->phone && $message) {
            SendWhatsAppMessage::dispatch($user->phone, $message);
        }
    }

    /**
     * Handle the User "updated" event.
     */
    public function updated(User $user): void
    {
        if ($user->role == 4) {
            if ($user->getOriginal('phone_verified_at') == null && $user->phone_verified_at !== null) {
                if ($user->phone) {
                    // Self-register flow: the user just verified via OTP and
                    // already knows their password (they typed it during
                    // registration). Send a welcome message WITHOUT echoing
                    // the credential back over WhatsApp (option 3a).
                    SendWhatsAppMessage::dispatch(
                        $user->phone,
                        $this->welcomeMessage($user, null)
                    );
                }
            }
        }
    }

    /**
     * Handle the User "deleted" event.
     */
    public function deleted(User $user): void
    {
        //
    }

    /**
     * Handle the User "restored" event.
     */
    public function restored(User $user): void
    {
        //
    }

    /**
     * Build the welcome WhatsApp message.
     *
     * When $plainPassword is provided (admin-created accounts) we include the
     * credential exactly once. For self-registered users it is null because
     * the user already knows their own password and we MUST NOT decrypt the
     * stored hash to echo it back.
     */
    private function welcomeMessage(User $user, ?string $plainPassword) : string
    {
        $message = "*PMBM YAYASAN DARUL HIKMAH*". PHP_EOL . PHP_EOL;
        $message .= "ini adalah pesan otomatis dari sistem." . PHP_EOL . PHP_EOL;
        $message .= "Selamat bergabung, $user->name." . PHP_EOL;
        $message .= "Nama Pengguna anda adalah: ". $user->email . PHP_EOL;
        if (!empty($plainPassword)) {
            $message .= "Kata Sandi adalah: " . $plainPassword . PHP_EOL;
            $message .= "Demi keamanan, segera ganti kata sandi setelah login pertama." . PHP_EOL;
        }
        $message .= "Silahkan login ke aplikasi https://pmbm.darul-hikmah.sch.id/masuk untuk melengkapi pendaftaran." . PHP_EOL;
        $message .= "Jika terdapat kesulitan, silahkan menghubungi admin kami." . PHP_EOL;
        $message .= "Terima kasih." . PHP_EOL;
        return $message;
    }

    /**
     * Handle the User "force deleted" event.
     */
//    public function forceDeleted(User $user): void
//    {
//        //
//    }
}
