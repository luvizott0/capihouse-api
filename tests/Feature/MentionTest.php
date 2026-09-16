<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\AppNotification;
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
}