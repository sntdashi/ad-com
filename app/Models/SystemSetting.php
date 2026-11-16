<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class SystemSetting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function getEncrypted(string $key): ?string
    {
        $raw = self::query()->where('key', $key)->value('value');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Crypt::decryptString($raw);
        } catch (DecryptException) {
            return null;
        }
    }

    public static function putEncrypted(string $key, ?string $plain): void
    {
        if ($plain === null || trim($plain) === '') {
            self::query()->where('key', $key)->delete();

            return;
        }

        self::query()->updateOrCreate(
            ['key' => $key],
            ['value' => Crypt::encryptString(trim($plain))],
        );
    }

    /**
     * @param  array<string, string|null>  $defaults
     * @return array<string, string>
     */
    public static function getMany(array $defaults): array
    {
        $stored = self::query()
            ->whereIn('key', array_keys($defaults))
            ->pluck('value', 'key')
            ->all();

        $resolved = [];

        foreach ($defaults as $key => $default) {
            $resolved[$key] = (string) ($stored[$key] ?? $default ?? '');
        }

        return $resolved;
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public static function putMany(array $values): void
    {
        foreach ($values as $key => $value) {
            self::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value],
            );
        }
    }
}
