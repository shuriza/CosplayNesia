<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasFactory, MustVerifyEmail, Notifiable;

    public const ROLE_BUYER = 'buyer';

    public const ROLE_SELLER = 'seller';

    public const ROLE_SUPPORT = 'support';

    public const ROLE_MODERATOR = 'moderator';

    public const ROLE_FINANCE = 'finance';

    public const ROLE_ADMIN = 'admin';

    public const CAPABILITY_REVIEW_STORES = 'review_stores';

    public const CAPABILITY_REVIEW_LISTINGS = 'review_listings';

    public const CAPABILITY_SUPPORT = 'support';

    public const CAPABILITY_FINANCE = 'finance';

    public const CAPABILITY_ADMINISTER = 'administer';

    public const CAPABILITY_BACKOFFICE = 'backoffice';

    public const CAPABILITY_BACKOFFICE_FINANCE = 'backoffice_finance';

    public const CAPABILITY_BACKOFFICE_QUEUE = 'backoffice_queue';

    protected $attributes = ['role' => self::ROLE_BUYER];

    protected $fillable = [
        'name', 'email', 'password', 'terms_version', 'privacy_version',
        'rental_policy_version', 'legal_accepted_at', 'role',
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

    public function store(): HasOne
    {
        return $this->hasOne(Store::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public static function roles(): array
    {
        return [
            self::ROLE_BUYER, self::ROLE_SELLER, self::ROLE_SUPPORT,
            self::ROLE_MODERATOR, self::ROLE_FINANCE, self::ROLE_ADMIN,
        ];
    }

    public function hasCapability(string $capability): bool
    {
        $capabilities = [
            self::ROLE_BUYER => [],
            self::ROLE_SELLER => [],
            self::ROLE_SUPPORT => [self::CAPABILITY_SUPPORT, self::CAPABILITY_BACKOFFICE],
            self::ROLE_MODERATOR => [self::CAPABILITY_REVIEW_STORES, self::CAPABILITY_REVIEW_LISTINGS, self::CAPABILITY_BACKOFFICE],
            self::ROLE_FINANCE => [self::CAPABILITY_FINANCE, self::CAPABILITY_BACKOFFICE, self::CAPABILITY_BACKOFFICE_FINANCE],
            self::ROLE_ADMIN => [
                self::CAPABILITY_REVIEW_STORES, self::CAPABILITY_REVIEW_LISTINGS, self::CAPABILITY_SUPPORT,
                self::CAPABILITY_FINANCE, self::CAPABILITY_ADMINISTER,
                self::CAPABILITY_BACKOFFICE, self::CAPABILITY_BACKOFFICE_FINANCE, self::CAPABILITY_BACKOFFICE_QUEUE,
            ],
        ];

        return in_array($capability, $capabilities[$this->role] ?? [], true);
    }

    public function canReviewStores(): bool
    {
        return $this->hasCapability(self::CAPABILITY_REVIEW_STORES);
    }

    public function canReviewListings(): bool
    {
        return $this->hasCapability(self::CAPABILITY_REVIEW_LISTINGS);
    }

    public function canAccessBackoffice(): bool
    {
        return $this->hasCapability(self::CAPABILITY_BACKOFFICE);
    }

    public function canViewBackofficeFinance(): bool
    {
        return $this->hasCapability(self::CAPABILITY_BACKOFFICE_FINANCE);
    }

    public function canViewBackofficeQueue(): bool
    {
        return $this->hasCapability(self::CAPABILITY_BACKOFFICE_QUEUE);
    }

    public function favoriteProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'favorites')->withTimestamps();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function buyerAddresses(): HasMany
    {
        return $this->hasMany(BuyerAddress::class);
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
