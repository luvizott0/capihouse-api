<?php

namespace App\Services;

use App\Enums\UserStatuses;
use App\Events\NotificationSent;
use App\Models\AppNotification;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Support\Collection;

class MentionService
{
    /**
     * Check if text contains the global mention @todos.
     */
    public static function containsEveryoneMention(?string $content): bool
    {
        if (empty($content)) {
            return false;
        }

        return (bool) preg_match('/(?:^|\s)@todos\b/i', $content);
    }

    /**
     * Extract unique usernames from given text.
     * Matches patterns like @username, @user-name, @user_name.
     */
    public static function extractUsernames(?string $content): array
    {
        if (empty($content)) {
            return [];
        }

        preg_match_all('/@([a-zA-Z0-9_-]+)/', $content, $matches);

        if (empty($matches[1])) {
            return [];
        }

        $usernames = array_values(array_unique(array_filter($matches[1])));

        // Filter out global mention reserved keywords like 'todos'
        return array_values(array_filter($usernames, fn ($u) => strtolower($u) !== 'todos'));
    }

    /**
     * Find approved users mentioned in content.
     */
    public static function getMentionedUsers(?string $content, ?int $excludeUserId = null): Collection
    {
        $usernames = self::extractUsernames($content);

        if (empty($usernames)) {
            return collect();
        }

        $query = User::whereIn('username', $usernames)
            ->where('status', UserStatuses::APPROVED);

        if ($excludeUserId) {
            $query->where('id', '!=', $excludeUserId);
        }

        return $query->get();
    }

    /**
     * Synchronize post mentions and notify new mentions.
     */
    public static function syncPostMentions(Post $post, User $author, ?string $oldContent = null): void
    {
        $previousIds = $post->mentions()->pluck('users.id')->toArray();
        $mentionedUsers = self::getMentionedUsers($post->content, $author->id);
        $newIds = $mentionedUsers->pluck('id')->toArray();

        $post->mentions()->sync($newIds);

        $addedUsers = $mentionedUsers->whereNotIn('id', $previousIds);

        $snippet = $post->content ? mb_strimwidth($post->content, 0, 80, '...') : 'uma publicação';

        foreach ($addedUsers as $user) {
            NotificationDispatcherService::send(
                recipient: $user->id,
                type: 'post_mention',
                title: 'Você foi marcado(a)',
                content: "{$author->name} marcou você em uma publicação: \"{$snippet}\"",
                data: [
                    'post_id' => $post->id,
                    'author_id' => $author->id,
                    'author_name' => $author->name,
                    'author_username' => $author->username,
                    'author_avatar' => $author->avatar_url,
                ],
                url: '/posts/'.$post->id
            );
        }

        // Global mention: @todos
        $hasEveryone = self::containsEveryoneMention($post->content);
        $hadEveryone = $oldContent !== null ? self::containsEveryoneMention($oldContent) : false;
        $isNewEveryone = $hasEveryone && ($post->wasRecentlyCreated || ! $hadEveryone);

        if ($isNewEveryone) {
            $alreadyNotifiedIds = $addedUsers->pluck('id')->push($author->id)->unique()->toArray();

            $allUsers = User::where('status', UserStatuses::APPROVED)
                ->whereNotIn('id', $alreadyNotifiedIds)
                ->get();

            foreach ($allUsers as $user) {
                NotificationDispatcherService::send(
                    recipient: $user->id,
                    type: 'post_mention',
                    title: '📢 Menção para todos',
                    content: "{$author->name} mencionou todos (@todos) em uma publicação: \"{$snippet}\"",
                    data: [
                        'post_id' => $post->id,
                        'author_id' => $author->id,
                        'author_name' => $author->name,
                        'author_username' => $author->username,
                        'author_avatar' => $author->avatar_url,
                        'is_all' => true,
                    ],
                    url: '/posts/'.$post->id
                );
            }
        }
    }

    /**
     * Synchronize comment mentions and notify new mentions.
     * Returns list of mentioned user IDs so caller knows if post author was mentioned.
     */
    public static function syncCommentMentions(PostComment $comment, User $commenter, Post $post, ?string $oldContent = null): array
    {
        $previousIds = $comment->mentions()->pluck('users.id')->toArray();
        $mentionedUsers = self::getMentionedUsers($comment->content, $commenter->id);
        $newIds = $mentionedUsers->pluck('id')->toArray();

        $comment->mentions()->sync($newIds);

        $addedUsers = $mentionedUsers->whereNotIn('id', $previousIds);
        $snippet = mb_strimwidth($comment->content, 0, 80, '...');

        foreach ($addedUsers as $user) {
            NotificationDispatcherService::send(
                recipient: $user->id,
                type: 'comment_mention',
                title: 'Você foi marcado(a)',
                content: "{$commenter->name} marcou você em um comentário: \"{$snippet}\"",
                data: [
                    'post_id' => $post->id,
                    'comment_id' => $comment->id,
                    'commenter_id' => $commenter->id,
                    'commenter_name' => $commenter->name,
                    'commenter_username' => $commenter->username,
                    'commenter_avatar' => $commenter->avatar_url,
                ],
                url: '/posts/'.$post->id
            );
        }

        // Global mention: @todos in comment
        $hasEveryone = self::containsEveryoneMention($comment->content);
        $hadEveryone = $oldContent !== null ? self::containsEveryoneMention($oldContent) : false;
        $isNewEveryone = $hasEveryone && ($comment->wasRecentlyCreated || ! $hadEveryone);

        if ($isNewEveryone) {
            $alreadyNotifiedIds = $addedUsers->pluck('id')->push($commenter->id)->unique()->toArray();

            $allUsers = User::where('status', UserStatuses::APPROVED)
                ->whereNotIn('id', $alreadyNotifiedIds)
                ->get();

            foreach ($allUsers as $user) {
                NotificationDispatcherService::send(
                    recipient: $user->id,
                    type: 'comment_mention',
                    title: '📢 Menção para todos',
                    content: "{$commenter->name} mencionou todos (@todos) em um comentário: \"{$snippet}\"",
                    data: [
                        'post_id' => $post->id,
                        'comment_id' => $comment->id,
                        'commenter_id' => $commenter->id,
                        'commenter_name' => $commenter->name,
                        'commenter_username' => $commenter->username,
                        'commenter_avatar' => $commenter->avatar_url,
                        'is_all' => true,
                    ],
                    url: '/posts/'.$post->id
                );
            }
        }

        return $newIds;
    }

    protected static function broadcastNotification(int $userId, AppNotification $notification): void
    {
        $unreadCount = AppNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();

        try {
            broadcast(new NotificationSent($notification, $unreadCount));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
