<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Events\CommentCreated;
use App\Events\CommentDeleted;
use App\Events\CommentUpdated;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PostCommentTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ], $attributes));
    }

    public function test_user_can_create_comment()
    {
        Event::fake([CommentCreated::class]);

        $author = $this->createApprovedUser(['name' => 'Author']);
        $commenter = $this->createApprovedUser(['name' => 'Commenter']);

        $post = Post::create([
            'user_id' => $author->id,
            'content' => 'Post de teste',
        ]);

        $res = $this->actingAs($commenter)->postJson("/api/posts/{$post->id}/comments", [
            'content' => 'Comentário muito bom!',
        ]);

        $res->assertStatus(201);
        $res->assertJsonFragment([
            'content' => 'Comentário muito bom!',
            'user_id' => $commenter->id,
        ]);

        $this->assertDatabaseHas('post_comments', [
            'post_id' => $post->id,
            'user_id' => $commenter->id,
            'content' => 'Comentário muito bom!',
        ]);

        $this->assertEquals(1, $post->fresh()->comments_count);
        Event::assertDispatched(CommentCreated::class);
    }

    public function test_author_can_update_own_comment()
    {
        Event::fake([CommentUpdated::class]);

        $user = $this->createApprovedUser();
        $post = Post::create([
            'user_id' => $user->id,
            'content' => 'Post de teste',
        ]);

        $comment = PostComment::create([
            'post_id' => $post->id,
            'user_id' => $user->id,
            'content' => 'Texto original',
        ]);

        $res = $this->actingAs($user)->putJson("/api/comments/{$comment->id}", [
            'content' => 'Texto editado',
        ]);

        $res->assertStatus(200);
        $res->assertJsonFragment([
            'id' => $comment->id,
            'content' => 'Texto editado',
        ]);

        $this->assertDatabaseHas('post_comments', [
            'id' => $comment->id,
            'content' => 'Texto editado',
        ]);

        Event::assertDispatched(CommentUpdated::class);
    }

    public function test_user_cannot_update_another_users_comment()
    {
        $commenter = $this->createApprovedUser();
        $otherUser = $this->createApprovedUser();

        $post = Post::create([
            'user_id' => $commenter->id,
            'content' => 'Post',
        ]);

        $comment = PostComment::create([
            'post_id' => $post->id,
            'user_id' => $commenter->id,
            'content' => 'Texto original',
        ]);

        $res = $this->actingAs($otherUser)->putJson("/api/comments/{$comment->id}", [
            'content' => 'Tentativa de alteração indevida',
        ]);

        $res->assertStatus(403);

        $this->assertDatabaseHas('post_comments', [
            'id' => $comment->id,
            'content' => 'Texto original',
        ]);
    }

    public function test_author_can_delete_own_comment()
    {
        Event::fake([CommentDeleted::class]);

        $user = $this->createApprovedUser();
        $post = Post::create([
            'user_id' => $user->id,
            'content' => 'Post',
            'comments_count' => 1,
        ]);

        $comment = PostComment::create([
            'post_id' => $post->id,
            'user_id' => $user->id,
            'content' => 'Texto a deletar',
        ]);

        $res = $this->actingAs($user)->deleteJson("/api/comments/{$comment->id}");

        $res->assertStatus(200);
        $this->assertSoftDeleted('post_comments', [
            'id' => $comment->id,
        ]);

        $this->assertEquals(0, $post->fresh()->comments_count);
        Event::assertDispatched(CommentDeleted::class);
    }

    public function test_post_owner_can_delete_comment_on_their_post()
    {
        Event::fake([CommentDeleted::class]);

        $postAuthor = $this->createApprovedUser(['name' => 'Post Owner']);
        $commenter = $this->createApprovedUser(['name' => 'Commenter']);

        $post = Post::create([
            'user_id' => $postAuthor->id,
            'content' => 'Post do Dono',
            'comments_count' => 1,
        ]);

        $comment = PostComment::create([
            'post_id' => $post->id,
            'user_id' => $commenter->id,
            'content' => 'Comentário inconveniente',
        ]);

        $res = $this->actingAs($postAuthor)->deleteJson("/api/comments/{$comment->id}");

        $res->assertStatus(200);
        $this->assertSoftDeleted('post_comments', [
            'id' => $comment->id,
        ]);

        $this->assertEquals(0, $post->fresh()->comments_count);
        Event::assertDispatched(CommentDeleted::class);
    }

    public function test_admin_can_delete_any_comment()
    {
        Event::fake([CommentDeleted::class]);

        $admin = $this->createApprovedUser(['role' => UserRoles::Admin]);
        $author = $this->createApprovedUser();
        $commenter = $this->createApprovedUser();

        $post = Post::create([
            'user_id' => $author->id,
            'content' => 'Post',
            'comments_count' => 1,
        ]);

        $comment = PostComment::create([
            'post_id' => $post->id,
            'user_id' => $commenter->id,
            'content' => 'Comentário a moderar',
        ]);

        $res = $this->actingAs($admin)->deleteJson("/api/comments/{$comment->id}");

        $res->assertStatus(200);
        $this->assertSoftDeleted('post_comments', [
            'id' => $comment->id,
        ]);

        Event::assertDispatched(CommentDeleted::class);
    }

    public function test_unauthorized_user_cannot_delete_another_users_comment()
    {
        $author = $this->createApprovedUser();
        $commenter = $this->createApprovedUser();
        $randomUser = $this->createApprovedUser();

        $post = Post::create([
            'user_id' => $author->id,
            'content' => 'Post',
            'comments_count' => 1,
        ]);

        $comment = PostComment::create([
            'post_id' => $post->id,
            'user_id' => $commenter->id,
            'content' => 'Comentário alheio',
        ]);

        $res = $this->actingAs($randomUser)->deleteJson("/api/comments/{$comment->id}");

        $res->assertStatus(403);
        $this->assertDatabaseHas('post_comments', [
            'id' => $comment->id,
        ]);
    }
}
