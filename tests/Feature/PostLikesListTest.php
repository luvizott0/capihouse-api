<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostLikesListTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ], $attributes));
    }

    public function test_can_fetch_likers_of_a_post()
    {
        $author = $this->createApprovedUser(['name' => 'Autor']);
        $liker1 = $this->createApprovedUser(['name' => 'Liker Um']);
        $liker2 = $this->createApprovedUser(['name' => 'Liker Dois']);

        $post = Post::create([
            'user_id' => $author->id,
            'content' => 'Post de teste com curtidas',
        ]);

        PostLike::create(['post_id' => $post->id, 'user_id' => $liker1->id]);
        PostLike::create(['post_id' => $post->id, 'user_id' => $liker2->id]);

        $response = $this->actingAs($author)->getJson("/api/posts/{$post->id}/likes");

        $response->assertStatus(200);
        $likerIds = collect($response->json())->pluck('id')->toArray();

        $this->assertCount(2, $likerIds);
        $this->assertContains($liker1->id, $likerIds);
        $this->assertContains($liker2->id, $likerIds);
    }

    public function test_empty_likes_returns_empty_array()
    {
        $author = $this->createApprovedUser(['name' => 'Autor']);
        $post = Post::create([
            'user_id' => $author->id,
            'content' => 'Post sem curtidas',
        ]);

        $response = $this->actingAs($author)->getJson("/api/posts/{$post->id}/likes");
        $response->assertStatus(200);
        $this->assertCount(0, $response->json());
    }
}
