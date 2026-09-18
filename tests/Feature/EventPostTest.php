<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\Event;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventPostTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ], $attributes));
    }

    public function test_event_show_returns_event_details_to_invited_and_organizer()
    {
        $organizer = $this->createApprovedUser(['name' => 'Organizador']);
        $guest = $this->createApprovedUser(['name' => 'Convidado']);
        $outsider = $this->createApprovedUser(['name' => 'De Fora']);

        $event = Event::create([
            'name' => 'Noite de Jogos',
            'description' => 'Tragam seus boardgames favoritos!',
            'date' => now()->addDays(2),
            'user_id' => $organizer->id,
        ]);
        $event->guests()->attach($guest->id, ['status' => 'invited']);

        // Organizer can view
        $response = $this->actingAs($organizer)->getJson("/api/events/{$event->id}");
        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'Noite de Jogos']);

        // Guest can view
        $response = $this->actingAs($guest)->getJson("/api/events/{$event->id}");
        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'Noite de Jogos']);

        // Outsider cannot view
        $response = $this->actingAs($outsider)->getJson("/api/events/{$event->id}");
        $response->assertStatus(403);
    }

    public function test_organizer_can_invite_more_guests()
    {
        $organizer = $this->createApprovedUser(['name' => 'Organizador']);
        $newGuest = $this->createApprovedUser(['name' => 'Novo Amigo']);

        $event = Event::create([
            'name' => 'Festa Surpresa',
            'description' => 'Festa secreta',
            'date' => now()->addDays(5),
            'user_id' => $organizer->id,
        ]);

        $response = $this->actingAs($organizer)->postJson("/api/events/{$event->id}/invite", [
            'guests' => [$newGuest->id],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('event_users', [
            'event_id' => $event->id,
            'user_id' => $newGuest->id,
        ]);
    }

    public function test_invited_and_organizer_can_create_post_in_event()
    {
        $organizer = $this->createApprovedUser(['name' => 'Organizador']);
        $guest = $this->createApprovedUser(['name' => 'Convidado']);
        $outsider = $this->createApprovedUser(['name' => 'Intruso']);

        $event = Event::create([
            'name' => 'Piquenique no Parque',
            'description' => 'Levar toalha e comidinhas',
            'date' => now()->addDays(4),
            'user_id' => $organizer->id,
        ]);
        $event->guests()->attach($guest->id, ['status' => 'confirmed']);

        // Guest creates post in event
        $guestPostResponse = $this->actingAs($guest)->postJson('/api/posts', [
            'event_id' => $event->id,
            'content' => 'Vou levar refrigerante e copos descartáveis!',
        ]);
        $guestPostResponse->assertStatus(201);
        $guestPostId = $guestPostResponse->json('id');

        $this->assertDatabaseHas('posts', [
            'id' => $guestPostId,
            'event_id' => $event->id,
            'user_id' => $guest->id,
        ]);

        // Outsider attempts to create post in event -> 403
        $outsiderPostResponse = $this->actingAs($outsider)->postJson('/api/posts', [
            'event_id' => $event->id,
            'content' => 'Posso ir também?',
        ]);
        $outsiderPostResponse->assertStatus(403);
    }

    public function test_event_posts_are_exclusive_to_event_and_hidden_from_general_feed()
    {
        $organizer = $this->createApprovedUser(['name' => 'Organizador']);
        $guest = $this->createApprovedUser(['name' => 'Convidado']);
        $outsider = $this->createApprovedUser(['name' => 'Outro Usuario']);

        $event = Event::create([
            'name' => 'Churrasco Exclusivo',
            'description' => 'Apenas convidados',
            'date' => now()->addDays(1),
            'user_id' => $organizer->id,
        ]);
        $event->guests()->attach($guest->id, ['status' => 'confirmed']);

        // Public general post
        $publicPost = Post::create([
            'user_id' => $outsider->id,
            'content' => 'Bom dia galera!',
        ]);

        // Event post
        $eventPost = Post::create([
            'user_id' => $organizer->id,
            'event_id' => $event->id,
            'content' => 'Não se esqueçam da carne!',
        ]);

        // General feed for outsider should only see public post, NOT event post
        $generalFeedResponse = $this->actingAs($outsider)->getJson('/api/posts');
        $generalFeedResponse->assertStatus(200);
        $generalFeedIds = collect($generalFeedResponse->json('data'))->pluck('id')->all();
        $this->assertContains($publicPost->id, $generalFeedIds);
        $this->assertNotContains($eventPost->id, $generalFeedIds);

        // Event feed for invited guest should see event post
        $eventFeedResponse = $this->actingAs($guest)->getJson("/api/posts?event_id={$event->id}");
        $eventFeedResponse->assertStatus(200);
        $eventFeedIds = collect($eventFeedResponse->json('data'))->pluck('id')->all();
        $this->assertContains($eventPost->id, $eventFeedIds);
        $this->assertNotContains($publicPost->id, $eventFeedIds);

        // Event feed for outsider -> 403 Forbidden
        $outsiderEventFeed = $this->actingAs($outsider)->getJson("/api/posts?event_id={$event->id}");
        $outsiderEventFeed->assertStatus(403);
    }
}
