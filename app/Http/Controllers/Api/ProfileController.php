<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Requests\UploadAvatarRequest;
use App\Http\Requests\UploadBannerRequest;
use App\Http\Requests\UploadThemeBackgroundRequest;
use App\Http\Resources\MediaResource;
use App\Http\Resources\ProfileResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use App\Enums\MediaType;

class ProfileController extends Controller
{
    public function show()
    {
        $user = auth()->user()->load(['avatar', 'banner', 'interests', 'posts', 'postLikes', 'events']);
        return new ProfileResource($user);
    }

    public function update(UpdateProfileRequest $request)
    {
        $user = auth()->user();
        $user->update($request->validated());

        return new ProfileResource($user->load(['avatar', 'banner', 'interests', 'posts', 'postLikes', 'events']));
    }

    public function uploadAvatar(UploadAvatarRequest $request)
    {
        $user = auth()->user();
        $disk = config('filesystems.default', 'public');
        
        if ($user->avatar) {
            $user->avatar->delete();
        }

        $path = $request->file('avatar')->store("avatars/{$user->id}", $disk);
        
        $media = $user->avatar()->create([
            'path' => $path,
            'type' => MediaType::IMAGE,
            'collection_name' => 'avatar',
        ]);

        $user->update(['avatar_url' => $media->getUrl()]);

        return new MediaResource($media);
    }

    public function uploadBanner(UploadBannerRequest $request)
    {
        $user = auth()->user();
        $disk = config('filesystems.default', 'public');
        
        if ($user->banner) {
            $user->banner->delete();
        }

        $path = $request->file('banner')->store("banners/{$user->id}", $disk);
        
        $media = $user->banner()->create([
            'path' => $path,
            'type' => MediaType::IMAGE,
            'collection_name' => 'banner',
        ]);

        $user->update(['banner_url' => $media->getUrl()]);

        return new MediaResource($media);
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        auth()->user()->update([
            'password' => Hash::make($request->input('password')),
        ]);

        return response()->json(['message' => 'Senha atualizada com sucesso.'], 200);
    }

    public function uploadThemeBackground(UploadThemeBackgroundRequest $request)
    {
        $user = auth()->user();
        $disk = config('filesystems.default', 'public');

        // Delete old theme background from storage if it's a stored file
        $currentTheme = $user->theme ?? [];
        if (
            isset($currentTheme['bg_type'], $currentTheme['bg_value']) &&
            $currentTheme['bg_type'] === 'image' &&
            str_contains($currentTheme['bg_value'], '/theme-backgrounds/')
        ) {
            $relative = preg_replace('/^.*\/storage\//', '', $currentTheme['bg_value']);
            Storage::disk($disk)->delete(ltrim($relative, '/'));
        }

        $path = $request->file('background')->store("theme-backgrounds/{$user->id}", $disk);
        $url  = Storage::disk($disk)->url($path);

        return response()->json(['url' => $url]);
    }

    public function resetTheme()
    {
        $user = auth()->user();

        // Delete stored background image if exists
        $currentTheme = $user->theme ?? [];
        if (
            isset($currentTheme['bg_type'], $currentTheme['bg_value']) &&
            $currentTheme['bg_type'] === 'image' &&
            str_contains($currentTheme['bg_value'], '/theme-backgrounds/')
        ) {
            $disk     = config('filesystems.default', 'public');
            $relative = preg_replace('/^.*\/storage\//', '', $currentTheme['bg_value']);
            Storage::disk($disk)->delete(ltrim($relative, '/'));
        }

        $user->update(['theme' => null]);

        return new ProfileResource($user->load(['avatar', 'banner', 'interests', 'posts', 'postLikes', 'events']));
    }
}
