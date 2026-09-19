<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasFactory, MustVerifyEmail, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'terms_version', 'privacy_version',
        'rental_policy_version', 'legal_accepted_at',
    ];

    protected $hidden = [
        'password', 'remember_token', 'pending_email', 'pending_email_token_hash',
        'pending_email_requested_at',
    ];

    protected $appends = ['transaction_ready'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'pending_email_requested_at' => 'datetime',
            'legal_accepted_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'seller_id');
    }

    public function favoriteProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'favorites')->withTimestamps();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(OrderFulfillment::class, 'seller_id');
    }

    public function productReviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function accountSessions(): HasMany
    {
        return $this->hasMany(AccountSession::class);
    }

    public function securityEvents(): HasMany
    {
        return $this->hasMany(SecurityEvent::class);
    }

    public function hasCurrentLegalConsent(): bool
    {
        return $this->terms_version === config('cosplaynesia.legal.terms_version')
            && $this->privacy_version === config('cosplaynesia.legal.privacy_version')
            && $this->rental_policy_version === config('cosplaynesia.legal.rental_policy_version')
            && $this->legal_accepted_at !== null;
    }

    public function canTransact(): bool
    {
        return $this->deactivated_at === null
            && $this->hasVerifiedEmail()
            && $this->hasCurrentLegalConsent();
    }

    public function getTransactionReadyAttribute(): bool
    {
        return $this->canTransact();
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
