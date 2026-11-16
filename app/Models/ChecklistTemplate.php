<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $service_id
 * @property string $item_key
 * @property string $title
 * @property string|null $description
 * @property string|null $standard_code
 * @property bool $requires_evidence
 * @property bool $is_required
 * @property int $sort_order
 */
#[Fillable(['service_id', 'item_key', 'title', 'description', 'standard_code', 'requires_evidence', 'is_required', 'sort_order'])]
class ChecklistTemplate extends Model
{
    protected function casts(): array
    {
        return [
            'requires_evidence' => 'boolean',
            'is_required' => 'boolean',
        ];
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
