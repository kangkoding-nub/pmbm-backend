<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Student\StudentPersonal;
use App\Models\User\Otp;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * Transient plain-text password used to deliver the credential to the
     * user once (e.g. via WhatsApp) right after creation. NEVER persisted.
     */
    public ?string $plainPassword = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'institutionId',
        'name',
        'email',
        'password',
        'phone',
        'role',
        'phone_verified_at',
        'token',
        'expires_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'role' => 'int'
        ];
    }

    protected function password(): Attribute
    {
        return Attribute::make(
            set: function (string $value) {
                // Avoid double-hashing if value is already a bcrypt/argon hash.
                if (preg_match('/^\$(2y|2a|2b|argon2i|argon2id)\$/', $value)) {
                    return $value;
                }
                return Hash::make($value);
            },
        );
    }

    protected function phone(): Attribute
    {
        return Attribute::make(
            set: function (string $value) {
                $cleaned = preg_replace('/[^[:digit:]]/', '', $value);
                if (Str::startsWith($cleaned, '62')) {
                    return $cleaned;
                }

                // Check and remove leading '0' before prepending '62'
                if (Str::startsWith($cleaned, '0')) {
                    return '62' . substr($cleaned, 1);
                }

                // Default case, prepend 62 (use with caution)
                return '62' . $cleaned;
            }
        );
    }

    public function routeNotificationForGowa(): string
    {
        return $this->phone;
    }

    public function createToken(string $name, array $abilities = ['*'], $expiresAt = null): NewAccessToken
    {
        $token = $this->tokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plainTextToken = Str::random(128)),
            'abilities' => $abilities,
            'expires_at' => $expiresAt ?? Carbon::now()->addDays(2),
        ]);
        return new NewAccessToken($token, $token->getKey(). '|'.$plainTextToken);
    }
    public function institution(): HasOne
    {
        return $this->hasOne(Institution::class, 'id', 'institutionId');
    }

    public function personal(): HasOne
    {
        return $this->hasOne(StudentPersonal::class, 'userId', 'id');
    }

    public function otps(): HasOne
    {
        return $this->hasOne(Otp::class,'email', 'email');
    }
}
