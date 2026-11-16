<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $service_order_id
 * @property int $uploaded_by
 * @property string $stage
 * @property string|null $file_path
 * @property string|null $original_name
 * @property string|null $mime_type
 * @property int|null $file_size
 * @property string|null $caption
 * @property Carbon|null $customer_confirmed_at
 * @property int|null $confirmed_by
 * @property string|null $confirmation_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'service_order_id',
    'uploaded_by',
    'stage',
    'file_path',
    'original_name',
    'mime_type',
    'file_size',
    'caption',
    'customer_confirmed_at',
    'confirmed_by',
    'confirmation_note',
])]
#[Hidden(['file_path'])]
class OrderWorkEvidence extends Model
{
    protected $table = 'order_work_evidences';

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'customer_confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ServiceOrder, $this> */
    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
