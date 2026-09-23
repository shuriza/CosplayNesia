<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'user_id', 'slug', 'name', 'description', 'city', 'logo_url', 'banner_url',
        'operating_hours', 'policy', 'status', 'legal_name', 'identity_number_hash',
        'document_path', 'review_note', 'reviewed_by', 'submitted_at', 'approved_at',
        'suspended_at', 'reviewed_at',
        'origin_address_line1', 'origin_address_line2', 'origin_province',
        'origin_postal_code', 'pickup_enabled', 'pickup_instructions',
    ];

    protected $hidden = ['identity_number_hash', 'document_path', 'reviewed_by'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'pickup_enabled' => 'boolean',
        ];
    }

    public static function statuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_SUSPENDED];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
