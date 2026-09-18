<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostFeelingTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(): User
    {
        return User::factory()->create([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ]);
    }

    public function test_post_allows_feeling_name_up_to_15_characters()
    {
        $user = $this->createApprovedUser();
        $feelingName = '123456789012345';

        $res = $this->actingAs($user)->postJson('/api/posts', [
            'content' => 'Test post with 15 char feeling',
            'feeling_name' => $feelingName,
            'feeling_emoji' => '🚀',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('feelings', [
            'name' => $feelingName,
            'emoji' => '🚀',
        ]);
    }

    public function test_post_rejects_feeling_name_over_15_characters()
    {
        $user = $this->createApprovedUser();
        $feelingName = '1234567890123456';

        $res = $this->actingAs($user)->postJson('/api/posts', [
            'content' => 'Test post with 16 char feeling',
            'feeling_name' => $feelingName,
            'feeling_emoji' => '🚀',
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['feeling_name']);
    }

    public function test_post_update_allows_feeling_name_up_to_15_characters()
    {
        $user = $this->createApprovedUser();
        $post = Post::create([
            'user_id' => $user->id,
            'content' => 'Initial content',
        ]);

        $feelingName = 'SuperEmpolgado!';

        $res = $this->actingAs($user)->putJson('/api/posts/'.$post->id, [
            'content' => 'Updated content',
            'feeling_name' => $feelingName,
            'feeling_emoji' => '🎉',
        ]);

        $res->assertStatus(200);
        $this->assertDatabaseHas('feelings', [
            'post_id' => $post->id,
            'name' => $feelingName,
            'emoji' => '🎉',
        ]);
    }
}
