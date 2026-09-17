<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Events\CommentCreated;
use App\Events\CommentDeleted;
use App\Events\CommentLiked;
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

    public function test_user_can_reply_to_comment()
    {
        Event::fake([CommentCreated::class]);

        $postAuthor = $this->createApprovedUser(['name' => 'Post Author']);
        $parentAuthor = $this->createApprovedUser(['name' => 'Parent Author']);
        $replier = $this->createApprovedUser(['name' => 'Replier']);

        $post = Post::create([
            'user_id' => $postAuthor->id,
            'content' => 'Publicação com comentário',
        ]);

        $parentComment = PostComment::create([
            'post_id' => $post->id,
            'user_id' => $parentAuthor->id,
            'content' => 'Comentário original para responder',
        ]);

        $res = $this->actingAs($replier)->postJson("/api/posts/{$post->id}/comments", [
            'content' => 'Esta é uma resposta!',
            'parent_id' => $parentComment->id,
        ]);

        $res->assertStatus(201);
        $res->assertJsonFragment([
            'content' => 'Esta é uma resposta!',
            'parent_id' => $parentComment->id,
        ]);
        $res->assertJsonPath('parent.user.name', 'Parent Author');

        $this->assertDatabaseHas('post_comments', [
            'post_id' => $post->id,
            'parent_id' => $parentComment->id,
            'user_id' => $replier->id,
            'content' => 'Esta é uma resposta!',
        ]);

        // Check comment_reply notification was created for parentAuthor
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $parentAuthor->id,
            'type' => 'comment_reply',
        ]);

        // Check post_comment notification was created for postAuthor
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $postAuthor->id,
            'type' => 'post_comment',
        ]);
    }

    public function test_cannot_reply_with_parent_id_from_different_post()
    {
        $user = $this->createApprovedUser();
        $post1 = Post::create(['user_id' => $user->id, 'content' => 'Post 1']);
        $post2 = Post::create(['user_id' => $user->id, 'content' => 'Post 2']);

        $commentPost1 = PostComment::create([
            'post_id' => $post1->id,
            'user_id' => $user->id,
            'content' => 'Comentário no post 1',
        ]);

        $res = $this->actingAs($user)->postJson("/api/posts/{$post2->id}/comments", [
            'content' => 'Tentativa cruzada',
            'parent_id' => $commentPost1->id,
        ]);

        $res->assertStatus(422);
    }

    public function test_user_can_like_and_unlike_comment()
    {
        Event::fake([CommentLiked::class]);

        $commentAuthor = $this->createApprovedUser(['name' => 'Comment Author']);
        $liker = $this->createApprovedUser(['name' => 'Liker User']);

        $post = Post::create([
            'user_id' => $commentAuthor->id,
            'content' => 'Post de teste',
        ]);

        $comment = PostComment::create([
            'post_id' => $post->id,
            'user_id' => $commentAuthor->id,
            'content' => 'Comentário a ser curtido',
        ]);

        // 1. Like
        $res = $this->actingAs($liker)->postJson("/api/comments/{$comment->id}/like");
        $res->assertStatus(200);
        $res->assertJson([
            'comment_id' => $comment->id,
            'is_liked' => true,
            'likes_count' => 1,
        ]);

        $this->assertEquals(1, $comment->fresh()->likes_count);
        $this->assertDatabaseHas('post_comment_likes', [
            'comment_id' => $comment->id,
            'user_id' => $liker->id,
        ]);

        // Notification for comment author
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $commentAuthor->id,
            'type' => 'comment_like',
        ]);

        Event::assertDispatched(CommentLiked::class);

        // 2. Unlike
        $resUnlike = $this->actingAs($liker)->postJson("/api/comments/{$comment->id}/like");
        $resUnlike->assertStatus(200);
        $resUnlike->assertJson([
            'comment_id' => $comment->id,
            'is_liked' => false,
            'likes_count' => 0,
        ]);

        $this->assertEquals(0, $comment->fresh()->likes_count);
        $this->assertDatabaseMissing('post_comment_likes', [
            'comment_id' => $comment->id,
            'user_id' => $liker->id,
        ]);
    }

    public function test_post_show_returns_comment_is_liked_and_parent()
    {
        $user = $this->createApprovedUser();
        $post = Post::create(['user_id' => $user->id, 'content' => 'Post']);

        $parent = PostComment::create([
            'post_id' => $post->id,
            'user_id' => $user->id,
            'content' => 'Comentário pai',
        ]);

        $child = PostComment::create([
            'post_id' => $post->id,
            'parent_id' => $parent->id,
            'user_id' => $user->id,
            'content' => 'Comentário filho',
        ]);

        // Like child
        $this->actingAs($user)->postJson("/api/comments/{$child->id}/like");

        $res = $this->actingAs($user)->getJson("/api/posts/{$post->id}");
        $res->assertStatus(200);

        $comments = $res->json('comments');
        $this->assertCount(2, $comments);

        $childInRes = collect($comments)->firstWhere('id', $child->id);
        $this->assertTrue($childInRes['is_liked']);
        $this->assertEquals(1, $childInRes['likes_count']);
        $this->assertEquals($parent->id, $childInRes['parent_id']);
        $this->assertEquals('Comentário pai', $childInRes['parent']['content']);

        $parentInRes = collect($comments)->firstWhere('id', $parent->id);
        $this->assertFalse($parentInRes['is_liked']);
        $this->assertEquals(0, $parentInRes['likes_count']);
    }
}
