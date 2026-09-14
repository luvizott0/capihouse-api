<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\AppNotification;
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

    public function test_user_can_create_group_and_invite_others()
    {
        $creator = $this->createApprovedUser();
        $friend = $this->createApprovedUser();

        $response = $this->actingAs($creator)->postJson('/api/groups', [
            'name' => 'Capivaras Unidas',
            'description' => 'Grupo para reunir amigos',
            'invites' => [$friend->id],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Capivaras Unidas');

        $this->assertDatabaseHas('groups', [
            'name' => 'Capivaras Unidas',
            'creator_id' => $creator->id,
        ]);

        // Creator is owner and accepted
        $this->assertDatabaseHas('group_users', [
            'user_id' => $creator->id,
            'role' => 'owner',
            'status' => 'accepted',
        ]);

        // Friend has pending invite
        $this->assertDatabaseHas('group_users', [
            'user_id' => $friend->id,
            'role' => 'member',
            'status' => 'pending',
        ]);

        // Notification created for friend
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $friend->id,
            'type' => 'group_invite',
        ]);
    }

    public function test_user_can_accept_and_decline_group_invite()
    {
        $creator = $this->createApprovedUser();
        $user1 = $this->createApprovedUser();
        $user2 = $this->createApprovedUser();

        $group = Group::create([
            'name' => 'Grupo Teste',
            'creator_id' => $creator->id,
        ]);
        $group->members()->attach($creator->id, ['role' => 'owner', 'status' => 'accepted']);
        $group->members()->attach($user1->id, ['role' => 'member', 'status' => 'pending']);
        $group->members()->attach($user2->id, ['role' => 'member', 'status' => 'pending']);

        AppNotification::create([
            'user_id' => $user1->id,
            'type' => 'group_invite',
            'title' => 'Convite',
            'data' => ['group_id' => $group->id],
        ]);

        // User1 accepts
        $acceptRes = $this->actingAs($user1)->postJson("/api/groups/{$group->id}/accept-invite");
        $acceptRes->assertStatus(200);

        $this->assertDatabaseHas('group_users', [
            'group_id' => $group->id,
            'user_id' => $user1->id,
            'status' => 'accepted',
        ]);

        $this->assertNotNull(AppNotification::where('user_id', $user1->id)->first()->read_at);

        // User2 declines
        $declineRes = $this->actingAs($user2)->postJson("/api/groups/{$group->id}/decline-invite");
        $declineRes->assertStatus(200);

        $this->assertDatabaseMissing('group_users', [
            'group_id' => $group->id,
            'user_id' => $user2->id,
        ]);
    }

    public function test_user_can_leave_group()
    {
        $creator = $this->createApprovedUser();
        $member = $this->createApprovedUser();

        $group = Group::create([
            'name' => 'Grupo Saída',
            'creator_id' => $creator->id,
        ]);
        $group->members()->attach($creator->id, ['role' => 'owner', 'status' => 'accepted']);
        $group->members()->attach($member->id, ['role' => 'member', 'status' => 'accepted']);

        $response = $this->actingAs($member)->postJson("/api/groups/{$group->id}/leave");
        $response->assertStatus(200);

        $this->assertDatabaseMissing('group_users', [
            'group_id' => $group->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_group_chat_messaging_and_authorization()
    {
        $creator = $this->createApprovedUser();
        $outsider = $this->createApprovedUser();

        $group = Group::create([
            'name' => 'Chat Grupo',
            'creator_id' => $creator->id,
        ]);
        $group->members()->attach($creator->id, ['role' => 'owner', 'status' => 'accepted']);

        // Creator can send message
        $sendRes = $this->actingAs($creator)->postJson("/api/groups/{$group->id}/messages", [
            'content' => 'Olá capivaras!',
        ]);
        $sendRes->assertStatus(201)
            ->assertJsonPath('content', 'Olá capivaras!');

        // Creator can read messages
        $getRes = $this->actingAs($creator)->getJson("/api/groups/{$group->id}/messages");
        $getRes->assertStatus(200)
            ->assertJsonCount(1);

        // Outsider cannot send or read messages
        $outsiderSend = $this->actingAs($outsider)->postJson("/api/groups/{$group->id}/messages", [
            'content' => 'Tentativa não autorizada',
        ]);
        $outsiderSend->assertStatus(403);

        $outsiderGet = $this->actingAs($outsider)->getJson("/api/groups/{$group->id}/messages");
        $outsiderGet->assertStatus(403);
    }

    public function test_group_post_privacy_and_historical_access()
    {
        $creator = $this->createApprovedUser();
        $member = $this->createApprovedUser();
        $lateJoiner = $this->createApprovedUser();
        $outsider = $this->createApprovedUser();

        $group = Group::create([
            'name' => 'Clube Secreto',
            'creator_id' => $creator->id,
        ]);
        $group->members()->attach($creator->id, ['role' => 'owner', 'status' => 'accepted']);
        $group->members()->attach($member->id, ['role' => 'member', 'status' => 'accepted']);

        // Creator publishes post in group
        $postRes = $this->actingAs($creator)->postJson('/api/posts', [
            'content' => 'Post secreto do clube',
            'group_id' => $group->id,
        ]);
        $postRes->assertStatus(201);
        $postId = $postRes->json('id');

        // Member can see the post in general feed and group filter
        $memberFeed = $this->actingAs($member)->getJson('/api/posts');
        $memberFeed->assertStatus(200)
            ->assertJsonFragment(['id' => $postId]);

        $memberGroupFeed = $this->actingAs($member)->getJson("/api/posts?group_id={$group->id}");
        $memberGroupFeed->assertStatus(200)
            ->assertJsonFragment(['id' => $postId]);

        // Outsider cannot see the post in general feed
        $outsiderFeed = $this->actingAs($outsider)->getJson('/api/posts');
        $outsiderFeed->assertStatus(200)
            ->assertJsonMissing(['id' => $postId]);

        // Outsider is blocked when trying to query the group feed directly
        $outsiderGroupFeed = $this->actingAs($outsider)->getJson("/api/posts?group_id={$group->id}");
        $outsiderGroupFeed->assertStatus(403);

        // Outsider cannot post in group
        $outsiderPost = $this->actingAs($outsider)->postJson('/api/posts', [
            'content' => 'Tentativa de post em grupo alheio',
            'group_id' => $group->id,
        ]);
        $outsiderPost->assertStatus(403);

        // Now LateJoiner joins the group AFTER the post was created
        $group->members()->attach($lateJoiner->id, ['role' => 'member', 'status' => 'accepted']);

        // LateJoiner can see past posts of that group!
        $lateJoinerFeed = $this->actingAs($lateJoiner)->getJson("/api/posts?group_id={$group->id}");
        $lateJoinerFeed->assertStatus(200)
            ->assertJsonFragment(['id' => $postId]);
    }

    public function test_groups_are_invite_only_and_not_discoverable_by_outsiders()
    {
        $creator = $this->createApprovedUser();
        $invitedUser = $this->createApprovedUser();
        $outsider = $this->createApprovedUser();

        $group = Group::create([
            'name' => 'Grupo Exclusivo',
            'creator_id' => $creator->id,
        ]);
        $group->members()->attach($creator->id, ['role' => 'owner', 'status' => 'accepted']);
        $group->members()->attach($invitedUser->id, ['role' => 'member', 'status' => 'pending']);

        // Creator sees the group
        $creatorList = $this->actingAs($creator)->getJson('/api/groups');
        $creatorList->assertStatus(200)
            ->assertJsonFragment(['name' => 'Grupo Exclusivo']);

        // Outsider does NOT see the group in index
        $outsiderList = $this->actingAs($outsider)->getJson('/api/groups');
        $outsiderList->assertStatus(200)
            ->assertJsonMissing(['name' => 'Grupo Exclusivo']);

        // Outsider cannot access show
        $outsiderShow = $this->actingAs($outsider)->getJson("/api/groups/{$group->id}");
        $outsiderShow->assertStatus(403);

        // Invited user can access show (to see invite details)
        $invitedShow = $this->actingAs($invitedUser)->getJson("/api/groups/{$group->id}");
        $invitedShow->assertStatus(200);
    }
}
