<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $claim_number
 * @property int $warranty_id
 * @property int|null $customer_id
 * @property string|null $customer_asset_id
 * @property string $original_service_order_id
 * @property string|null $claim_service_order_id
 * @property int|null $submitted_by
 * @property int|null $reviewed_by
 * @property int|null $resolved_by
 * @property string $status
 * @property string $issue_description
 * @property bool $eligibility_checked
 * @property string|null $eligibility_notes
 * @property string|null $review_notes
 * @property string|null $resolution_notes
 * @property Carbon $submitted_at
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Warranty $warranty
 * @property-read User|null $customer
 * @property-read CustomerAsset|null $asset
 * @property-read ServiceOrder $originalOrder
 * @property-read ServiceOrder|null $claimOrder
 * @property-read User|null $submitter
 * @property-read User|null $reviewer
 * @property-read User|null $resolver
 */
#[Fillable([
    'claim_number',
    'warranty_id',
    'customer_id',
    'customer_asset_id',
    'original_service_order_id',
    'claim_service_order_id',
    'submitted_by',
    'reviewed_by',
    'resolved_by',
    'status',
    'issue_description',
    'eligibility_checked',
    'eligibility_notes',
    'review_notes',
    'resolution_notes',
    'submitted_at',
    'reviewed_at',
    'resolved_at',
])]
class WarrantyClaim extends Model
{
    public const ACTIVE_STATUSES = ['submitted', 'under_review', 'approved'];

    protected function casts(): array
    {
        return [
            'eligibility_checked' => 'boolean',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Warranty, $this> */
    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class, 'warranty_id');
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<CustomerAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(CustomerAsset::class, 'customer_asset_id');
    }

    /** @return BelongsTo<ServiceOrder, $this> */
    public function originalOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'original_service_order_id');
    }

    /** @return BelongsTo<ServiceOrder, $this> */
    public function claimOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'claim_service_order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
