<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class UpdateUserProfile
{
    /** @param array<string, mixed> $attributes */
    public function handle(User $user, array $attributes, ?UploadedFile $avatar = null, bool $removeAvatar = false): User
    {
        $oldAvatarPath = $user->avatar_path;
        $newAvatarPath = null;

        if ($avatar !== null) {
            $newAvatarPath = $avatar->store('profile-photos', 'public');

            if ($newAvatarPath === false) {
                throw new RuntimeException('Foto profil gagal disimpan.');
            }
        }

        $profile = Arr::only($attributes, [
            'name', 'username', 'email', 'phone', 'address', 'bio', 'specialty', 'show_phone_publicly',
        ]);

        if ($user->role !== 'technician') {
            unset($profile['specialty']);
            $profile['show_phone_publicly'] = false;
        }

        if ($newAvatarPath !== null) {
            $profile['avatar_path'] = $newAvatarPath;
            $profile['avatar_url'] = Storage::disk('public')->url($newAvatarPath);
        } elseif ($removeAvatar) {
            $profile['avatar_path'] = null;
            $profile['avatar_url'] = $user->socialAccounts()->value('avatar_url');
        }

        try {
            $user->fill($profile);

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            $user->save();
        } catch (Throwable $exception) {
            if ($newAvatarPath !== null) {
                Storage::disk('public')->delete($newAvatarPath);
            }

            throw $exception;
        }

        if ($oldAvatarPath !== null && ($newAvatarPath !== null || $removeAvatar)) {
            Storage::disk('public')->delete($oldAvatarPath);
        }

        return $user;
    }
}
