<?php

namespace App\Models;

use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $slug
 * @property string $name
 * @property int|null $starting_price
 * @property string $service_mode
 * @property string $risk_level
 * @property int $warranty_days
 * @property bool $is_active
 */
#[Fillable(['code', 'slug', 'name', 'summary', 'description', 'starting_price', 'pricing_label', 'service_mode', 'risk_level', 'icon', 'warranty_days', 'outcomes', 'is_active', 'sort_order'])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starting_price' => 'integer',
            'warranty_days' => 'integer',
            'outcomes' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<ServiceOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class);
    }

    /** @return HasMany<ChecklistTemplate, $this> */
    public function checklistTemplates(): HasMany
    {
        return $this->hasMany(ChecklistTemplate::class)->orderBy('sort_order');
    }
}
