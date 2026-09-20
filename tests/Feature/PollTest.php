<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\AppNotification;
use App\Models\PollVote;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PollTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(): User
    {
        return User::factory()->create([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ]);
    }

    public function test_can_create_post_with_poll()
    {
        $user = $this->createApprovedUser();

        $res = $this->actingAs($user)->postJson('/api/posts', [
            'content' => 'Post com enquete',
            'poll' => [
                'question' => 'Qual o melhor dia?',
                'options' => ['Sexta', 'Sábado', 'Domingo'],
            ],
        ]);

        $res->assertStatus(201);
        $res->assertJsonPath('poll.question', 'Qual o melhor dia?');
        $res->assertJsonPath('poll.has_voted', false);
        $res->assertJsonPath('poll.can_see_results', true);
        $res->assertJsonPath('poll.user_voted_option_id', null);
        $res->assertJsonPath('poll.total_votes', 0);
        $this->assertCount(3, $res->json('poll.options'));
        $this->assertEquals(0, $res->json('poll.options.0.votes_count'));
        $this->assertEquals(0, $res->json('poll.options.0.percentage'));

        $this->assertDatabaseHas('polls', [
            'question' => 'Qual o melhor dia?',
        ]);
        $this->assertDatabaseHas('poll_options', [
            'text' => 'Sexta',
        ]);
    }

    public function test_can_create_post_with_only_poll_without_text()
    {
        $user = $this->createApprovedUser();

        $res = $this->actingAs($user)->postJson('/api/posts', [
            'poll' => [
                'question' => 'Apenas enquete',
                'options' => ['Opção A', 'Opção B'],
            ],
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('polls', [
            'question' => 'Apenas enquete',
        ]);
    }

    public function test_poll_validation_min_and_max_options()
    {
        $user = $this->createApprovedUser();

        // Less than 2 options
        $res = $this->actingAs($user)->postJson('/api/posts', [
            'poll' => [
                'question' => 'Incompleta',
                'options' => ['Opção única'],
            ],
        ]);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['poll.options']);

        // More than 5 options
        $res = $this->actingAs($user)->postJson('/api/posts', [
            'poll' => [
                'question' => 'Muitas opções',
                'options' => ['1', '2', '3', '4', '5', '6'],
            ],
        ]);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['poll.options']);
    }

    public function test_user_cannot_see_votes_count_until_voted()
    {
        $author = $this->createApprovedUser();
        $viewer = $this->createApprovedUser();

        $res = $this->actingAs($author)->postJson('/api/posts', [
            'content' => 'Votação secreta',
            'poll' => [
                'question' => 'Qual você prefere?',
                'options' => ['A', 'B'],
            ],
        ]);
        $postId = $res->json('id');
        $optionAId = $res->json('poll.options.0.id');

        // Author votes
        $this->actingAs($author)->postJson("/api/posts/{$postId}/poll/vote", [
            'option_id' => $optionAId,
        ])->assertStatus(200);

        // Viewer checks the feed / post
        $viewRes = $this->actingAs($viewer)->getJson("/api/posts/{$postId}");
        $viewRes->assertStatus(200);
        $viewRes->assertJsonPath('poll.has_voted', false);
        $viewRes->assertJsonPath('poll.total_votes', null);
        $this->assertNull($viewRes->json('poll.options.0.votes_count'));
        $this->assertNull($viewRes->json('poll.options.0.percentage'));
    }

    public function test_voting_and_changing_vote()
    {
        $author = $this->createApprovedUser();
        $voter = $this->createApprovedUser();

        $res = $this->actingAs($author)->postJson('/api/posts', [
            'content' => 'Enquete de teste',
            'poll' => [
                'question' => 'Qual pizza?',
                'options' => ['Calabresa', 'Marguerita'],
            ],
        ]);
        $postId = $res->json('id');
        $pollId = $res->json('poll.id');
        $calabresaId = $res->json('poll.options.0.id');
        $margueritaId = $res->json('poll.options.1.id');

        // Voter votes for Calabresa
        $voteRes = $this->actingAs($voter)->postJson("/api/posts/{$postId}/poll/vote", [
            'option_id' => $calabresaId,
        ]);

        $voteRes->assertStatus(200);
        $voteRes->assertJsonPath('has_voted', true);
        $voteRes->assertJsonPath('user_voted_option_id', $calabresaId);
        $voteRes->assertJsonPath('total_votes', 1);
        $voteRes->assertJsonPath('options.0.votes_count', 1);
        $voteRes->assertJsonPath('options.0.percentage', 100);
        $voteRes->assertJsonPath('options.1.votes_count', 0);
        $voteRes->assertJsonPath('options.1.percentage', 0);

        $this->assertEquals(1, PollVote::where('poll_id', $pollId)->where('user_id', $voter->id)->count());

        // Voter changes vote to Marguerita
        $changeRes = $this->actingAs($voter)->postJson("/api/posts/{$postId}/poll/vote", [
            'option_id' => $margueritaId,
        ]);

        $changeRes->assertStatus(200);
        $changeRes->assertJsonPath('has_voted', true);
        $changeRes->assertJsonPath('user_voted_option_id', $margueritaId);
        $changeRes->assertJsonPath('total_votes', 1);
        $changeRes->assertJsonPath('options.0.votes_count', 0);
        $changeRes->assertJsonPath('options.0.percentage', 0);
        $changeRes->assertJsonPath('options.1.votes_count', 1);
        $changeRes->assertJsonPath('options.1.percentage', 100);

        // Ensure still only 1 vote record exists
        $this->assertEquals(1, PollVote::where('poll_id', $pollId)->where('user_id', $voter->id)->count());
        $this->assertEquals($margueritaId, PollVote::where('poll_id', $pollId)->where('user_id', $voter->id)->first()->poll_option_id);
    }

    public function test_cannot_vote_with_invalid_option()
    {
        $author = $this->createApprovedUser();
        $voter = $this->createApprovedUser();

        $res = $this->actingAs($author)->postJson('/api/posts', [
            'poll' => [
                'options' => ['A', 'B'],
            ],
        ]);
        $postId = $res->json('id');

        $this->actingAs($voter)->postJson("/api/posts/{$postId}/poll/vote", [
            'option_id' => 999999,
        ])->assertStatus(422);
    }

    public function test_deleting_post_cascades_poll_and_votes()
    {
        $author = $this->createApprovedUser();

        $res = $this->actingAs($author)->postJson('/api/posts', [
            'poll' => [
                'options' => ['A', 'B'],
            ],
        ]);
        $postId = $res->json('id');
        $pollId = $res->json('poll.id');

        $this->actingAs($author)->deleteJson("/api/posts/{$postId}")->assertStatus(200);

        $this->assertDatabaseMissing('posts', ['id' => $postId]);
        $this->assertDatabaseMissing('polls', ['id' => $pollId]);
        $this->assertDatabaseMissing('poll_options', ['poll_id' => $pollId]);
    }

    public function test_author_is_notified_only_on_first_vote_from_other_user()
    {
        $author = $this->createApprovedUser();
        $voter = $this->createApprovedUser();

        $res = $this->actingAs($author)->postJson('/api/posts', [
            'poll' => [
                'options' => ['Opção 1', 'Opção 2'],
            ],
        ]);
        $postId = $res->json('id');
        $opt1Id = $res->json('poll.options.0.id');
        $opt2Id = $res->json('poll.options.1.id');

        // 1. Voter votes on option 1 -> author should receive notification
        $this->actingAs($voter)->postJson("/api/posts/{$postId}/poll/vote", [
            'option_id' => $opt1Id,
        ])->assertStatus(200);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $author->id,
            'type' => 'poll_vote',
        ]);
        $this->assertEquals(1, AppNotification::where('user_id', $author->id)->where('type', 'poll_vote')->count());

        // 2. Voter changes vote to option 2 -> NO new notification should be sent
        $this->actingAs($voter)->postJson("/api/posts/{$postId}/poll/vote", [
            'option_id' => $opt2Id,
        ])->assertStatus(200);

        $this->assertEquals(1, AppNotification::where('user_id', $author->id)->where('type', 'poll_vote')->count());

        // 3. Author votes on own poll -> NO notification should be created
        $this->actingAs($author)->postJson("/api/posts/{$postId}/poll/vote", [
            'option_id' => $opt1Id,
        ])->assertStatus(200);

        $this->assertEquals(1, AppNotification::where('user_id', $author->id)->where('type', 'poll_vote')->count());
    }

    public function test_author_sees_results_even_without_voting()
    {
        $author = $this->createApprovedUser();
        $viewer = $this->createApprovedUser();

        $res = $this->actingAs($author)->postJson('/api/posts', [
            'poll' => [
                'question' => 'Enquete do autor',
                'options' => ['Opção 1', 'Opção 2'],
            ],
        ]);
        $postId = $res->json('id');
        $opt1Id = $res->json('poll.options.0.id');

        // Viewer votes
        $this->actingAs($viewer)->postJson("/api/posts/{$postId}/poll/vote", [
            'option_id' => $opt1Id,
        ])->assertStatus(200);

        // Viewer checks post -> cannot see results yet
        $viewerCheck = $this->actingAs($this->createApprovedUser())->getJson("/api/posts/{$postId}");
        $viewerCheck->assertJsonPath('poll.can_see_results', false);
        $this->assertNull($viewerCheck->json('poll.options.0.votes_count'));

        // Author fetches the post WITHOUT having voted
        $authorView = $this->actingAs($author)->getJson("/api/posts/{$postId}");
        $authorView->assertStatus(200);
        $authorView->assertJsonPath('poll.has_voted', false);
        $authorView->assertJsonPath('poll.can_see_results', true);
        $authorView->assertJsonPath('poll.total_votes', 1);
        $this->assertEquals(1, $authorView->json('poll.options.0.votes_count'));
        $this->assertEquals(100, $authorView->json('poll.options.0.percentage'));
    }
}
