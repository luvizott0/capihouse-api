<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    /**
     * Get user notification preferences
     */
    public function show(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'preferences' => $user->getEffectiveNotificationPreferences(),
            'subscriptions_count' => $user->pushSubscriptions()->count(),
        ]);
    }

    /**
     * Update user notification preferences
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'likes' => 'sometimes|boolean',
            'comments' => 'sometimes|boolean',
            'mentions' => 'sometimes|boolean',
            'group_invites' => 'sometimes|boolean',
            'event_invites' => 'sometimes|boolean',
        ]);

        $user = $request->user();
        $currentPrefs = $user->getEffectiveNotificationPreferences();
        $mergedPrefs = array_merge($currentPrefs, $validated);

        $user->notification_preferences = $mergedPrefs;
        $user->save();

        return response()->json([
            'message' => 'Preferências de notificações atualizadas com sucesso.',
            'preferences' => $user->getEffectiveNotificationPreferences(),
        ]);
    }
}
