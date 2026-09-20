<?php

namespace App\Http\Controllers\Api;

use App\Events\PollVoted;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PollController extends Controller
{
    public function vote(Request $request, Post $post)
    {
        $userId = auth()->id();
        $isAdmin = auth()->user()->isAdmin();

        // Check group authorization
        if ($post->group_id) {
            $isMember = auth()->user()->acceptedGroups()->where('groups.id', $post->group_id)->exists();
            if (! $isMember && ! $isAdmin) {
                return response()->json(['message' => 'Você não tem permissão para interagir com este post.'], 403);
            }
        }

        // Check event authorization
        if ($post->event_id) {
            $event = $post->event;
            $isInvitedOrOwner = $event && ($event->user_id === $userId || $event->guests()->where('users.id', $userId)->exists());
            if (! $isInvitedOrOwner && ! $isAdmin) {
                return response()->json(['message' => 'Você não tem permissão para interagir com este post.'], 403);
            }
        }

        $poll = $post->poll;
        if (! $poll) {
            return response()->json(['message' => 'Votação não encontrada nesta publicação.'], 404);
        }

        $request->validate([
            'option_id' => 'required|integer',
        ], [
            'option_id.required' => 'Selecione uma opção para votar.',
        ]);

        $optionId = (int) $request->input('option_id');
        $selectedOption = PollOption::where('poll_id', $poll->id)->where('id', $optionId)->first();

        if (! $selectedOption) {
            return response()->json(['message' => 'Opção inválida para esta votação.'], 422);
        }

        DB::transaction(function () use ($poll, $optionId, $userId) {
            $existingVote = PollVote::where('poll_id', $poll->id)->where('user_id', $userId)->first();

            if ($existingVote) {
                if ($existingVote->poll_option_id !== $optionId) {
                    // Decrement previous option
                    PollOption::where('id', $existingVote->poll_option_id)
                        ->where('votes_count', '>', 0)
                        ->decrement('votes_count');

                    // Increment new option
                    PollOption::where('id', $optionId)->increment('votes_count');

                    // Update vote
                    $existingVote->update(['poll_option_id' => $optionId]);
                }
            } else {
                // Create vote
                PollVote::create([
                    'poll_id' => $poll->id,
                    'poll_option_id' => $optionId,
                    'user_id' => $userId,
                ]);

                // Increment option
                PollOption::where('id', $optionId)->increment('votes_count');
            }
        });

        // Reload poll and options
        $poll->load('options');
        $totalVotes = (int) $poll->options->sum('votes_count');

        // Broadcast event safely
        try {
            $optionsBroadcast = $poll->options->map(fn ($o) => [
                'id' => $o->id,
                'votes_count' => $o->votes_count,
            ])->values()->all();

            broadcast(new PollVoted(
                postId: $post->id,
                pollId: $poll->id,
                totalVotes: $totalVotes,
                options: $optionsBroadcast,
                groupId: $post->group_id,
                eventId: $post->event_id
            ));
        } catch (\Throwable $e) {
            report($e);
        }

        // Format poll response for current user (they just voted, so they see all numbers)
        $formattedOptions = $poll->options->map(function ($opt) use ($totalVotes) {
            $votes = (int) $opt->votes_count;
            $percentage = $totalVotes > 0 ? round(($votes / $totalVotes) * 100, 1) : 0;

            return [
                'id' => $opt->id,
                'poll_id' => $opt->poll_id,
                'text' => $opt->text,
                'order' => $opt->order,
                'votes_count' => $votes,
                'percentage' => $percentage,
            ];
        });

        return response()->json([
            'id' => $poll->id,
            'post_id' => $poll->post_id,
            'question' => $poll->question,
            'has_voted' => true,
            'user_voted_option_id' => $optionId,
            'total_votes' => $totalVotes,
            'options' => $formattedOptions,
        ]);
    }
}
