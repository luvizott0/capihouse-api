<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ], $attributes));
    }

    public function test_user_can_list_notifications_and_read_them()
    {
        $user = $this->createApprovedUser();

        $n1 = AppNotification::create([
            'user_id' => $user->id,
            'type' => 'system',
            'title' => 'Bem-vindo ao CapiHouse',
            'content' => 'Explore o app',
        ]);

        $n2 = AppNotification::create([
            'user_id' => $user->id,
            'type' => 'system',
            'title' => 'Segunda notificação',
            'content' => 'Teste',
        ]);

        // Check unread count
        $countRes = $this->actingAs($user)->getJson('/api/notifications/unread-count');
        $countRes->assertStatus(200)
            ->assertJson(['unread_count' => 2]);

        // List notifications
        $listRes = $this->actingAs($user)->getJson('/api/notifications');
        $listRes->assertStatus(200)
            ->assertJsonCount(2, 'data');

        // Mark single as read
        $readRes = $this->actingAs($user)->patchJson("/api/notifications/{$n1->id}/read");
        $readRes->assertStatus(200);

        $this->assertNotNull($n1->fresh()->read_at);
        $this->assertNull($n2->fresh()->read_at);

        // Check count is now 1
        $countRes2 = $this->actingAs($user)->getJson('/api/notifications/unread-count');
        $countRes2->assertJson(['unread_count' => 1]);

        // Mark all as read
        $readAllRes = $this->actingAs($user)->postJson('/api/notifications/read-all');
        $readAllRes->assertStatus(200);

        $this->assertNotNull($n2->fresh()->read_at);

        // Check count is 0
        $countRes3 = $this->actingAs($user)->getJson('/api/notifications/unread-count');
        $countRes3->assertJson(['unread_count' => 0]);
    }

    public function test_user_can_filter_notifications_by_category_and_get_counts()
    {
        $user = $this->createApprovedUser();

        // Create notifications across different categories
        AppNotification::create([
            'user_id' => $user->id,
            'type' => 'post_like',
            'title' => 'Nova curtida',
            'content' => 'Alguém curtiu sua publicação',
        ]);

        AppNotification::create([
            'user_id' => $user->id,
            'type' => 'comment_like',
            'title' => 'Curtida no comentário',
            'content' => 'Alguém curtiu seu comentário',
        ]);

        AppNotification::create([
            'user_id' => $user->id,
            'type' => 'post_comment',
            'title' => 'Novo comentário',
            'content' => 'Comentaram no seu post',
        ]);

        AppNotification::create([
            'user_id' => $user->id,
            'type' => 'post_mention',
            'title' => 'Menção em post',
            'content' => 'Você foi mencionado',
        ]);

        AppNotification::create([
            'user_id' => $user->id,
            'type' => 'group_invite',
            'title' => 'Convite de grupo',
            'content' => 'Você foi convidado para o grupo',
            'data' => ['group_id' => 99],
        ]);

        AppNotification::create([
            'user_id' => $user->id,
            'type' => 'poll_vote',
            'title' => 'Novo voto na enquete',
            'content' => 'Alguém votou na sua enquete',
        ]);

        // Check category counts
        $countsRes = $this->actingAs($user)->getJson('/api/notifications/category-counts');
        $countsRes->assertStatus(200)
            ->assertJson([
                'all' => 6,
                'unread' => 6,
                'likes' => 2,
                'comments' => 1,
                'mentions' => 1,
                'groups' => 1,
                'events' => 0,
                'polls' => 1,
            ]);

        // Filter by unread
        $unreadRes = $this->actingAs($user)->getJson('/api/notifications?category=unread');
        $unreadRes->assertStatus(200)
            ->assertJsonCount(6, 'data');

        // Mark one as read and verify unread count decreases
        $firstNotif = AppNotification::where('user_id', $user->id)->first();
        $firstNotif->update(['read_at' => now()]);

        $countsResAfterRead = $this->actingAs($user)->getJson('/api/notifications/category-counts');
        $countsResAfterRead->assertJson(['all' => 6, 'unread' => 5]);

        $unreadResAfterRead = $this->actingAs($user)->getJson('/api/notifications?category=unread');
        $unreadResAfterRead->assertJsonCount(5, 'data');

        // Filter by likes
        $likesRes = $this->actingAs($user)->getJson('/api/notifications?category=likes');
        $likesRes->assertStatus(200)
            ->assertJsonCount(2, 'data');

        // Filter by comments
        $commentsRes = $this->actingAs($user)->getJson('/api/notifications?category=comments');
        $commentsRes->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Filter by mentions
        $mentionsRes = $this->actingAs($user)->getJson('/api/notifications?category=mentions');
        $mentionsRes->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Filter by groups
        $groupsRes = $this->actingAs($user)->getJson('/api/notifications?category=groups');
        $groupsRes->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Filter by events (empty)
        $eventsRes = $this->actingAs($user)->getJson('/api/notifications?category=events');
        $eventsRes->assertStatus(200)
            ->assertJsonCount(0, 'data');

        // Filter by polls
        $pollsRes = $this->actingAs($user)->getJson('/api/notifications?category=polls');
        $pollsRes->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Filter by all
        $allRes = $this->actingAs($user)->getJson('/api/notifications?category=all');
        $allRes->assertStatus(200)
            ->assertJsonCount(6, 'data');
    }
}
