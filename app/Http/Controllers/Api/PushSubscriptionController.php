<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    /**
     * Get VAPID public key
     */
    public function key()
    {
        return response()->json([
            'publicKey' => config('webpush.vapid.public_key'),
        ]);
    }

    /**
     * Store or update push subscription for authenticated user
     */
    public function subscribe(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => 'required|string',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
            'content_encoding' => 'nullable|string',
        ]);

        $user = $request->user();

        $user->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['content_encoding'] ?? 'aesgcm'
        );

        return response()->json([
            'message' => 'Dispositivo inscrito com sucesso para notificações push.',
        ], 201);
    }

    /**
     * Remove push subscription for this endpoint
     */
    public function unsubscribe(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => 'required|string',
        ]);

        $user = $request->user();
        $user->deletePushSubscription($validated['endpoint']);

        return response()->json([
            'message' => 'Dispositivo desinscrito com sucesso.',
        ]);
    }

    /**
     * Send a test push notification to user's registered devices
     */
    public function test(Request $request)
    {
        $user = $request->user();

        $subscriptionsCount = $user->pushSubscriptions()->count();
        if ($subscriptionsCount === 0) {
            return response()->json([
                'message' => 'Nenhum dispositivo registrado para notificações push neste usuário.',
                'subscriptions_count' => 0,
            ], 422);
        }

        try {
            $user->notify(new \App\Notifications\GenericWebPushNotification(
                title: '🧪 Teste CapiHouse',
                body: 'Parabéns! Suas notificações push estão funcionando perfeitamente.',
                url: '/profile',
                data: [
                    'test' => true,
                    'timestamp' => now()->toISOString(),
                ]
            ));

            return response()->json([
                'message' => 'Notificação de teste enviada com sucesso!',
                'subscriptions_count' => $subscriptionsCount,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Falha ao enviar notificação de teste: ' . $e->getMessage(),
            ], 500);
        }
    }
}
