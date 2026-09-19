<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Jobs\GenerateUserBirthdayPostJob;
use App\Models\BirthdayPost;
use App\Models\Post;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BirthdayPostTest extends TestCase
{
    use RefreshDatabase;

    protected User $rogeria;
    protected User $bento;
    protected User $maria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rogeria = User::create([
            'name' => 'Capivara Rogéria',
            'username' => 'capivara.rogeria',
            'email' => 'capivara@rogeria.com',
            'password' => bcrypt('password'),
            'role' => UserRoles::Admin,
            'status' => UserStatuses::APPROVED,
        ]);

        $this->bento = User::create([
            'name' => 'Bento Silva',
            'username' => 'bento',
            'email' => 'bento@capihouse.com',
            'password' => bcrypt('password'),
            'birth' => '1998-09-19',
            'role' => UserRoles::User,
            'status' => UserStatuses::APPROVED,
        ]);

        $this->maria = User::create([
            'name' => 'Maria Souza',
            'username' => 'maria',
            'email' => 'maria@capihouse.com',
            'password' => bcrypt('password'),
            'birth' => '2000-01-15',
            'role' => UserRoles::User,
            'status' => UserStatuses::APPROVED,
        ]);
    }

    public function test_job_generates_birthday_post_with_mentions_and_age(): void
    {
        $job = new GenerateUserBirthdayPostJob($this->bento, 2026);
        $job->handle();

        // 1. Post criado pela Capivara Rogéria
        $post = Post::where('user_id', $this->rogeria->id)->latest()->first();
        $this->assertNotNull($post);
        $this->assertStringContainsString('@bento', $post->content);
        $this->assertStringContainsString('28 anos', $post->content);
        $this->assertStringContainsString('#AniversarioCapiHouse', $post->content);

        // 2. Sentimento comemorativo
        $this->assertNotNull($post->feeling);
        $this->assertEquals('Celebrando', $post->feeling->name);
        $this->assertEquals('🎂', $post->feeling->emoji);

        // 3. Menção vinculada (faz aparecer no perfil do Bento)
        $this->assertTrue($post->mentions->contains($this->bento->id));

        // 4. Registro na tabela birthday_posts
        $this->assertDatabaseHas('birthday_posts', [
            'user_id' => $this->bento->id,
            'post_id' => $post->id,
            'year' => 2026,
            'age' => 28,
        ]);
    }

    public function test_job_notifies_other_users_with_link_to_post(): void
    {
        $job = new GenerateUserBirthdayPostJob($this->bento, 2026);
        $job->handle();

        $post = Post::where('user_id', $this->rogeria->id)->latest()->first();

        // Maria deve receber notificação sobre o aniversário do Bento
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->maria->id,
            'type' => 'birthday_post',
            'title' => '🎂 Hoje é aniversário de Bento Silva!',
        ]);

        $mariaNotification = $this->maria->appNotifications()->where('type', 'birthday_post')->first();
        $this->assertNotNull($mariaNotification);
        $this->assertEquals($post->id, $mariaNotification->data['post_id'] ?? null);
        $this->assertStringContainsString('@bento', $mariaNotification->content);
    }

    public function test_job_is_idempotent_and_does_not_duplicate_posts(): void
    {
        $job = new GenerateUserBirthdayPostJob($this->bento, 2026);
        $job->handle();

        $firstPostCount = Post::where('user_id', $this->rogeria->id)->count();
        $firstRecordCount = BirthdayPost::count();

        // Executar novamente no mesmo ano
        $job->handle();

        $this->assertEquals($firstPostCount, Post::where('user_id', $this->rogeria->id)->count());
        $this->assertEquals($firstRecordCount, BirthdayPost::count());
    }

    public function test_command_filters_birthdays_and_dispatches_job(): void
    {
        Queue::fake();

        // Bento faz aniversário em 09-19. Maria faz em 01-15.
        // Executar comando para a data de hoje (19/09)
        $this->artisan('capihouse:generate-birthday-posts', [
            '--date' => '2026-09-19',
        ])
            ->expectsOutputToContain('Bento Silva')
            ->assertSuccessful();

        Queue::assertPushed(GenerateUserBirthdayPostJob::class, function ($job) {
            return $job->user->id === $this->bento->id && $job->year === 2026;
        });

        Queue::assertNotPushed(GenerateUserBirthdayPostJob::class, function ($job) {
            return $job->user->id === $this->maria->id;
        });
    }

    public function test_command_handles_leap_year_february_29(): void
    {
        Queue::fake();

        // Usuário nascido em 29 de fevereiro
        $leapUser = User::create([
            'name' => 'Bissexto Silva',
            'username' => 'bissexto',
            'email' => 'bissexto@capihouse.com',
            'password' => bcrypt('password'),
            'birth' => '2000-02-29',
            'role' => UserRoles::User,
            'status' => UserStatuses::APPROVED,
        ]);

        // 2025 não é bissexto -> deve comemorar em 28/02
        $this->artisan('capihouse:generate-birthday-posts', [
            '--date' => '2025-02-28',
        ])
            ->assertSuccessful();

        Queue::assertPushed(GenerateUserBirthdayPostJob::class, function ($job) use ($leapUser) {
            return $job->user->id === $leapUser->id && $job->year === 2025;
        });
    }
}
