<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $category
 * @property bool $in_app_enabled
 * @property bool $whatsapp_enabled
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'category',
    'in_app_enabled',
    'whatsapp_enabled',
])]
class UserNotificationPreference extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'in_app_enabled' => 'boolean',
            'whatsapp_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
