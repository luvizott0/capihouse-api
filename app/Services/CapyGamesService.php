<?php

namespace App\Services;

use App\Events\PostCreated;
use App\Models\CapyGame;
use App\Models\CapyGameActivity;
use App\Models\CapyUserGame;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CapyGamesService
{
    /**
     * Busca jogos em catálogo (Steam, OpenXBL e jogos já cadastrados localmente)
     */
    public function searchGames(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $cacheKey = 'capygames_search_'.md5(mb_strtolower($query));

        return Cache::remember($cacheKey, 1800, function () use ($query) {
            $results = [];
            $seenTitles = [];

            // 1. Jogos já cadastrados localmente no banco
            $localGames = CapyGame::where('title', 'like', "%{$query}%")
                ->limit(6)
                ->get();

            foreach ($localGames as $lg) {
                $norm = mb_strtolower(trim($lg->title));
                $seenTitles[$norm] = true;
                $results[] = [
                    'id' => $lg->id,
                    'external_id' => $lg->external_id,
                    'external_source' => $lg->external_source,
                    'title' => $lg->title,
                    'cover_url' => $lg->cover_url,
                    'banner_url' => $lg->banner_url,
                    'summary' => $lg->summary,
                    'developer' => $lg->developer,
                    'publisher' => $lg->publisher,
                    'platforms' => $lg->platforms ?? ['PC'],
                    'genres' => $lg->genres ?? [],
                ];
            }

            // 2. Busca na Steam Store Search
            try {
                $steamRes = Http::timeout(4)->get('https://store.steampowered.com/api/storesearch/', [
                    'term' => $query,
                    'l' => 'brazilian',
                    'cc' => 'BR',
                ]);

                if ($steamRes->successful()) {
                    $steamData = $steamRes->json();
                    foreach ($steamData['items'] ?? [] as $item) {
                        $title = trim($item['name'] ?? '');
                        if (! $title) {
                            continue;
                        }

                        $norm = mb_strtolower($title);
                        if (isset($seenTitles[$norm])) {
                            continue;
                        }
                        $seenTitles[$norm] = true;

                        $appId = $item['id'] ?? null;
                        $coverUrl = $appId
                            ? "https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/{$appId}/library_600x900.jpg"
                            : ($item['tiny_image'] ?? null);

                        $bannerUrl = $appId
                            ? "https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/{$appId}/header.jpg"
                            : null;

                        $results[] = [
                            'id' => null, // ainda não salvo localmente
                            'external_id' => "steam:{$appId}",
                            'external_source' => 'steam',
                            'title' => $title,
                            'cover_url' => $coverUrl,
                            'banner_url' => $bannerUrl,
                            'summary' => null,
                            'developer' => null,
                            'publisher' => null,
                            'platforms' => ['PC'],
                            'genres' => [],
                        ];

                        if (count($results) >= 14) {
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('CapyGames Steam search failed: '.$e->getMessage());
            }

            // 3. Busca complementar no Marketplace Xbox via OpenXBL Autosuggest (se configurado)
            $openxblKey = config('services.openxbl.api_key');
            if ($openxblKey && count($results) < 14) {
                try {
                    $xblRes = Http::withHeaders([
                        'X-Authorization' => $openxblKey,
                        'Accept' => 'application/json',
                    ])->timeout(3)->get('https://api.xbl.io/v2/marketplace/autosuggest', [
                        'q' => $query,
                    ]);

                    if ($xblRes->successful()) {
                        $xblData = $xblRes->json();
                        $families = $xblData['content']['Results'] ?? [];

                        foreach ($families as $family) {
                            if (($family['ProductFamilyName'] ?? '') === 'Games') {
                                foreach ($family['Products'] ?? [] as $prod) {
                                    $title = trim($prod['Title'] ?? '');
                                    if (! $title) {
                                        continue;
                                    }

                                    $norm = mb_strtolower($title);
                                    if (isset($seenTitles[$norm])) {
                                        continue;
                                    }
                                    $seenTitles[$norm] = true;

                                    $icon = $prod['Icon'] ?? null;
                                    if ($icon && str_starts_with($icon, '//')) {
                                        $icon = 'https:'.$icon;
                                    }

                                    $results[] = [
                                        'id' => null,
                                        'external_id' => 'xbox:'.($prod['ProductId'] ?? Str::uuid()),
                                        'external_source' => 'xbox',
                                        'title' => $title,
                                        'cover_url' => $icon,
                                        'banner_url' => null,
                                        'summary' => null,
                                        'developer' => null,
                                        'publisher' => null,
                                        'platforms' => ['Xbox Series X|S', 'Xbox One'],
                                        'genres' => [],
                                    ];

                                    if (count($results) >= 16) {
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('CapyGames Xbox search failed: '.$e->getMessage());
                }
            }

            return $results;
        });
    }

    /**
     * Retorna jogos em destaque / populares
     */
    public function getPopularGames(): array
    {
        return Cache::remember('capygames_popular_featured', 3600, function () {
            // Jogos populares no Steam / comunidade
            $featured = [
                ['id' => 'steam:1091500', 'title' => 'Cyberpunk 2077', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1091500/library_600x900.jpg'],
                ['id' => 'steam:1245620', 'title' => 'ELDEN RING', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1245620/library_600x900.jpg'],
                ['id' => 'steam:292030', 'title' => 'The Witcher 3: Wild Hunt', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/292030/library_600x900.jpg'],
                ['id' => 'steam:1086940', 'title' => "Baldur's Gate 3", 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1086940/library_600x900.jpg'],
                ['id' => 'steam:1172470', 'title' => 'Apex Legends', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1172470/library_600x900.jpg'],
                ['id' => 'steam:730', 'title' => 'Counter-Strike 2', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/730/library_600x900.jpg'],
                ['id' => 'steam:2358720', 'title' => 'Black Myth: Wukong', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/2358720/library_600x900.jpg'],
                ['id' => 'steam:883710', 'title' => 'Resident Evil 2', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/883710/library_600x900.jpg'],
                ['id' => 'steam:1145360', 'title' => 'Hades', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1145360/library_600x900.jpg'],
                ['id' => 'steam:413150', 'title' => 'Stardew Valley', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/413150/library_600x900.jpg'],
                ['id' => 'steam:105600', 'title' => 'Terraria', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/105600/library_600x900.jpg'],
                ['id' => 'steam:1151640', 'title' => 'Horizon Zero Dawn™ Complete Edition', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1151640/library_600x900.jpg'],
            ];

            $output = [];
            foreach ($featured as $f) {
                $game = $this->findOrCreateGame([
                    'external_id' => $f['id'],
                    'external_source' => 'steam',
                    'title' => $f['title'],
                    'cover_url' => $f['cover'],
                    'platforms' => ['PC', 'PlayStation', 'Xbox'],
                ]);
                $output[] = $game->toArray();
            }

            return $output;
        });
    }

    /**
     * Garante a existência do jogo na tabela `capy_games`
     */
    public function findOrCreateGame(array $gameData): CapyGame
    {
        $externalSource = $gameData['external_source'] ?? 'manual';
        $externalId = $gameData['external_id'] ?? ('manual:'.Str::uuid());

        $game = CapyGame::where('external_source', $externalSource)
            ->where('external_id', $externalId)
            ->first();

        if ($game) {
            // Atualiza cover_url ou title se faltar
            if (empty($game->cover_url) && ! empty($gameData['cover_url'])) {
                $game->cover_url = $gameData['cover_url'];
                $game->save();
            }

            return $game;
        }

        $title = trim($gameData['title'] ?? 'Jogo sem título');
        $coverUrl = $gameData['cover_url'] ?? null;
        $bannerUrl = $gameData['banner_url'] ?? null;
        $summary = $gameData['summary'] ?? null;
        $developer = $gameData['developer'] ?? null;
        $publisher = $gameData['publisher'] ?? null;
        $platforms = $gameData['platforms'] ?? ['PC'];

        // Se for jogo da Steam e não temos banner ou summary, busca detalhes via API Steam
        if ($externalSource === 'steam' && str_starts_with($externalId, 'steam:')) {
            $appId = str_replace('steam:', '', $externalId);
            try {
                $detailsRes = Http::timeout(3)->get("https://store.steampowered.com/api/appdetails?appids={$appId}&l=brazilian");
                if ($detailsRes->successful()) {
                    $json = $detailsRes->json();
                    if (($json[$appId]['success'] ?? false) && isset($json[$appId]['data'])) {
                        $appData = $json[$appId]['data'];
                        $bannerUrl = $bannerUrl ?: ($appData['header_image'] ?? null);
                        $summary = $summary ?: strip_tags($appData['short_description'] ?? '');
                        $developer = $developer ?: implode(', ', $appData['developers'] ?? []);
                        $publisher = $publisher ?: implode(', ', $appData['publishers'] ?? []);
                    }
                }
            } catch (\Throwable $e) {
                // segue adiante com dados parciais
            }
        }

        return CapyGame::create([
            'title' => $title,
            'slug' => Str::slug($title),
            'external_source' => $externalSource,
            'external_id' => $externalId,
            'cover_url' => $coverUrl,
            'banner_url' => $bannerUrl,
            'summary' => $summary,
            'developer' => $developer,
            'publisher' => $publisher,
            'platforms' => is_array($platforms) ? $platforms : [$platforms],
            'genres' => $gameData['genres'] ?? [],
            'raw_data' => $gameData['raw_data'] ?? null,
        ]);
    }

    /**
     * Define ou atualiza o status de um jogo na biblioteca do usuário
     */
    public function setGameStatus(User $user, CapyGame $game, array $params): CapyUserGame
    {
        $userGame = CapyUserGame::firstOrNew([
            'user_id' => $user->id,
            'game_id' => $game->id,
        ]);

        $oldStatus = $userGame->exists ? $userGame->status : null;
        $newStatus = $params['status'] ?? ($oldStatus ?: 'plan_to_play');

        $userGame->status = $newStatus;
        if (isset($params['platform'])) {
            $userGame->platform = $params['platform'];
        }
        if (isset($params['hours_played'])) {
            $userGame->hours_played = (float) $params['hours_played'];
        }
        if (array_key_exists('rating', $params)) {
            $userGame->rating = $params['rating'] !== null ? (float) $params['rating'] : null;
        }
        if (array_key_exists('review', $params)) {
            $userGame->review = $params['review'] !== null ? trim($params['review']) : null;
        }
        if (isset($params['source'])) {
            $userGame->source = $params['source'];
        }
        if (isset($params['is_favorite'])) {
            $userGame->is_favorite = (bool) $params['is_favorite'];
        }

        if (in_array($newStatus, ['completed', 'mastered'], true) && empty($userGame->completed_at)) {
            $userGame->completed_at = now();
        }
        if ($newStatus === 'playing' && empty($userGame->started_at)) {
            $userGame->started_at = now();
        }

        $userGame->save();

        // 1. Sempre registra atividade no feed dedicado do CapyGames
        $this->recordCapyGameActivity($user, $game, $userGame, $oldStatus, $newStatus);

        // 2. Sincroniza com o Feed Geral da Capihouse APENAS para marcos importantes
        // (completou, platinou, abandonou ou publicou review)
        $this->syncMainCapihouseFeed($user, $game, $userGame);

        return $userGame;
    }

    /**
     * Remove um jogo da biblioteca do usuário
     */
    public function removeGame(User $user, int $userGameId): bool
    {
        $userGame = CapyUserGame::where('user_id', $user->id)->where('id', $userGameId)->first();
        if (! $userGame) {
            return false;
        }

        $gameId = $userGame->game_id;
        $userGame->delete();

        // Remove post associado no feed geral da Capihouse se existir
        Post::where('user_id', $user->id)
            ->where('external_source', 'capygames')
            ->where('external_id', "capygames:game:{$gameId}:user:{$user->id}")
            ->delete();

        return true;
    }

    /**
     * Registra evento no feed do CapyGames (conquistas, zeramentos, reviews)
     */
    public function recordCapyGameActivity(
        User $user,
        CapyGame $game,
        CapyUserGame $userGame,
        ?string $oldStatus,
        string $newStatus
    ): void {
        $hasReview = ! empty($userGame->review);
        $hasRating = $userGame->rating !== null && $userGame->rating > 0;

        $type = 'status_change';
        $title = "Atualizou o status de {$game->title}";

        if ($newStatus === 'mastered') {
            $type = 'achievement';
            $title = "🏆 Platinou / Fez 100% em {$game->title}!";
        } elseif ($newStatus === 'completed') {
            $type = 'status_change';
            $title = "🏁 Zerou {$game->title}!";
        } elseif ($newStatus === 'dropped') {
            $type = 'status_change';
            $title = "🛑 Abandonou {$game->title}";
        } elseif ($hasReview) {
            $type = 'review';
            $title = "📝 Publicou uma análise de {$game->title}";
        } elseif ($newStatus === 'playing') {
            $title = "🎮 Começou a jogar {$game->title}";
        } elseif ($newStatus === 'plan_to_play') {
            $title = "📌 Adicionou {$game->title} ao backlog (a jogar)";
        }

        CapyGameActivity::create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'type' => $type,
            'title' => $title,
            'content' => $userGame->review,
            'metadata' => [
                'status' => $newStatus,
                'rating' => $userGame->rating,
                'hours_played' => $userGame->hours_played,
                'platform' => $userGame->platform,
                'cover_url' => $game->cover_url,
                'game_title' => $game->title,
                'achievements_earned' => $userGame->achievements_earned,
                'achievements_total' => $userGame->achievements_total,
            ],
        ]);
    }

    /**
     * Alimenta o Feed Geral da Capihouse (apenas para jogos completados, platinados, abandonados ou com análise)
     */
    public function syncMainCapihouseFeed(User $user, CapyGame $game, CapyUserGame $userGame): void
    {
        $status = $userGame->status;
        $hasReview = ! empty($userGame->review);
        $hasRating = $userGame->rating !== null && $userGame->rating > 0;

        // Regra do usuário: Apenas jogos completados, platinados, abandonados ou com review vão para o feed da Capihouse
        $shouldPost = in_array($status, ['completed', 'mastered', 'dropped'], true) || $hasReview;

        $externalId = "capygames:game:{$game->id}:user:{$user->id}";

        if (! $shouldPost) {
            // Se mudou para um status não visível no feed geral (ex: 'plan_to_play' ou 'playing' sem review),
            // remove post antigo se houvesse
            Post::where('user_id', $user->id)
                ->where('external_source', 'capygames')
                ->where('external_id', $externalId)
                ->delete();

            return;
        }

        $headline = match ($status) {
            'mastered' => "🏆 Platinou / Fez 100% em {$game->title}!",
            'completed' => "🏁 Zerou {$game->title}!",
            'dropped' => "🛑 Abandonou {$game->title}",
            default => "🎮 Jogando {$game->title}",
        };

        if ($userGame->rating !== null) {
            $headline .= " Nota: {$userGame->rating}/10";
        }

        $content = $headline;
        if ($hasReview) {
            $content .= "\n\n\"{$userGame->review}\"";
        }

        $post = Post::updateOrCreate(
            [
                'user_id' => $user->id,
                'external_source' => 'capygames',
                'external_id' => $externalId,
            ],
            [
                'category' => 'entertainment',
                'entertainment_type' => 'game',
                'watched_at' => $userGame->completed_at ?? now(),
                'content' => $content,
                'metadata' => [
                    'app' => 'capygames',
                    'game_id' => $game->id,
                    'game_title' => $game->title,
                    'cover_url' => $game->cover_url,
                    'platform' => $userGame->platform,
                    'game_status' => $status,
                    'rating' => $userGame->rating,
                    'hours_played' => $userGame->hours_played,
                    'review_text' => $userGame->review,
                    'achievements_count' => $userGame->achievements_earned,
                    'achievements_total' => $userGame->achievements_total,
                ],
            ]
        );

        try {
            $post->load([
                'user',
                'media',
                'comments.user',
                'comments.parent.user',
                'comments.mentions',
                'mentions',
            ]);
            broadcast(new PostCreated($post));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Retorna a biblioteca do usuário com filtros opcionais
     */
    public function getUserLibrary(int $userId, ?string $status = null, ?string $platform = null)
    {
        $query = CapyUserGame::with('game')
            ->where('user_id', $userId);

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($platform && $platform !== 'all') {
            $query->where('platform', $platform);
        }

        return $query->orderByDesc('updated_at')->get();
    }

    /**
     * Enriquece metadados do jogo (sinopse, prints, recursos, conquistas) se ainda não existirem
     */
    public function enrichGameMetadata(CapyGame $game): CapyGame
    {
        if ($game->external_source === 'steam' || str_starts_with($game->external_id ?? '', 'steam:')) {
            $parts = explode(':', $game->external_id ?? '');
            $appId = $parts[1] ?? null;

            if ($appId && (empty($game->summary) || empty($game->raw_data['screenshots'] ?? null))) {
                try {
                    $res = Http::timeout(4)->get('https://store.steampowered.com/api/appdetails', [
                        'appids' => $appId,
                        'l' => 'brazilian',
                        'cc' => 'BR',
                    ]);

                    if ($res->successful()) {
                        $json = $res->json();
                        $data = $json[$appId]['data'] ?? null;
                        if ($data) {
                            if (empty($game->summary) && ! empty($data['short_description'])) {
                                $game->summary = $data['short_description'];
                            }
                            if (empty($game->developer) && ! empty($data['developers'])) {
                                $game->developer = implode(', ', $data['developers']);
                            }
                            if (empty($game->publisher) && ! empty($data['publishers'])) {
                                $game->publisher = implode(', ', $data['publishers']);
                            }
                            if (empty($game->banner_url) && ! empty($data['header_image'])) {
                                $game->banner_url = $data['header_image'];
                            }
                            if (! empty($data['genres']) && (empty($game->genres) || count($game->genres) === 0)) {
                                $game->genres = array_column($data['genres'], 'description');
                            }

                            $plats = [];
                            if (! empty($data['platforms']['windows'])) {
                                $plats[] = 'PC (Windows)';
                            }
                            if (! empty($data['platforms']['mac'])) {
                                $plats[] = 'Mac';
                            }
                            if (! empty($data['platforms']['linux'])) {
                                $plats[] = 'Linux / Steam Deck';
                            }
                            if (! empty($plats)) {
                                $game->platforms = $plats;
                            }

                            $screenshots = [];
                            foreach ($data['screenshots'] ?? [] as $s) {
                                $screenshots[] = [
                                    'id' => $s['id'] ?? null,
                                    'thumbnail' => $s['path_thumbnail'] ?? null,
                                    'full' => $s['path_full'] ?? null,
                                ];
                            }

                            $categories = array_column($data['categories'] ?? [], 'description');

                            $raw = $game->raw_data ?? [];
                            $raw['screenshots'] = $screenshots;
                            $raw['categories'] = $categories;
                            $raw['achievements_count'] = $data['achievements']['total'] ?? 0;
                            $raw['release_date'] = $data['release_date']['date'] ?? null;
                            $raw['metacritic'] = $data['metacritic']['score'] ?? null;

                            $game->raw_data = $raw;
                            $game->save();
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Falha ao enriquecer jogo {$game->id} do Steam ({$appId}): {$e->getMessage()}");
                }
            }
        }

        return $game;
    }

    /**
     * Retorna os detalhes de um jogo, estatísticas e amigos da casa que o possuem
     */
    public function getGameDetails(int $gameId, User $currentUser): array
    {
        $game = CapyGame::findOrFail($gameId);
        $game = $this->enrichGameMetadata($game);

        $myStatus = CapyUserGame::where('user_id', $currentUser->id)
            ->where('game_id', $gameId)
            ->first();

        // Moradores da Capihouse que têm o jogo na biblioteca
        $housemates = CapyUserGame::with('user:id,name,username,avatar_url')
            ->where('game_id', $gameId)
            ->get()
            ->map(function ($ug) {
                return [
                    'user' => $ug->user,
                    'status' => $ug->status,
                    'platform' => $ug->platform,
                    'rating' => $ug->rating,
                    'hours_played' => $ug->hours_played,
                    'review' => $ug->review,
                    'completed_at' => $ug->completed_at,
                ];
            });

        // Média de notas da comunidade
        $avgRating = CapyUserGame::where('game_id', $gameId)
            ->whereNotNull('rating')
            ->avg('rating');

        $totalRatings = CapyUserGame::where('game_id', $gameId)
            ->whereNotNull('rating')
            ->count();

        return [
            'game' => $game,
            'my_status' => $myStatus,
            'community_rating' => $avgRating ? round($avgRating, 1) : null,
            'total_ratings' => $totalRatings,
            'housemates' => $housemates,
        ];
    }

    /**
     * Feed comunitário de jogos da Capihouse
     */
    public function getCommunityFeed(int $limit = 30)
    {
        return CapyGameActivity::with([
            'user:id,name,username,avatar_url',
            'game:id,title,cover_url,banner_url',
        ])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Perfil Gamer e estatísticas de um usuário
     */
    public function getUserGamerProfile(int $userId): array
    {
        $user = User::select('id', 'name', 'username', 'avatar_url', 'banner_url', 'xbox_gamertag', 'steam_username')->findOrFail($userId);

        $counts = CapyUserGame::where('user_id', $userId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $totalGames = CapyUserGame::where('user_id', $userId)->count();
        $totalCompleted = ($counts['completed'] ?? 0) + ($counts['mastered'] ?? 0);
        $totalMastered = $counts['mastered'] ?? 0;
        $totalPlaying = $counts['playing'] ?? 0;
        $totalBacklog = $counts['plan_to_play'] ?? 0;
        $totalDropped = $counts['dropped'] ?? 0;

        $totalHours = CapyUserGame::where('user_id', $userId)->sum('hours_played');

        $avgRating = CapyUserGame::where('user_id', $userId)
            ->whereNotNull('rating')
            ->avg('rating');

        // Últimos jogos atualizados
        $recentGames = CapyUserGame::with('game')
            ->where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        return [
            'user' => $user,
            'stats' => [
                'total_games' => $totalGames,
                'completed' => $totalCompleted,
                'mastered' => $totalMastered,
                'playing' => $totalPlaying,
                'backlog' => $totalBacklog,
                'dropped' => $totalDropped,
                'total_hours' => round((float) $totalHours, 1),
                'avg_rating' => $avgRating ? round((float) $avgRating, 1) : null,
            ],
            'recent_games' => $recentGames,
        ];
    }
}
