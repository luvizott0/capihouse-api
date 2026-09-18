<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\AppNotification;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventRsvpTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ], $attributes));
    }

    public function test_user_can_confirm_presence_and_notifies_organizer()
    {
        $organizer = $this->createApprovedUser(['name' => 'Organizador']);
        $guest = $this->createApprovedUser(['name' => 'Convidado']);

        $event = Event::create([
            'name' => 'Festa da Capivara',
            'description' => 'Comemoração dos amigos da casa.',
            'date' => now()->addDays(2),
            'user_id' => $organizer->id,
        ]);

        $response = $this->actingAs($guest)->postJson("/api/events/{$event->id}/rsvp", [
            'status' => 'confirmed',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'confirmed']);

        $this->assertDatabaseHas('event_users', [
            'event_id' => $event->id,
            'user_id' => $guest->id,
            'status' => 'confirmed',
        ]);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $organizer->id,
            'type' => 'event_rsvp',
            'title' => 'Confirmação de Presença',
        ]);

        $notification = AppNotification::where('user_id', $organizer->id)->first();
        $this->assertStringContainsString('Convidado confirmou presença no seu evento', $notification->content);
    }

    public function test_user_can_decline_presence_and_notifies_organizer()
    {
        $organizer = $this->createApprovedUser(['name' => 'Organizador']);
        $guest = $this->createApprovedUser(['name' => 'Convidado']);

        $event = Event::create([
            'name' => 'Churrasco de Domingo',
            'description' => 'Churrasco no quintal.',
            'date' => now()->addDays(3),
            'user_id' => $organizer->id,
        ]);

        $response = $this->actingAs($guest)->postJson("/api/events/{$event->id}/rsvp", [
            'status' => 'declined',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('event_users', [
            'event_id' => $event->id,
            'user_id' => $guest->id,
            'status' => 'declined',
        ]);

        $notification = AppNotification::where('user_id', $organizer->id)->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('Convidado informou que não vai ao seu evento', $notification->content);
    }

    public function test_organizer_does_not_receive_notification_if_they_are_the_owner()
    {
        $organizer = $this->createApprovedUser(['name' => 'Organizador']);

        $event = Event::create([
            'name' => 'Reunião da Casa',
            'description' => 'Organização geral.',
            'date' => now()->addDays(1),
            'user_id' => $organizer->id,
        ]);

        $response = $this->actingAs($organizer)->postJson("/api/events/{$event->id}/rsvp", [
            'status' => 'confirmed',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $organizer->id,
            'type' => 'event_rsvp',
        ]);
    }
}
