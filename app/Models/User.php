<?php

namespace App\Models;

use App\Services\PasswordOtpService;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string|null $username
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property string $role
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $avatar_url
 * @property string|null $avatar_path
 * @property string|null $specialty
 * @property string|null $bio
 * @property bool $show_phone_publicly
 * @property string $availability_status
 * @property string|null $availability_note
 * @property Carbon|null $availability_updated_at
 * @property bool $is_active
 * @property int $active_assignments_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'username', 'email', 'password', 'role', 'phone', 'address', 'avatar_url', 'avatar_path', 'specialty', 'bio', 'show_phone_publicly', 'availability_status', 'availability_note', 'availability_updated_at', 'is_active'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'avatar_path'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            if ($user->wasChanged(['password', 'email', 'phone', 'role', 'is_active', 'two_factor_secret', 'two_factor_confirmed_at', 'two_factor_recovery_codes'])) {
                $user->tokens()->delete();
            }
        });

        static::deleting(function (User $user): void {
            $user->tokens()->delete();
        });
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        app(PasswordOtpService::class)->issue($this, 'password_reset');
        $this->notify(new ResetPassword($token));
    }

    public function mobileSecurityFingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->password, $this->email, $this->phone, $this->role, (string) $this->is_active,
            $this->two_factor_secret, $this->two_factor_confirmed_at?->toISOString(),
            $this->two_factor_recovery_codes,
        ]));
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * @return HasMany<OrderAssignment, $this>
     */
    public function orderAssignments(): HasMany
    {
        return $this->hasMany(OrderAssignment::class);
    }

    /** @return HasMany<ServiceOrder, $this> */
    public function customerOrders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class, 'customer_id');
    }

    /** @return HasMany<TechnicianReview, $this> */
    public function receivedTechnicianReviews(): HasMany
    {
        return $this->hasMany(TechnicianReview::class, 'technician_id');
    }

    /** @return HasMany<TechnicianReview, $this> */
    public function authoredTechnicianReviews(): HasMany
    {
        return $this->hasMany(TechnicianReview::class, 'customer_id');
    }

    /** @return HasMany<OrderMessage, $this> */
    public function sentOrderMessages(): HasMany
    {
        return $this->hasMany(OrderMessage::class, 'sender_id');
    }

    /** @return HasMany<TechnicianAvailabilityLog, $this> */
    public function availabilityLogs(): HasMany
    {
        return $this->hasMany(TechnicianAvailabilityLog::class);
    }

    /** @return HasMany<TechnicianWorkSession, $this> */
    public function workSessions(): HasMany
    {
        return $this->hasMany(TechnicianWorkSession::class, 'technician_id');
    }

    /** @return HasMany<CustomerAsset, $this> */
    public function customerAssets(): HasMany
    {
        return $this->hasMany(CustomerAsset::class, 'customer_id')->latest('id');
    }

    /** @return HasMany<Warranty, $this> */
    public function warranties(): HasMany
    {
        return $this->hasMany(Warranty::class, 'customer_id')->latest('id');
    }

    /** @return HasMany<WarrantyClaim, $this> */
    public function warrantyClaims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class, 'customer_id')->latest('submitted_at');
    }

    /** @return HasMany<UserNotificationPreference, $this> */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(UserNotificationPreference::class);
    }

    /** @return HasMany<Estimate, $this> */
    public function customerQuotations(): HasMany
    {
        return $this->hasMany(Estimate::class, 'customer_id')->latest('created_at');
    }

    /** @return HasMany<Invoice, $this> */
    public function customerInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'customer_id')->latest('created_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'availability_updated_at' => 'datetime',
            'is_active' => 'boolean',
            'show_phone_publicly' => 'boolean',
        ];
    }
}
