<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssistantSetting extends Model
{
    protected $fillable = [
        'provider',
        'model',
        'api_key',
        'is_enabled',
        'base_url',
        'max_output_tokens',
        'custom_instructions',
    ];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_enabled' => 'boolean',
            'max_output_tokens' => 'integer',
        ];
    }
}
