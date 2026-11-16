<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $driver
 * @property string $name
 * @property string|null $base_url
 * @property string|null $api_token
 * @property string|null $model_id
 * @property string|null $model_display_name
 * @property array<int, array{id: string, name: string}>|null $discovered_models
 * @property bool $is_active
 * @property bool $is_default
 * @property string $connection_status
 * @property string|null $last_error
 * @property Carbon|null $last_validated_at
 */
class AiProvider extends Model
{
    /** @var list<string> */
    public const DRIVERS = [
        'openai',
        'gemini',
        'anthropic',
        'openrouter',
        'custom_openai',
    ];

    /** @var list<string> */
    protected $fillable = [
        'driver',
        'name',
        'base_url',
        'api_token',
        'model_id',
        'model_display_name',
        'discovered_models',
        'is_active',
        'is_default',
        'connection_status',
        'last_error',
        'last_validated_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'api_token',
    ];

    public static function driverLabel(string $driver): string
    {
        return match ($driver) {
            'openai' => 'OpenAI',
            'gemini' => 'Google Gemini',
            'anthropic' => 'Anthropic Claude',
            'openrouter' => 'OpenRouter',
            'custom_openai' => 'Custom OpenAI-Compatible',
            default => strtoupper($driver),
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'discovered_models' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'last_validated_at' => 'datetime',
        ];
    }
}
