<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Events\PostCreated;
use App\Models\AppNotification;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use App\Notifications\GenericWebPushNotification;
use App\Services\LetterboxdSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EntertainmentRealtimeAndNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ], $attributes));
    }

    public function test_liking_an_entertainment_post_notifies_owner_in_app_and_via_push()
    {
        Notification::fake();

        $author = $this->createApprovedUser(['name' => 'Movie Buff']);
        $liker = $this->createApprovedUser(['name' => 'Cinema Fan']);

        $post = Post::create([
            'user_id' => $author->id,
            'category' => 'entertainment',
            'entertainment_type' => 'movie',
            'content' => 'Review excelente de Duna!',
            'metadata' => [
                'film_title' => 'Duna: Parte 2',
                'rating' => 5,
            ],
        ]);

        $res = $this->actingAs($liker)->postJson("/api/posts/{$post->id}/like");
        $res->assertStatus(200);

        // Verifica notificação no banco (in-app)
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $author->id,
            'type' => 'post_like',
            'title' => 'Nova curtida',
        ]);

        $notif = AppNotification::where('user_id', $author->id)->first();
        $this->assertEquals($post->id, $notif->data['post_id']);
        $this->assertEquals($liker->id, $notif->data['liker_id']);

        // Verifica push notification web
        Notification::assertSentTo(
            $author,
            GenericWebPushNotification::class,
            function ($notification) use ($post) {
                return $notification->title === 'Nova curtida'
                    && str_contains($notification->url, "/posts/{$post->id}");
            }
        );
    }

    public function test_commenting_on_an_entertainment_post_notifies_owner_in_app_and_via_push()
    {
        Notification::fake();

        $author = $this->createApprovedUser(['name' => 'Movie Critic']);
        $commenter = $this->createApprovedUser(['name' => 'Film Lover']);

        $post = Post::create([
            'user_id' => $author->id,
            'category' => 'entertainment',
            'entertainment_type' => 'movie',
            'content' => 'Review de Interestelar',
            'metadata' => [
                'film_title' => 'Interstellar',
                'rating' => 4.5,
            ],
        ]);

        $res = $this->actingAs($commenter)->postJson("/api/posts/{$post->id}/comments", [
            'content' => 'Concordo totalmente com a sua nota!',
        ]);
        $res->assertStatus(201);

        // Verifica in-app notification
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $author->id,
            'type' => 'post_comment',
            'title' => 'Novo comentário',
        ]);

        // Verifica push notification web
        Notification::assertSentTo(
            $author,
            GenericWebPushNotification::class,
            function ($notification) use ($post) {
                return $notification->title === 'Novo comentário'
                    && str_contains($notification->url, "/posts/{$post->id}");
            }
        );
    }

    public function test_liking_comment_on_entertainment_post_notifies_comment_author()
    {
        Notification::fake();

        $postAuthor = $this->createApprovedUser();
        $commentAuthor = $this->createApprovedUser(['name' => 'Commenter']);
        $liker = $this->createApprovedUser(['name' => 'Liker']);

        $post = Post::create([
            'user_id' => $postAuthor->id,
            'category' => 'entertainment',
            'entertainment_type' => 'movie',
            'content' => 'Filme legal',
        ]);

        $comment = PostComment::create([
            'post_id' => $post->id,
            'user_id' => $commentAuthor->id,
            'content' => 'Comentário top',
        ]);

        $res = $this->actingAs($liker)->postJson("/api/comments/{$comment->id}/like");
        $res->assertStatus(200);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $commentAuthor->id,
            'type' => 'comment_like',
            'title' => 'Nova curtida no comentário',
        ]);

        Notification::assertSentTo(
            $commentAuthor,
            GenericWebPushNotification::class,
            function ($notification) use ($post) {
                return $notification->title === 'Nova curtida no comentário'
                    && str_contains($notification->url, "/posts/{$post->id}");
            }
        );
    }

    public function test_letterboxd_sync_broadcasts_post_created_event()
    {
        Event::fake([PostCreated::class]);

        $user = $this->createApprovedUser([
            'letterboxd_username' => 'testuser',
        ]);

        $sampleRss = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:letterboxd="https://letterboxd.com/">
  <channel>
    <title>Letterboxd - testuser</title>
    <item>
      <title>Oppenheimer, 2023 - ★★★★★</title>
      <link>https://letterboxd.com/testuser/film/oppenheimer/</link>
      <guid isPermaLink="false">letterboxd-watch-123456</guid>
      <pubDate>Mon, 20 Sep 2026 12:00:00 +0000</pubDate>
      <letterboxd:watchedDate>2026-09-20</letterboxd:watchedDate>
      <letterboxd:rewatch>No</letterboxd:rewatch>
      <letterboxd:filmTitle>Oppenheimer</letterboxd:filmTitle>
      <letterboxd:filmYear>2023</letterboxd:filmYear>
      <letterboxd:memberRating>5.0</letterboxd:memberRating>
      <description><![CDATA[<p><img src="https://example.com/poster.jpg"/></p><p>Filme incrível.</p>]]></description>
    </item>
  </channel>
</rss>
XML;

        Http::fake([
            'https://letterboxd.com/testuser/rss/' => Http::response($sampleRss, 200),
        ]);

        $syncService = new LetterboxdSyncService;
        $count = $syncService->syncUser($user);

        $this->assertEquals(1, $count);

        Event::assertDispatched(PostCreated::class, function ($event) {
            return $event->post->category === 'entertainment'
                && $event->post->metadata['film_title'] === 'Oppenheimer';
        });
    }
}
