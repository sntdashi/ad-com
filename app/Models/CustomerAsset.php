<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int|null $customer_id
 * @property string|null $origin_order_id
 * @property string|null $device_code
 * @property string $name
 * @property string $category
 * @property string|null $brand
 * @property string|null $model
 * @property string|null $serial_masked
 * @property string|null $serial_number_hash
 * @property string|null $location
 * @property string $status
 * @property string|null $notes
 * @property string|null $passport_token_hash
 * @property Carbon|null $last_serviced_at
 * @property Carbon|null $registered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $customer
 * @property-read ServiceOrder|null $originOrder
 */
#[Fillable([
    'customer_id',
    'origin_order_id',
    'device_code',
    'name',
    'category',
    'brand',
    'model',
    'serial_masked',
    'serial_number_hash',
    'location',
    'status',
    'notes',
    'passport_token_hash',
    'last_serviced_at',
    'registered_at',
])]
class CustomerAsset extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'last_serviced_at' => 'datetime',
            'registered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<ServiceOrder, $this> */
    public function originOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'origin_order_id');
    }

    /** @return HasMany<ServiceOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class, 'customer_asset_id')->latest();
    }

    /** @return HasMany<AssetServiceRecord, $this> */
    public function serviceRecords(): HasMany
    {
        return $this->hasMany(AssetServiceRecord::class, 'customer_asset_id')->latest('id');
    }

    /** @return HasMany<Warranty, $this> */
    public function warranties(): HasMany
    {
        return $this->hasMany(Warranty::class, 'customer_asset_id')->latest('id');
    }

    /** @return HasMany<WarrantyClaim, $this> */
    public function warrantyClaims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class, 'customer_asset_id')->latest('submitted_at');
    }

    public static function normalizeSerial(?string $serial): ?string
    {
        if ($serial === null) {
            return null;
        }

        $clean = mb_strtoupper((string) preg_replace('/\s+/', '', trim($serial)));

        return $clean === '' ? null : $clean;
    }

    public static function hashSerial(?string $normalizedSerial): ?string
    {
        if ($normalizedSerial === null || $normalizedSerial === '') {
            return null;
        }

        return hash('sha256', $normalizedSerial);
    }

    public static function maskSerial(?string $normalizedSerial): ?string
    {
        if ($normalizedSerial === null || $normalizedSerial === '') {
            return null;
        }

        $len = strlen($normalizedSerial);
        if ($len <= 4) {
            return str_repeat('*', max(0, $len - 1)).substr($normalizedSerial, -1);
        }

        return substr($normalizedSerial, 0, 2)
            .str_repeat('*', max(2, $len - 4))
            .substr($normalizedSerial, -2);
    }
}
