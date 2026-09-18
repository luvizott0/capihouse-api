<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\AppNotification;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ], $attributes));
    }

    public function test_liking_another_users_post_creates_notification()
    {
        $author = $this->createApprovedUser(['name' => 'Author']);
        $liker = $this->createApprovedUser(['name' => 'Liker']);

        $post = Post::create([
            'user_id' => $author->id,
            'content' => 'Test Post Content',
        ]);

        $res = $this->actingAs($liker)->postJson("/api/posts/{$post->id}/like");
        $res->assertStatus(200);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $author->id,
            'type' => 'post_like',
            'title' => 'Nova curtida',
        ]);

        $notif = AppNotification::where('user_id', $author->id)->first();
        $this->assertEquals($post->id, $notif->data['post_id']);
        $this->assertEquals($liker->id, $notif->data['liker_id']);
    }

    public function test_liking_own_post_does_not_create_notification()
    {
        $user = $this->createApprovedUser(['name' => 'Self User']);

        $post = Post::create([
            'user_id' => $user->id,
            'content' => 'My own post',
        ]);

        $res = $this->actingAs($user)->postJson("/api/posts/{$post->id}/like");
        $res->assertStatus(200);

        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $user->id,
            'type' => 'post_like',
        ]);
    }

    public function test_unliking_does_not_create_another_notification()
    {
        $author = $this->createApprovedUser();
        $liker = $this->createApprovedUser();

        $post = Post::create(['user_id' => $author->id]);

        // Like
        $this->actingAs($liker)->postJson("/api/posts/{$post->id}/like");
        $this->assertEquals(1, AppNotification::where('user_id', $author->id)->count());

        // Unlike
        $this->actingAs($liker)->postJson("/api/posts/{$post->id}/like");
        $this->assertEquals(1, AppNotification::where('user_id', $author->id)->count());
    }

    public function test_commenting_on_another_users_post_creates_notification()
    {
        $author = $this->createApprovedUser(['name' => 'Post Author']);
        $commenter = $this->createApprovedUser(['name' => 'Commenter']);

        $post = Post::create([
            'user_id' => $author->id,
            'content' => 'Original Post',
        ]);

        $res = $this->actingAs($commenter)->postJson("/api/posts/{$post->id}/comments", [
            'content' => 'Que post legal!',
        ]);
        $res->assertStatus(201);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $author->id,
            'type' => 'post_comment',
            'title' => 'Novo comentário',
        ]);

        $notif = AppNotification::where('user_id', $author->id)->where('type', 'post_comment')->first();
        $this->assertStringContainsString('Commenter comentou', $notif->content);
        $this->assertEquals($post->id, $notif->data['post_id']);
        $this->assertEquals($commenter->id, $notif->data['commenter_id']);
    }

    public function test_commenting_on_own_post_does_not_create_notification()
    {
        $user = $this->createApprovedUser(['name' => 'Author']);

        $post = Post::create([
            'user_id' => $user->id,
            'content' => 'My Post',
        ]);

        $res = $this->actingAs($user)->postJson("/api/posts/{$post->id}/comments", [
            'content' => 'Meu próprio comentário',
        ]);
        $res->assertStatus(201);

        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $user->id,
            'type' => 'post_comment',
        ]);
    }

    public function test_user_resource_formats_birth_as_ymd()
    {
        $user = $this->createApprovedUser();
        $user->birth = '1995-05-20';
        $user->save();

        $res = $this->actingAs($user)->getJson('/api/profile');
        $res->assertStatus(200);
        $this->assertEquals('1995-05-20', $res->json('data.birth') ?? $res->json('birth'));
    }

    public function test_can_get_single_post()
    {
        $author = $this->createApprovedUser(['name' => 'Author']);
        $viewer = $this->createApprovedUser(['name' => 'Viewer']);

        $post = Post::create([
            'user_id' => $author->id,
            'content' => 'Single Post Content',
        ]);

        $res = $this->actingAs($viewer)->getJson("/api/posts/{$post->id}");
        $res->assertStatus(200);
        $this->assertEquals($post->id, $res->json('id'));
        $this->assertEquals('Single Post Content', $res->json('content'));
        $this->assertFalse($res->json('is_liked'));
    }
}
