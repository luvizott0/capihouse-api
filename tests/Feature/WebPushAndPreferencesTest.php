<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\PushSubscription;
use Tests\TestCase;

class WebPushAndPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function createApprovedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => \App\Enums\UserStatuses::APPROVED,
        ], $attributes));
    }

    public function test_can_fetch_vapid_public_key(): void
    {
        $user = $this->createApprovedUser();

        $response = $this->actingAs($user)->getJson('/api/push/key');

        $response->assertStatus(200);
        $response->assertJsonStructure(['publicKey']);
    }

    public function test_can_subscribe_device_for_push(): void
    {
        $user = $this->createApprovedUser();

        $payload = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/fake-endpoint-test-123',
            'keys' => [
                'p256dh' => 'BDW6eNq3a3K8X0s123...',
                'auth' => 'authSecret123',
            ],
            'content_encoding' => 'aesgcm',
        ];

        $response = $this->actingAs($user)->postJson('/api/push/subscribe', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_id' => $user->id,
            'endpoint' => $payload['endpoint'],
        ]);
    }

    public function test_can_unsubscribe_device(): void
    {
        $user = $this->createApprovedUser();

        $user->updatePushSubscription(
            'https://fcm.googleapis.com/fcm/send/fake-endpoint-to-remove',
            'dummy-key',
            'dummy-auth'
        );

        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_id' => $user->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/fake-endpoint-to-remove',
        ]);

        $response = $this->actingAs($user)->postJson('/api/push/unsubscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/fake-endpoint-to-remove',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('push_subscriptions', [
            'subscribable_id' => $user->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/fake-endpoint-to-remove',
        ]);
    }

    public function test_can_get_and_update_notification_preferences(): void
    {
        $user = $this->createApprovedUser();

        $response = $this->actingAs($user)->getJson('/api/user/notification-preferences');

        $response->assertStatus(200);
        $response->assertJson([
            'preferences' => [
                'likes' => true,
                'comments' => true,
                'mentions' => true,
                'group_invites' => true,
                'event_invites' => true,
            ],
            'subscriptions_count' => 0,
        ]);

        // Disable likes and mentions
        $updateResponse = $this->actingAs($user)->putJson('/api/user/notification-preferences', [
            'likes' => false,
            'mentions' => false,
        ]);

        $updateResponse->assertStatus(200);
        $updateResponse->assertJson([
            'preferences' => [
                'likes' => false,
                'comments' => true,
                'mentions' => false,
                'group_invites' => true,
                'event_invites' => true,
            ],
        ]);

        $user->refresh();
        $this->assertFalse($user->wantsNotificationFor('post_like'));
        $this->assertTrue($user->wantsNotificationFor('post_comment'));
        $this->assertFalse($user->wantsNotificationFor('post_mention'));
    }

    public function test_push_test_endpoint_validates_subscriptions(): void
    {
        $user = $this->createApprovedUser();

        // No subscriptions yet
        $response = $this->actingAs($user)->postJson('/api/push/test');
        $response->assertStatus(422);

        // Add a subscription
        $user->updatePushSubscription('https://endpoint', 'key', 'auth');

        Notification::fake();

        $response = $this->actingAs($user)->postJson('/api/push/test');
        $response->assertStatus(200);

        Notification::assertSentTo($user, \App\Notifications\GenericWebPushNotification::class);
    }
}
