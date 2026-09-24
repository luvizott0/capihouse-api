<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MentionTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ], $attributes));
    }

    public function test_user_can_mention_another_user_in_post()
    {
        $author = $this->createApprovedUser(['username' => 'alice']);
        $mentioned = $this->createApprovedUser(['username' => 'bob']);

        $response = $this->actingAs($author)->postJson('/api/posts', [
            'content' => 'Olá @bob, veja esta postagem!',
        ]);

        $response->assertStatus(201);
        $postId = $response->json('id');

        $this->assertDatabaseHas('post_mentions', [
            'post_id' => $postId,
            'user_id' => $mentioned->id,
        ]);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $mentioned->id,
            'type' => 'post_mention',
        ]);
    }

    public function test_self_mention_in_post_does_not_create_notification()
    {
        $author = $this->createApprovedUser(['username' => 'alice']);

        $response = $this->actingAs($author)->postJson('/api/posts', [
            'content' => 'Olá para mim mesma @alice!',
        ]);

        $response->assertStatus(201);
        $postId = $response->json('id');

        $this->assertDatabaseMissing('post_mentions', [
            'post_id' => $postId,
            'user_id' => $author->id,
        ]);

        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $author->id,
            'type' => 'post_mention',
        ]);
    }

    public function test_post_with_mention_appears_in_mentioned_user_profile_query()
    {
        $author = $this->createApprovedUser(['username' => 'alice']);
        $mentioned = $this->createApprovedUser(['username' => 'bob']);

        $post = $this->actingAs($author)->postJson('/api/posts', [
            'content' => 'Grande abraço para @bob na casa!',
        ])->json();

        // Query posts by bob's user_id
        $response = $this->actingAs($mentioned)->getJson("/api/posts?user_id={$mentioned->id}");

        $response->assertStatus(200);
        $responsePostIds = collect($response->json('data'))->pluck('id')->toArray();

        $this->assertContains($post['id'], $responsePostIds);
    }

    public function test_user_can_mention_another_user_in_comment()
    {
        $author = $this->createApprovedUser(['username' => 'alice']);
        $commenter = $this->createApprovedUser(['username' => 'bob']);
        $friend = $this->createApprovedUser(['username' => 'charlie']);

        $post = Post::create([
            'user_id' => $author->id,
            'content' => 'Post de teste da alice',
        ]);

        $response = $this->actingAs($commenter)->postJson("/api/posts/{$post->id}/comments", [
            'content' => 'Olha isso @charlie!',
        ]);

        $response->assertStatus(201);
        $commentId = $response->json('id');

        $this->assertDatabaseHas('post_comment_mentions', [
            'post_comment_id' => $commentId,
            'user_id' => $friend->id,
        ]);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $friend->id,
            'type' => 'comment_mention',
        ]);
    }

    public function test_mention_todos_in_post_notifies_all_approved_users_without_attaching_to_post_mentions()
    {
        $author = $this->createApprovedUser(['username' => 'alice']);
        $bob = $this->createApprovedUser(['username' => 'bob']);
        $charlie = $this->createApprovedUser(['username' => 'charlie']);

        $response = $this->actingAs($author)->postJson('/api/posts', [
            'content' => 'Atenção @todos, teremos reunião na cozinha hoje às 20h!',
        ]);

        $response->assertStatus(201);
        $postId = $response->json('id');

        // Post mentions pivot table should be empty (no users attached directly)
        $this->assertDatabaseMissing('post_mentions', [
            'post_id' => $postId,
        ]);

        // Author should not be notified
        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $author->id,
            'type' => 'post_mention',
        ]);

        // Other approved users should be notified
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $bob->id,
            'type' => 'post_mention',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $charlie->id,
            'type' => 'post_mention',
        ]);

        // Post should NOT appear in bob's profile query because he was not directly attached
        $profileResponse = $this->actingAs($bob)->getJson("/api/posts?user_id={$bob->id}");
        $profileResponse->assertStatus(200);
        $postIds = collect($profileResponse->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($postId, $postIds);
    }

    public function test_mention_todos_with_specific_user_mentions_attaches_only_specific_user_and_deduplicates()
    {
        $author = $this->createApprovedUser(['username' => 'alice']);
        $bob = $this->createApprovedUser(['username' => 'bob']);
        $charlie = $this->createApprovedUser(['username' => 'charlie']);

        $response = $this->actingAs($author)->postJson('/api/posts', [
            'content' => 'Alô @bob e @todos, vejam isso!',
        ]);

        $response->assertStatus(201);
        $postId = $response->json('id');

        // Bob should be in post_mentions, but charlie should NOT
        $this->assertDatabaseHas('post_mentions', [
            'post_id' => $postId,
            'user_id' => $bob->id,
        ]);
        $this->assertDatabaseMissing('post_mentions', [
            'post_id' => $postId,
            'user_id' => $charlie->id,
        ]);

        // Bob should only receive ONE notification (the individual one), not two
        $bobNotifications = \App\Models\AppNotification::where('user_id', $bob->id)->get();
        $this->assertCount(1, $bobNotifications);

        // Charlie receives the @todos notification
        $charlieNotifications = \App\Models\AppNotification::where('user_id', $charlie->id)->get();
        $this->assertCount(1, $charlieNotifications);
    }

    public function test_mention_todos_in_comment_notifies_all_approved_users()
    {
        $author = $this->createApprovedUser(['username' => 'alice']);
        $bob = $this->createApprovedUser(['username' => 'bob']);
        $charlie = $this->createApprovedUser(['username' => 'charlie']);

        $post = Post::create([
            'user_id' => $author->id,
            'content' => 'Publicação normal',
        ]);

        $response = $this->actingAs($bob)->postJson("/api/posts/{$post->id}/comments", [
            'content' => 'Comentário importante para @todos!',
        ]);

        $response->assertStatus(201);

        // Bob (commenter) should not receive notification
        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $bob->id,
            'type' => 'comment_mention',
        ]);

        // Alice and Charlie should be notified
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $author->id,
            'type' => 'comment_mention',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $charlie->id,
            'type' => 'comment_mention',
        ]);
    }

    public function test_cannot_register_with_todos_username()
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Conta Todos',
            'username' => 'todos',
            'email' => 'todos@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['username']);
    }
}
