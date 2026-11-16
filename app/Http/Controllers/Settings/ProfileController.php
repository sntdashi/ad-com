<?php

namespace App\Http\Controllers\Settings;

use App\Actions\UpdateUserProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Services\FonnteMessageSender;
use App\Services\Otp\OtpConfig;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'profileCompletion' => $this->profileCompletion($request),
            'phoneChangeOtpEnabled' => OtpConfig::isPurposeEnabled('phone_change')
                && app(FonnteMessageSender::class)->isConfigured(),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, UpdateUserProfile $updateProfile): RedirectResponse
    {
        $updateProfile->handle($request->user(), $request->validated(), $request->avatar(), $request->boolean('remove_avatar'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function profileCompletion(Request $request): int
    {
        $user = $request->user();
        $fields = [
            $user->name,
            $user->email,
            $user->username,
            $user->phone,
            $user->address,
            $user->bio,
            $user->avatar_url,
        ];

        if ($user->role === 'technician') {
            $fields[] = $user->specialty;
        }

        return (int) round(collect($fields)->filter(fn (mixed $value): bool => filled($value))->count() / count($fields) * 100);
    }
}
