<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\Event;
use App\Models\Group;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchFilterTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ], $attributes));
    }

    public function test_filter_posts_by_query_date_and_user()
    {
        $userA = $this->createApprovedUser(['name' => 'Alice Silva', 'username' => 'alice']);
        $userB = $this->createApprovedUser(['name' => 'Bob Santos', 'username' => 'bob']);

        $post1 = Post::create([
            'user_id' => $userA->id,
            'content' => 'Comendo pastel no centro da cidade',
        ]);
        $post1->created_at = '2026-09-10 10:00:00';
        $post1->save();

        $post2 = Post::create([
            'user_id' => $userB->id,
            'content' => 'Trabalhando no novo projeto de capivara',
        ]);
        $post2->created_at = '2026-09-15 14:00:00';
        $post2->save();

        // 1. Search by content query
        $res = $this->actingAs($userA)->getJson('/api/posts?q=pastel');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals($post1->id, $res->json('data.0.id'));

        // 2. Search by author name query
        $res = $this->actingAs($userA)->getJson('/api/posts?q=Bob');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals($post2->id, $res->json('data.0.id'));

        // 3. Search by date
        $res = $this->actingAs($userA)->getJson('/api/posts?date=2026-09-15');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals($post2->id, $res->json('data.0.id'));

        // 4. Search by user_id
        $res = $this->actingAs($userA)->getJson("/api/posts?user_id={$userA->id}");
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals($post1->id, $res->json('data.0.id'));
    }

    public function test_filter_events_by_query_date_and_user()
    {
        $userA = $this->createApprovedUser(['name' => 'Alice Silva', 'username' => 'alice']);
        $userB = $this->createApprovedUser(['name' => 'Bob Santos', 'username' => 'bob']);

        $event1 = Event::create([
            'user_id' => $userA->id,
            'name' => 'Churrasco dos Amigos',
            'description' => 'Tragam bebidas e alegria',
            'date' => '2026-09-20 18:00:00',
        ]);

        $event2 = Event::create([
            'user_id' => $userB->id,
            'name' => 'Noite da Pizza',
            'description' => 'Massa caseira e jogos',
            'date' => '2026-09-25 19:00:00',
        ]);
        $event2->guests()->attach($userA->id, ['status' => 'confirmed']);

        // 1. Search by query
        $res = $this->actingAs($userA)->getJson('/api/events?q=Churrasco');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals($event1->id, $res->json('data.0.id'));

        // 2. Search by date
        $res = $this->actingAs($userA)->getJson('/api/events?date=2026-09-25');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals($event2->id, $res->json('data.0.id'));

        // 3. Search by user_id
        $res = $this->actingAs($userA)->getJson("/api/events?user_id={$userB->id}");
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals($event2->id, $res->json('data.0.id'));
    }

    public function test_filter_groups_by_query_date_and_user()
    {
        $user = $this->createApprovedUser();
        $otherUser = $this->createApprovedUser();

        $group1 = Group::create([
            'name' => 'Devs de Curitiba',
            'description' => 'Grupo para devs locais',
            'creator_id' => $user->id,
        ]);
        $group1->created_at = '2026-09-01 10:00:00';
        $group1->save();
        $group1->members()->attach($user->id, ['role' => 'owner', 'status' => 'accepted']);

        $group2 = Group::create([
            'name' => 'Gamers Noturnos',
            'description' => 'Jogatina toda sexta',
            'creator_id' => $otherUser->id,
        ]);
        $group2->created_at = '2026-09-05 12:00:00';
        $group2->save();
        $group2->members()->attach([
            $otherUser->id => ['role' => 'owner', 'status' => 'accepted'],
            $user->id => ['role' => 'member', 'status' => 'accepted'],
        ]);

        // 1. Search by query
        $res = $this->actingAs($user)->getJson('/api/groups?q=Curitiba');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals($group1->id, $res->json('data.0.id'));

        // 2. Search by date
        $res = $this->actingAs($user)->getJson('/api/groups?date=2026-09-05');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals($group2->id, $res->json('data.0.id'));

        // 3. Search by user_id (creator)
        $res = $this->actingAs($user)->getJson("/api/groups?user_id={$user->id}");
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals($group1->id, $res->json('data.0.id'));
    }
}
