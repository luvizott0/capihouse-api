<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\Group;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ], $attributes));
    }

    public function test_user_can_create_group_with_name_up_to_25_chars_and_color_and_members()
    {
        $creator = $this->createApprovedUser();
        $friend1 = $this->createApprovedUser();
        $friend2 = $this->createApprovedUser();

        // Name exceeding 25 chars fails
        $failResponse = $this->actingAs($creator)->postJson('/api/groups', [
            'name' => 'Nome Muito Longo Que Ultrapassa Vinte E Cinco Caracteres',
            'color' => '#10b981',
        ]);
        $failResponse->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // Valid creation with color and members
        $response = $this->actingAs($creator)->postJson('/api/groups', [
            'name' => 'Amigos Próximos',
            'color' => '#10b981',
            'members' => [$friend1->id, $friend2->id],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Amigos Próximos')
            ->assertJsonPath('color', '#10b981')
            ->assertJsonCount(2, 'members');

        $groupId = $response->json('id');

        $this->assertDatabaseHas('groups', [
            'id' => $groupId,
            'name' => 'Amigos Próximos',
            'color' => '#10b981',
            'creator_id' => $creator->id,
        ]);

        // Members directly synced without notifications
        $this->assertDatabaseHas('group_users', [
            'group_id' => $groupId,
            'user_id' => $friend1->id,
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('group_users', [
            'group_id' => $groupId,
            'user_id' => $friend2->id,
            'status' => 'accepted',
        ]);

        $this->assertDatabaseMissing('app_notifications', [
            'type' => 'group_invite',
        ]);
    }

    public function test_groups_are_private_and_only_visible_to_creator()
    {
        $creator = $this->createApprovedUser();
        $member = $this->createApprovedUser();
        $otherUser = $this->createApprovedUser();

        $group = Group::create([
            'name' => 'Filtro Secreto',
            'color' => '#ef4444',
            'creator_id' => $creator->id,
        ]);
        $group->members()->attach($member->id, ['role' => 'member', 'status' => 'accepted']);

        // Creator lists groups -> sees their group
        $creatorRes = $this->actingAs($creator)->getJson('/api/groups');
        $creatorRes->assertStatus(200)
            ->assertJsonPath('data.0.id', $group->id)
            ->assertJsonPath('data.0.name', 'Filtro Secreto');

        // Member lists groups -> does NOT see the group (groups are only for creator)
        $memberRes = $this->actingAs($member)->getJson('/api/groups');
        $memberRes->assertStatus(200)
            ->assertJsonCount(0, 'data');

        // Other user lists groups -> does NOT see the group
        $otherRes = $this->actingAs($otherUser)->getJson('/api/groups');
        $otherRes->assertStatus(200)
            ->assertJsonCount(0, 'data');

        // Member cannot access group directly
        $this->actingAs($member)->getJson("/api/groups/{$group->id}")
            ->assertStatus(403);
    }

    public function test_user_can_update_and_delete_their_own_group()
    {
        $creator = $this->createApprovedUser();
        $user1 = $this->createApprovedUser();
        $user2 = $this->createApprovedUser();
        $otherUser = $this->createApprovedUser();

        $group = Group::create([
            'name' => 'Grupo Inicial',
            'color' => '#a66130',
            'creator_id' => $creator->id,
        ]);
        $group->members()->attach($user1->id, ['role' => 'member', 'status' => 'accepted']);

        // Other user cannot update
        $this->actingAs($otherUser)->putJson("/api/groups/{$group->id}", [
            'name' => 'Tentativa Hacker',
        ])->assertStatus(403);

        // Creator updates name, color and member list
        $updateRes = $this->actingAs($creator)->putJson("/api/groups/{$group->id}", [
            'name' => 'Grupo Atualizado',
            'color' => '#3b82f6',
            'members' => [$user2->id],
        ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('name', 'Grupo Atualizado')
            ->assertJsonPath('color', '#3b82f6')
            ->assertJsonCount(1, 'members')
            ->assertJsonPath('members.0.id', $user2->id);

        $this->assertDatabaseHas('groups', [
            'id' => $group->id,
            'name' => 'Grupo Atualizado',
            'color' => '#3b82f6',
        ]);

        // User1 is no longer a member, User2 is member
        $this->assertDatabaseMissing('group_users', [
            'group_id' => $group->id,
            'user_id' => $user1->id,
        ]);
        $this->assertDatabaseHas('group_users', [
            'group_id' => $group->id,
            'user_id' => $user2->id,
        ]);

        // Other user cannot delete
        $this->actingAs($otherUser)->deleteJson("/api/groups/{$group->id}")
            ->assertStatus(403);

        // Creator deletes group
        $deleteRes = $this->actingAs($creator)->deleteJson("/api/groups/{$group->id}");
        $deleteRes->assertStatus(200);

        $this->assertDatabaseMissing('groups', ['id' => $group->id]);
        $this->assertDatabaseMissing('group_users', ['group_id' => $group->id]);
    }

    public function test_post_targeted_to_group_is_only_visible_to_creator_and_group_members()
    {
        $creator = $this->createApprovedUser();
        $member = $this->createApprovedUser();
        $outsider = $this->createApprovedUser();

        $group = Group::create([
            'name' => 'Devs Secretos',
            'color' => '#8b5cf6',
            'creator_id' => $creator->id,
        ]);
        $group->members()->attach($member->id, ['role' => 'member', 'status' => 'accepted']);

        // Outsider cannot post to this group
        $outsiderPostRes = $this->actingAs($outsider)->postJson('/api/posts', [
            'content' => 'Post não autorizado',
            'group_id' => $group->id,
        ]);
        $outsiderPostRes->assertStatus(403);

        // Creator publishes post directed to their group
        $postRes = $this->actingAs($creator)->postJson('/api/posts', [
            'content' => 'Novidade para os Devs!',
            'group_id' => $group->id,
        ]);
        $postRes->assertStatus(201);
        $postId = $postRes->json('id');

        // Creator views feed -> sees post WITH group badge
        $creatorFeed = $this->actingAs($creator)->getJson('/api/posts');
        $creatorFeed->assertStatus(200)
            ->assertJsonPath('data.0.id', $postId)
            ->assertJsonPath('data.0.group.name', 'Devs Secretos');

        // Member views feed -> sees post WITHOUT group badge (private to author)
        $memberFeed = $this->actingAs($member)->getJson('/api/posts');
        $memberFeed->assertStatus(200)
            ->assertJsonPath('data.0.id', $postId)
            ->assertJsonPath('data.0.group', null);

        // Outsider views feed -> DOES NOT see post
        $outsiderFeed = $this->actingAs($outsider)->getJson('/api/posts');
        $outsiderFeed->assertStatus(200)
            ->assertJsonCount(0, 'data');

        // Member can view individual post
        $memberPostShow = $this->actingAs($member)->getJson("/api/posts/{$postId}");
        $memberPostShow->assertStatus(200)
            ->assertJsonPath('group', null);

        // Outsider cannot view individual post
        $outsiderPostShow = $this->actingAs($outsider)->getJson("/api/posts/{$postId}");
        $outsiderPostShow->assertStatus(403);
    }
}
