<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Jobs\GenerateUserMonthlyRecapJob;
use App\Models\Post;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonthlyRecapTest extends TestCase
{
    use RefreshDatabase;

    protected User $rogeria;

    protected User $user;

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

        $this->user = User::create([
            'name' => 'Bento Silva',
            'username' => 'bento',
            'email' => 'bento@capihouse.com',
            'password' => bcrypt('password'),
            'role' => UserRoles::User,
            'status' => UserStatuses::APPROVED,
        ]);
    }

    public function test_job_generates_monthly_recap_post_with_ranking_and_mentions(): void
    {
        // Criar posts no mês 2026-09 com diferentes sentimentos
        $date = Carbon::parse('2026-09-15 12:00:00');

        // 3 posts com 😊
        for ($i = 0; $i < 3; $i++) {
            $post = new Post([
                'user_id' => $this->user->id,
                'content' => "Post feliz $i",
            ]);
            $post->created_at = $date;
            $post->save();
            $post->feeling()->create(['name' => 'Feliz', 'emoji' => '😊']);
        }

        // 2 posts com 🚀
        for ($i = 0; $i < 2; $i++) {
            $post = new Post([
                'user_id' => $this->user->id,
                'content' => "Post animado $i",
            ]);
            $post->created_at = $date;
            $post->save();
            $post->feeling()->create(['name' => 'Animado', 'emoji' => '🚀']);
        }

        // 1 post com 🔥
        $post = new Post([
            'user_id' => $this->user->id,
            'content' => 'Post fogo',
        ]);
        $post->created_at = $date;
        $post->save();
        $post->feeling()->create(['name' => 'Fogo', 'emoji' => '🔥']);

        // Post de outro mês (outubro - não deve entrar na conta de setembro)
        $octPost = new Post([
            'user_id' => $this->user->id,
            'content' => 'Post de outubro',
        ]);
        $octPost->created_at = Carbon::parse('2026-10-02 10:00:00');
        $octPost->save();
        $octPost->feeling()->create(['name' => 'Outro', 'emoji' => '🎉']);

        // Executar o Job diretamente
        $job = new GenerateUserMonthlyRecapJob($this->user, '2026-09');
        $job->handle();

        // Validar que o post foi criado pela Capivara Rogéria
        $recapPost = Post::where('user_id', $this->rogeria->id)->latest()->first();
        $this->assertNotNull($recapPost);
        $this->assertStringContainsString('@bento', $recapPost->content);
        $this->assertStringContainsString('Setembro', $recapPost->content);
        $this->assertStringContainsString('#RecapRogeria', $recapPost->content);

        // Validar pódio com nomes de sentimentos
        $this->assertStringContainsString('🥇 😊 Feliz — 3x', $recapPost->content);
        $this->assertStringContainsString('🥈 🚀 Animado — 2x', $recapPost->content);
        $this->assertStringContainsString('🥉 🔥 Fogo — 1x', $recapPost->content);

        // Validar tabela monthly_recaps
        $this->assertDatabaseHas('monthly_recaps', [
            'user_id' => $this->user->id,
            'year_month' => '2026-09',
            'post_id' => $recapPost->id,
            'total_feelings' => 6,
        ]);

        // Validar que a notificação de menção foi gerada para o usuário
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->user->id,
            'type' => 'post_mention',
        ]);
    }

    public function test_job_skips_post_creation_if_user_has_no_feelings_in_month(): void
    {
        // Post sem sentimento
        Post::create([
            'user_id' => $this->user->id,
            'content' => 'Post comum sem sentimento',
            'created_at' => Carbon::parse('2026-09-10 12:00:00'),
            'updated_at' => Carbon::parse('2026-09-10 12:00:00'),
        ]);

        $job = new GenerateUserMonthlyRecapJob($this->user, '2026-09');
        $job->handle();

        // Nenhum post deve ser criado pela Rogéria
        $this->assertEquals(0, Post::where('user_id', $this->rogeria->id)->count());

        // Registro de recap com zero sentimentos deve existir para evitar reprocessamento
        $this->assertDatabaseHas('monthly_recaps', [
            'user_id' => $this->user->id,
            'year_month' => '2026-09',
            'post_id' => null,
            'total_feelings' => 0,
        ]);
    }

    public function test_job_is_idempotent_and_does_not_duplicate_posts(): void
    {
        $post = Post::create([
            'user_id' => $this->user->id,
            'content' => 'Post feliz',
            'created_at' => Carbon::parse('2026-09-10 12:00:00'),
            'updated_at' => Carbon::parse('2026-09-10 12:00:00'),
        ]);
        $post->feeling()->create(['name' => 'Feliz', 'emoji' => '😊']);

        // Rodar 1ª vez
        (new GenerateUserMonthlyRecapJob($this->user, '2026-09'))->handle();
        $this->assertEquals(1, Post::where('user_id', $this->rogeria->id)->count());

        // Rodar 2ª vez (sem force)
        (new GenerateUserMonthlyRecapJob($this->user, '2026-09'))->handle();
        $this->assertEquals(1, Post::where('user_id', $this->rogeria->id)->count());
    }

    public function test_artisan_command_dispatches_jobs_for_approved_users(): void
    {
        Queue::fake();

        // Criar outro usuário aprovado
        $otherUser = User::create([
            'name' => 'Pipoca',
            'username' => 'pipoca',
            'email' => 'pipoca@capihouse.com',
            'password' => bcrypt('password'),
            'role' => UserRoles::User,
            'status' => UserStatuses::APPROVED,
        ]);

        // Criar usuário pendente (não deve receber)
        User::create([
            'name' => 'Pendente',
            'username' => 'pendente',
            'email' => 'pendente@capihouse.com',
            'password' => bcrypt('password'),
            'role' => UserRoles::User,
            'status' => UserStatuses::PENDING,
        ]);

        $this->artisan('capihouse:generate-monthly-recap', ['--month' => '2026-09'])
            ->assertSuccessful();

        Queue::assertPushed(GenerateUserMonthlyRecapJob::class, function ($job) {
            return $job->user->id === $this->user->id && $job->yearMonth === '2026-09';
        });

        Queue::assertPushed(GenerateUserMonthlyRecapJob::class, function ($job) use ($otherUser) {
            return $job->user->id === $otherUser->id;
        });

        // Não deve despachar para a própria Rogéria
        Queue::assertNotPushed(GenerateUserMonthlyRecapJob::class, function ($job) {
            return $job->user->username === 'capivara.rogeria';
        });
    }
}
