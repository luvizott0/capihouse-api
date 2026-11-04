<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\AppNotification;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventInviteNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ], $attributes));
    }

    public function test_creating_event_with_guests_dispatches_event_invite_notification()
    {
        $organizer = $this->createApprovedUser(['name' => 'Capivara Chefe']);
        $guest1 = $this->createApprovedUser(['name' => 'Amigo Um']);
        $guest2 = $this->createApprovedUser(['name' => 'Amigo Dois']);

        $response = $this->actingAs($organizer)->postJson('/api/events', [
            'name' => 'Lual da Capivara',
            'description' => 'Música acústica e fogueira',
            'date' => now()->addDays(3)->toIso8601String(),
            'guests' => [$guest1->id, $guest2->id, $organizer->id],
        ]);

        $response->assertStatus(201);
        $eventId = $response->json('id');

        // Organizer should NOT receive notification
        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $organizer->id,
            'type' => 'event_invite',
        ]);

        // Guest 1 should receive notification
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $guest1->id,
            'type' => 'event_invite',
            'title' => 'Convite para Evento',
        ]);

        // Guest 2 should receive notification
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $guest2->id,
            'type' => 'event_invite',
            'title' => 'Convite para Evento',
        ]);

        $notification = AppNotification::where('user_id', $guest1->id)->first();
        $this->assertStringContainsString('Capivara Chefe convidou você para o evento "Lual da Capivara"', $notification->content);
        $this->assertEquals($eventId, $notification->data['event_id']);
        $this->assertEquals('Lual da Capivara', $notification->data['event_name']);
    }

    public function test_inviting_guests_via_endpoint_dispatches_notification_only_to_new_guests()
    {
        $organizer = $this->createApprovedUser(['name' => 'Organizador']);
        $existingGuest = $this->createApprovedUser(['name' => 'Convidado Existente']);
        $newGuest = $this->createApprovedUser(['name' => 'Novo Convidado']);

        $event = Event::create([
            'name' => 'Noite de Pizza',
            'description' => 'Rodízio caseiro',
            'date' => now()->addDays(2),
            'user_id' => $organizer->id,
        ]);
        $event->guests()->attach($existingGuest->id, ['status' => 'invited']);

        // Post invite with both existing and new guest
        $response = $this->actingAs($organizer)->postJson("/api/events/{$event->id}/invite", [
            'guests' => [$existingGuest->id, $newGuest->id],
        ]);

        $response->assertStatus(200);

        // Existing guest should NOT be notified again
        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $existingGuest->id,
            'type' => 'event_invite',
        ]);

        // New guest must be notified
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $newGuest->id,
            'type' => 'event_invite',
            'title' => 'Convite para Evento',
        ]);

        $notif = AppNotification::where('user_id', $newGuest->id)->first();
        $this->assertStringContainsString('Organizador convidou você para o evento "Noite de Pizza"', $notif->content);
    }

    public function test_updating_event_guests_notifies_only_newly_added_guests()
    {
        $organizer = $this->createApprovedUser(['name' => 'Organizador']);
        $firstGuest = $this->createApprovedUser(['name' => 'Primeiro Convidado']);
        $secondGuest = $this->createApprovedUser(['name' => 'Segundo Convidado']);

        $event = Event::create([
            'name' => 'Sessão Cinema',
            'description' => 'Filme cult',
            'date' => now()->addDays(4),
            'user_id' => $organizer->id,
        ]);
        $event->guests()->attach($firstGuest->id, ['status' => 'invited']);

        $response = $this->actingAs($organizer)->postJson("/api/events/{$event->id}", [
            'name' => 'Sessão Cinema VIP',
            'description' => 'Filme cult e pipoca',
            'date' => now()->addDays(4)->toIso8601String(),
            'guests' => [$firstGuest->id, $secondGuest->id],
        ]);

        $response->assertStatus(200);

        // First guest was already in the list
        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $firstGuest->id,
            'type' => 'event_invite',
        ]);

        // Second guest is newly added
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $secondGuest->id,
            'type' => 'event_invite',
        ]);
    }

    public function test_notifications_category_filter_and_counts_include_event_invites()
    {
        $user = $this->createApprovedUser(['name' => 'Destinatario']);

        AppNotification::create([
            'user_id' => $user->id,
            'type' => 'event_invite',
            'title' => 'Convite para Evento',
            'content' => 'Você foi convidado para o evento A',
            'data' => ['event_id' => 10],
        ]);

        AppNotification::create([
            'user_id' => $user->id,
            'type' => 'event_rsvp',
            'title' => 'Confirmação de Presença',
            'content' => 'Alguém confirmou no seu evento',
            'data' => ['event_id' => 11],
        ]);

        AppNotification::create([
            'user_id' => $user->id,
            'type' => 'post_like',
            'title' => 'Curtida',
            'content' => 'Curtiram seu post',
            'data' => ['post_id' => 20],
        ]);

        // Category counts
        $countsResponse = $this->actingAs($user)->getJson('/api/notifications/category-counts');
        $countsResponse->assertStatus(200);
        $countsResponse->assertJsonFragment([
            'events' => 2,
            'likes' => 1,
        ]);

        // Category filter 'events' returns both event_invite and event_rsvp
        $eventsListResponse = $this->actingAs($user)->getJson('/api/notifications?category=events');
        $eventsListResponse->assertStatus(200);
        $this->assertCount(2, $eventsListResponse->json('data'));

        $types = collect($eventsListResponse->json('data'))->pluck('type')->toArray();
        $this->assertContains('event_invite', $types);
        $this->assertContains('event_rsvp', $types);
    }
}
