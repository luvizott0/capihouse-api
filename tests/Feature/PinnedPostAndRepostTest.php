<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PinnedPostAndRepostTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'username' => 'user_'.uniqid(),
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ], $attributes));
    }

    public function test_user_can_repost_another_users_feed_post(): void
    {
        $author = $this->createApprovedUser();
        $otherUser = $this->createApprovedUser();

        $originalPost = Post::create([
            'user_id' => $author->id,
            'content' => 'Meu post original no feed',
            'category' => 'feed',
        ]);

        $res = $this->actingAs($otherUser)->postJson('/api/posts', [
            'content' => 'Comentando o post do amigo!',
            'repost_of_id' => $originalPost->id,
        ]);

        $res->assertStatus(201);
        $repostId = $res->json('data.id') ?? $res->json('id');

        $this->assertDatabaseHas('posts', [
            'id' => $repostId,
            'user_id' => $otherUser->id,
            'repost_of_id' => $originalPost->id,
        ]);

        // Verificar se aparece no feed com reposted_post carregado
        $feed = $this->actingAs($otherUser)->getJson('/api/posts');
        $feed->assertStatus(200);

        $repostInFeed = collect($feed->json('data'))->firstWhere('id', $repostId);
        $this->assertNotNull($repostInFeed);
        $this->assertNotNull($repostInFeed['reposted_post']);
        $this->assertEquals('Meu post original no feed', $repostInFeed['reposted_post']['content']);
        $this->assertEquals($author->username, $repostInFeed['reposted_post']['user']['username']);
    }

    public function test_user_can_pin_and_unpin_their_own_post(): void
    {
        $user = $this->createApprovedUser();

        $post = Post::create([
            'user_id' => $user->id,
            'content' => 'Post super importante que quero fixar',
            'category' => 'feed',
        ]);

        // Pin
        $res = $this->actingAs($user)->postJson("/api/posts/{$post->id}/pin");
        $res->assertStatus(200)
            ->assertJson([
                'pinned' => true,
                'pinned_post_id' => $post->id,
            ]);

        $this->assertEquals($post->id, $user->fresh()->pinned_post_id);

        // Check in /api/auth/me
        $me = $this->actingAs($user)->getJson('/api/auth/me');
        $me->assertStatus(200);
        $this->assertEquals($post->id, $me->json('data.pinned_post_id') ?? $me->json('pinned_post_id'));
        $this->assertNotNull($me->json('data.pinned_post') ?? $me->json('pinned_post'));
        $this->assertEquals('Post super importante que quero fixar', ($me->json('data.pinned_post') ?? $me->json('pinned_post'))['content']);

        // Check in /users/{username}
        $profile = $this->actingAs($user)->getJson("/api/users/{$user->username}");
        $profile->assertStatus(200);
        $this->assertEquals($post->id, $profile->json('data.pinned_post_id') ?? $profile->json('pinned_post_id'));

        // Toggle unpin
        $unpinRes = $this->actingAs($user)->postJson("/api/posts/{$post->id}/pin");
        $unpinRes->assertStatus(200)
            ->assertJson([
                'pinned' => false,
                'pinned_post_id' => null,
            ]);

        $this->assertNull($user->fresh()->pinned_post_id);
    }

    public function test_user_cannot_pin_another_users_post(): void
    {
        $user1 = $this->createApprovedUser();
        $user2 = $this->createApprovedUser();

        $postOfUser1 = Post::create([
            'user_id' => $user1->id,
            'content' => 'Post do User 1',
            'category' => 'feed',
        ]);

        $res = $this->actingAs($user2)->postJson("/api/posts/{$postOfUser1->id}/pin");
        $res->assertStatus(403);

        $this->assertNull($user2->fresh()->pinned_post_id);
    }

    public function test_deleting_pinned_post_sets_pinned_post_id_to_null(): void
    {
        $user = $this->createApprovedUser();

        $post = Post::create([
            'user_id' => $user->id,
            'content' => 'Post que vai ser apagado',
            'category' => 'feed',
        ]);

        $user->update(['pinned_post_id' => $post->id]);
        $this->assertEquals($post->id, $user->fresh()->pinned_post_id);

        // Delete post
        $delRes = $this->actingAs($user)->deleteJson("/api/posts/{$post->id}");
        $delRes->assertStatus(200);

        $this->assertNull($user->fresh()->pinned_post_id);
    }
}
