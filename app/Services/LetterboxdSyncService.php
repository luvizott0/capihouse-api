<?php

namespace App\Services;

use App\Events\PostCreated;
use App\Models\Post;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LetterboxdSyncService
{
    /**
     * Sincroniza as avaliações/atividades do Letterboxd para um usuário.
     * Retorna a quantidade de novos posts de entretenimento criados.
     */
    public function syncUser(User $user): int
    {
        $username = trim(ltrim((string) $user->letterboxd_username, '@'));
        if (empty($username)) {
            return 0;
        }

        $rssUrl = "https://letterboxd.com/{$username}/rss/";

        try {
            $response = Http::withUserAgent('CapiHouse/1.0 (+https://capihouse.app)')
                ->timeout(15)
                ->get($rssUrl);

            if ($response->status() === 404) {
                Log::warning("Perfil do Letterboxd não encontrado para usuário #{$user->id}: {$username}");
                throw new \Exception("Perfil '{$username}' não foi encontrado no Letterboxd. Verifique o nome de usuário digitado.");
            }

            if (! $response->successful()) {
                Log::warning("Erro ao buscar RSS do Letterboxd para {$username}: HTTP {$response->status()}");

                return 0;
            }

            $xml = @simplexml_load_string($response->body());
            if (! $xml || ! isset($xml->channel->item)) {
                return 0;
            }

            $newItemsCount = 0;

            foreach ($xml->channel->item as $item) {
                // Tenta namespace padrão ou fallback
                $nsLetterboxd = $item->children('https://letterboxd.com');
                if (! isset($nsLetterboxd->filmTitle) || empty((string) $nsLetterboxd->filmTitle)) {
                    $nsLetterboxd = $item->children('https://letterboxd.com/');
                }
                if (! isset($nsLetterboxd->filmTitle) || empty((string) $nsLetterboxd->filmTitle)) {
                    $nsLetterboxd = $item->children('https://boxd.it/');
                }

                // Se não tiver título de filme, pode ser lista ou outro tipo de item — ignora
                if (! isset($nsLetterboxd->filmTitle) || empty((string) $nsLetterboxd->filmTitle)) {
                    continue;
                }

                $guid = trim((string) $item->guid);
                if (empty($guid)) {
                    $guid = trim((string) $item->link);
                }

                if (empty($guid)) {
                    continue;
                }

                // Prevenção estrita de duplicatas
                $alreadyExists = Post::where('user_id', $user->id)
                    ->where('external_id', $guid)
                    ->exists();

                if ($alreadyExists) {
                    continue;
                }

                $filmTitle = (string) $nsLetterboxd->filmTitle;
                $filmYear = isset($nsLetterboxd->filmYear) && (string) $nsLetterboxd->filmYear !== ''
                    ? (string) $nsLetterboxd->filmYear
                    : null;

                $rating = isset($nsLetterboxd->memberRating) && (string) $nsLetterboxd->memberRating !== ''
                    ? (float) $nsLetterboxd->memberRating
                    : null;

                $watchedDate = isset($nsLetterboxd->watchedDate) && (string) $nsLetterboxd->watchedDate !== ''
                    ? (string) $nsLetterboxd->watchedDate
                    : null;

                $rewatch = isset($nsLetterboxd->rewatch) && strtolower((string) $nsLetterboxd->rewatch) === 'yes';
                $letterboxdUrl = (string) $item->link;

                // Extrair poster e resenha da tag <description>
                $descriptionHtml = (string) $item->description;
                $posterUrl = null;
                if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $descriptionHtml, $matches)) {
                    $posterUrl = $matches[1];
                }

                // Limpeza do HTML para obter texto da resenha
                $cleanedHtml = preg_replace('/<p>\s*<img[^>]+>\s*<\/p>/i', '', $descriptionHtml);
                $reviewText = trim(strip_tags($cleanedHtml));

                // Se o texto for apenas aviso padrão de data (ex: "Watched on Thursday September 17, 2026.")
                if (preg_match('/^Watched on .+\.?$/i', $reviewText)) {
                    $reviewText = null;
                }

                $pubDate = isset($item->pubDate)
                    ? Carbon::parse((string) $item->pubDate)
                    : now();

                $watchedAt = $watchedDate
                    ? Carbon::parse($watchedDate)->startOfDay()
                    : $pubDate;

                $newPost = Post::create([
                    'user_id' => $user->id,
                    'category' => 'entertainment',
                    'entertainment_type' => 'movie',
                    'external_source' => 'letterboxd',
                    'external_id' => $guid,
                    'watched_at' => $watchedAt,
                    'content' => $reviewText,
                    'metadata' => [
                        'film_title' => $filmTitle,
                        'film_year' => $filmYear,
                        'rating' => $rating,
                        'watched_date' => $watchedDate,
                        'rewatch' => $rewatch,
                        'poster_url' => $posterUrl,
                        'letterboxd_url' => $letterboxdUrl,
                        'review_text' => $reviewText,
                    ],
                    'created_at' => $watchedAt,
                    'updated_at' => $pubDate,
                ]);

                try {
                    $newPost->load([
                        'user',
                        'media',
                        'comments.user',
                        'comments.parent.user',
                        'comments.mentions',
                        'mentions',
                    ]);
                    $newPost->is_liked = false;
                    $newPost->likes_count = 0;
                    $newPost->comments_count = 0;
                    broadcast(new PostCreated($newPost));
                } catch (\Throwable $e) {
                    report($e);
                }

                $newItemsCount++;
            }

            $user->update([
                'letterboxd_last_synced_at' => now(),
            ]);

            return $newItemsCount;
        } catch (\Exception $e) {
            Log::error("Exceção ao sincronizar Letterboxd para {$username}: {$e->getMessage()}");
            throw $e;
        }
    }
}
