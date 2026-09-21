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

    public function test_filter_by_date_range_across_entities()
    {
        $user = $this->createApprovedUser(['name' => 'Carlos Lima', 'username' => 'carlos']);

        // Create posts with different dates
        $p1 = Post::create(['user_id' => $user->id, 'content' => 'Post de agosto']);
        $p1->created_at = '2026-08-15 12:00:00';
        $p1->save();

        $p2 = Post::create(['user_id' => $user->id, 'content' => 'Post de setembro início']);
        $p2->created_at = '2026-09-02 08:00:00';
        $p2->save();

        $p3 = Post::create(['user_id' => $user->id, 'content' => 'Post de setembro fim']);
        $p3->created_at = '2026-09-28 20:00:00';
        $p3->save();

        // 1. Posts date range: both start and end
        $res = $this->actingAs($user)->getJson('/api/posts?start_date=2026-09-01&end_date=2026-09-15');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals($p2->id, $res->json('data.0.id'));

        // 2. Posts date range: only start_date
        $res = $this->actingAs($user)->getJson('/api/posts?start_date=2026-09-20');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals($p3->id, $res->json('data.0.id'));

        // 3. Posts date range: only end_date
        $res = $this->actingAs($user)->getJson('/api/posts?end_date=2026-08-31');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals($p1->id, $res->json('data.0.id'));

        // Events date range
        $e1 = Event::create([
            'user_id' => $user->id,
            'name' => 'Meetup 1',
            'description' => 'Desc',
            'date' => '2026-10-05 14:00:00',
        ]);
        $e2 = Event::create([
            'user_id' => $user->id,
            'name' => 'Meetup 2',
            'description' => 'Desc',
            'date' => '2026-10-20 18:00:00',
        ]);

        $res = $this->actingAs($user)->getJson('/api/events?start_date=2026-10-01&end_date=2026-10-10');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals($e1->id, $res->json('data.0.id'));

        // Groups date range
        $g1 = Group::create(['name' => 'Grupo Antigo', 'description' => 'D', 'creator_id' => $user->id]);
        $g1->created_at = '2026-07-01 10:00:00';
        $g1->save();
        $g1->members()->attach($user->id, ['role' => 'owner', 'status' => 'accepted']);

        $g2 = Group::create(['name' => 'Grupo Novo', 'description' => 'D', 'creator_id' => $user->id]);
        $g2->created_at = '2026-09-10 10:00:00';
        $g2->save();
        $g2->members()->attach($user->id, ['role' => 'owner', 'status' => 'accepted']);

        $res = $this->actingAs($user)->getJson('/api/groups?start_date=2026-09-01&end_date=2026-09-30');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals($g2->id, $res->json('data.0.id'));
    }
}
