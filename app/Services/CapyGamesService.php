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
                ->limit(20)
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

                        if (count($results) >= 30) {
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('CapyGames Steam search failed: '.$e->getMessage());
            }

            // 3. Busca complementar no Marketplace Xbox via OpenXBL Autosuggest (se configurado)
            $openxblKey = config('services.openxbl.api_key');
            if ($openxblKey && count($results) < 30) {
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

                                    if (count($results) >= 40) {
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

            // 4. Busca no catálogo da Nintendo (Switch / consoles) via Algolia oficial
            if (count($results) < 40) {
                try {
                    $nintendoRes = Http::timeout(3)->withHeaders([
                        'X-Algolia-Application-Id' => 'U3B6GR4UA3',
                        'X-Algolia-API-Key' => 'c4da8be7fd29f0f5bfa42920b0a99dc7',
                    ])->post('https://u3b6gr4ua3-dsn.algolia.net/1/indexes/*/queries', [
                        'requests' => [
                            [
                                'indexName' => 'ncom_game_en_us',
                                'params' => http_build_query([
                                    'query' => $query,
                                    'hitsPerPage' => 15,
                                ]),
                            ],
                        ],
                    ]);

                    if ($nintendoRes->successful()) {
                        $hits = $nintendoRes->json('results.0.hits') ?? [];
                        foreach ($hits as $hit) {
                            $rawTitle = $hit['title'] ?? '';
                            $title = trim(str_replace(['™', '®'], '', $rawTitle));
                            if (! $title) {
                                continue;
                            }

                            $norm = mb_strtolower($title);
                            if (isset($seenTitles[$norm])) {
                                continue;
                            }
                            $seenTitles[$norm] = true;

                            $cover = $hit['boxart'] ?? $hit['boxArt'] ?? $hit['horizontalHeaderImage'] ?? null;
                            $slug = $hit['slug'] ?? Str::slug($title);
                            $summary = ! empty($hit['description']) ? strip_tags($hit['description']) : null;
                            $developer = ! empty($hit['developers']) && is_array($hit['developers']) ? implode(', ', $hit['developers']) : null;
                            $publisher = ! empty($hit['publishers']) && is_array($hit['publishers']) ? implode(', ', $hit['publishers']) : null;
                            $genres = ! empty($hit['genres']) && is_array($hit['genres']) ? $hit['genres'] : [];
                            $plat = ! empty($hit['platform']) ? $hit['platform'] : 'Nintendo Switch';

                            $results[] = [
                                'id' => null,
                                'external_id' => "nintendo:{$slug}",
                                'external_source' => 'nintendo',
                                'title' => $title,
                                'cover_url' => $cover,
                                'banner_url' => $hit['horizontalHeaderImage'] ?? null,
                                'summary' => $summary,
                                'developer' => $developer,
                                'publisher' => $publisher,
                                'platforms' => [is_string($plat) ? $plat : 'Nintendo Switch'],
                                'genres' => $genres,
                            ];

                            if (count($results) >= 40) {
                                break;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('CapyGames Nintendo search failed: '.$e->getMessage());
                }
            }

            return $results;
        });
    }

    /**
     * Retorna jogos em destaque / populares com paginação e catálogo expandido
     */
    public function getPopularGames(int $page = 1, int $perPage = 24): array
    {
        $allPopular = Cache::remember('capygames_popular_catalog_v4', 43200, function () {
            $seenTitles = [];
            $output = [];

            // 1. Jogos já existentes no banco local que têm cover_url válida
            $localGames = CapyGame::whereNotNull('cover_url')
                ->where('cover_url', '!=', '')
                ->orderByDesc('id')
                ->get();

            foreach ($localGames as $lg) {
                $norm = mb_strtolower(trim($lg->title));
                if (isset($seenTitles[$norm])) {
                    continue;
                }
                $seenTitles[$norm] = true;
                $output[] = $lg->toArray();
            }

            // 2. Acervo curado de grandes sucessos e jogos aclamados
            $curated = [
                // Top RPGs / Ação / Aventura
                ['id' => 'steam:1091500', 'title' => 'Cyberpunk 2077', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1091500/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['RPG', 'Ação', 'Ficção Científica']],
                ['id' => 'steam:1245620', 'title' => 'ELDEN RING', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1245620/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['RPG', 'Ação', 'Souls-like']],
                ['id' => 'steam:292030', 'title' => 'The Witcher 3: Wild Hunt', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/292030/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox', 'Nintendo Switch'], 'genres' => ['RPG', 'Aventura', 'Mundo Aberto']],
                ['id' => 'steam:1086940', 'title' => "Baldur's Gate 3", 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1086940/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['RPG', 'Estratégia']],
                ['id' => 'steam:1174180', 'title' => 'Red Dead Redemption 2', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1174180/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['Ação', 'Aventura', 'Mundo Aberto']],
                ['id' => 'steam:271590', 'title' => 'Grand Theft Auto V', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/271590/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['Ação', 'Aventura', 'Mundo Aberto']],
                ['id' => 'steam:1593500', 'title' => 'God of War', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1593500/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation'], 'genres' => ['Ação', 'Aventura']],
                ['id' => 'steam:1888930', 'title' => 'The Last of Us™ Part I', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1888930/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation'], 'genres' => ['Ação', 'Aventura', 'Terror']],
                ['id' => 'steam:1817070', 'title' => "Marvel's Spider-Man Remastered", 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1817070/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation'], 'genres' => ['Ação', 'Aventura']],
                ['id' => 'steam:2358720', 'title' => 'Black Myth: Wukong', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/2358720/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation'], 'genres' => ['Ação', 'RPG']],
                ['id' => 'steam:2050650', 'title' => 'Resident Evil 4', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/2050650/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['Terror', 'Ação']],
                ['id' => 'steam:883710', 'title' => 'Resident Evil 2', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/883710/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['Terror', 'Ação']],
                ['id' => 'steam:1145360', 'title' => 'Hades', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1145360/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox', 'Nintendo Switch'], 'genres' => ['Indie', 'Ação', 'Roguelike']],
                ['id' => 'steam:1145350', 'title' => 'Hades II', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1145350/library_600x900.jpg', 'platforms' => ['PC'], 'genres' => ['Indie', 'Ação', 'Roguelike']],
                ['id' => 'steam:367520', 'title' => 'Hollow Knight', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/367520/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox', 'Nintendo Switch'], 'genres' => ['Indie', 'Metroidvania', 'Aventura']],
                ['id' => 'steam:504230', 'title' => 'Celeste', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/504230/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox', 'Nintendo Switch'], 'genres' => ['Indie', 'Plataforma']],
                ['id' => 'steam:413150', 'title' => 'Stardew Valley', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/413150/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox', 'Nintendo Switch'], 'genres' => ['Indie', 'Simulação', 'RPG']],
                ['id' => 'steam:105600', 'title' => 'Terraria', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/105600/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox', 'Nintendo Switch'], 'genres' => ['Indie', 'Aventura', 'Sandbox']],
                ['id' => 'steam:588650', 'title' => 'Dead Cells', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/588650/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox', 'Nintendo Switch'], 'genres' => ['Indie', 'Roguelike', 'Ação']],
                ['id' => 'steam:814380', 'title' => 'Sekiro: Shadows Die Twice', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/814380/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['Ação', 'Souls-like']],
                ['id' => 'steam:374320', 'title' => 'DARK SOULS™ III', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/374320/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['RPG', 'Ação', 'Souls-like']],
                ['id' => 'steam:582010', 'title' => 'Monster Hunter: World', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/582010/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['RPG', 'Ação']],
                ['id' => 'steam:1687950', 'title' => 'Persona 5 Royal', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1687950/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox', 'Nintendo Switch'], 'genres' => ['RPG', 'JRPG', 'Anime']],
                ['id' => 'steam:1551360', 'title' => 'Forza Horizon 5', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1551360/library_600x900.jpg', 'platforms' => ['PC', 'Xbox'], 'genres' => ['Corrida', 'Mundo Aberto']],
                ['id' => 'steam:1172620', 'title' => 'Sea of Thieves', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1172620/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['Aventura', 'Multijogador']],
                ['id' => 'steam:1240440', 'title' => 'Halo Infinite', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1240440/library_600x900.jpg', 'platforms' => ['PC', 'Xbox'], 'genres' => ['Tiro / FPS', 'Ação']],
                ['id' => 'steam:1716740', 'title' => 'Starfield', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1716740/library_600x900.jpg', 'platforms' => ['PC', 'Xbox'], 'genres' => ['RPG', 'Ficção Científica']],
                ['id' => 'steam:782330', 'title' => 'DOOM Eternal', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/782330/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox', 'Nintendo Switch'], 'genres' => ['Tiro / FPS', 'Ação']],
                ['id' => 'steam:377160', 'title' => 'Fallout 4', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/377160/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['RPG', 'Mundo Aberto', 'Ação']],
                ['id' => 'steam:489830', 'title' => 'The Elder Scrolls V: Skyrim Special Edition', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/489830/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox', 'Nintendo Switch'], 'genres' => ['RPG', 'Mundo Aberto']],
                ['id' => 'steam:990080', 'title' => 'Hogwarts Legacy', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/990080/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox', 'Nintendo Switch'], 'genres' => ['RPG', 'Ação', 'Aventura']],
                ['id' => 'steam:553850', 'title' => 'HELLDIVERS™ 2', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/553850/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation'], 'genres' => ['Tiro / FPS', 'Ação', 'Multijogador']],
                ['id' => 'steam:1623730', 'title' => 'Palworld', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1623730/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['Aventura', 'Sobrevivência', 'RPG']],
                ['id' => 'steam:1172470', 'title' => 'Apex Legends', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1172470/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox', 'Nintendo Switch'], 'genres' => ['Tiro / FPS', 'Battle Royale']],
                ['id' => 'steam:730', 'title' => 'Counter-Strike 2', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/730/library_600x900.jpg', 'platforms' => ['PC'], 'genres' => ['Tiro / FPS', 'Competitivo']],
                ['id' => 'steam:570', 'title' => 'Dota 2', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/570/library_600x900.jpg', 'platforms' => ['PC'], 'genres' => ['Estratégia', 'MOBA']],
                ['id' => 'steam:264710', 'title' => 'Subnautica', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/264710/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox', 'Nintendo Switch'], 'genres' => ['Aventura', 'Sobrevivência']],
                ['id' => 'steam:632470', 'title' => 'Disco Elysium - The Final Cut', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/632470/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox', 'Nintendo Switch'], 'genres' => ['RPG', 'Indie', 'Narrativo']],
                ['id' => 'steam:646570', 'title' => 'Slay the Spire', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/646570/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox', 'Nintendo Switch'], 'genres' => ['Indie', 'Estratégia', 'Roguelike']],
                ['id' => 'steam:2379780', 'title' => 'Balatro', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/2379780/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox', 'Nintendo Switch'], 'genres' => ['Indie', 'Estratégia', 'Roguelike']],
                ['id' => 'steam:2620600', 'title' => 'Metaphor: ReFantazio', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/2620600/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['RPG', 'JRPG']],
                ['id' => 'steam:2124490', 'title' => 'SILENT HILL 2', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/2124490/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation'], 'genres' => ['Terror', 'Ação']],
                ['id' => 'steam:1462040', 'title' => 'FINAL FANTASY VII REMAKE INTERGRADE', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1462040/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation'], 'genres' => ['RPG', 'Ação']],
                ['id' => 'steam:1627720', 'title' => 'Lies of P', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1627720/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['Ação', 'Souls-like']],
                ['id' => 'steam:1888040', 'title' => 'ARMORED CORE™ VI FIRES OF RUBICON™', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1888040/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['Ação', 'Ficção Científica']],
                ['id' => 'steam:1778820', 'title' => 'TEKKEN 8', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1778820/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['Luta', 'Ação']],
                ['id' => 'steam:1364780', 'title' => 'Street Fighter™ 6', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1364780/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['Luta', 'Ação']],
                ['id' => 'steam:1426210', 'title' => 'It Takes Two', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1426210/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox', 'Nintendo Switch'], 'genres' => ['Aventura', 'Cooperativo']],
                ['id' => 'steam:1057090', 'title' => 'Ori and the Will of the Wisps', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1057090/library_600x900.jpg', 'platforms' => ['PC', 'Xbox', 'Nintendo Switch'], 'genres' => ['Plataforma', 'Aventura', 'Metroidvania']],
                ['id' => 'steam:268910', 'title' => 'Cuphead', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/268910/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox', 'Nintendo Switch'], 'genres' => ['Ação', 'Indie', 'Plataforma']],
                ['id' => 'steam:1868140', 'title' => 'DAVE THE DIVER', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1868140/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Nintendo Switch'], 'genres' => ['Indie', 'Aventura', 'Simulação']],
                ['id' => 'steam:1244090', 'title' => 'Sea of Stars', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1244090/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox', 'Nintendo Switch'], 'genres' => ['Indie', 'RPG', 'Retro']],
                ['id' => 'steam:2054970', 'title' => "Dragon's Dogma 2", 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/2054970/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['RPG', 'Ação', 'Mundo Aberto']],
                ['id' => 'steam:2215430', 'title' => 'Ghost of Tsushima DIRECTOR\'S CUT', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/2215430/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation'], 'genres' => ['Ação', 'Aventura', 'Mundo Aberto']],
                ['id' => 'steam:1850570', 'title' => 'DEATH STRANDING DIRECTOR\'S CUT', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1850570/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['Ação', 'Aventura']],
                ['id' => 'steam:1817230', 'title' => 'Hi-Fi RUSH', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1817230/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['Ação', 'Ritmo']],
                ['id' => 'steam:870780', 'title' => 'Control Ultimate Edition', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/870780/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation', 'Xbox'], 'genres' => ['Ação', 'Aventura', 'Ficção Científica']],
                ['id' => 'steam:1151640', 'title' => 'Horizon Zero Dawn™ Complete Edition', 'cover' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1151640/library_600x900.jpg', 'platforms' => ['PC', 'PlayStation'], 'genres' => ['Ação', 'RPG', 'Mundo Aberto']],

                // Nintendo Switch
                ['id' => 'nintendo:zelda_totk', 'source' => 'nintendo', 'title' => 'The Legend of Zelda: Tears of the Kingdom', 'cover' => 'https://images.igdb.com/igdb/image/upload/t_cover_big/co5vmg.jpg', 'platforms' => ['Nintendo Switch'], 'genres' => ['Ação', 'Aventura', 'Mundo Aberto']],
                ['id' => 'nintendo:zelda_botw', 'source' => 'nintendo', 'title' => 'The Legend of Zelda: Breath of the Wild', 'cover' => 'https://assets.nintendo.com/image/upload/ncom/en_US/games/switch/t/the-legend-of-zelda-breath-of-the-wild-switch/boxart', 'platforms' => ['Nintendo Switch'], 'genres' => ['Ação', 'Aventura', 'Mundo Aberto']],
                ['id' => 'nintendo:mario_odyssey', 'source' => 'nintendo', 'title' => 'Super Mario Odyssey', 'cover' => 'https://assets.nintendo.com/image/upload/ncom/en_US/games/switch/s/super-mario-odyssey-switch/boxart', 'platforms' => ['Nintendo Switch'], 'genres' => ['Plataforma', 'Aventura']],
                ['id' => 'nintendo:smash_ultimate', 'source' => 'nintendo', 'title' => 'Super Smash Bros. Ultimate', 'cover' => 'https://assets.nintendo.com/image/upload/ncom/en_US/games/switch/s/super-smash-bros-ultimate-switch/boxart', 'platforms' => ['Nintendo Switch'], 'genres' => ['Luta', 'Multijogador']],
                ['id' => 'nintendo:mario_kart_8', 'source' => 'nintendo', 'title' => 'Mario Kart 8 Deluxe', 'cover' => 'https://assets.nintendo.com/image/upload/ncom/en_US/games/switch/m/mario-kart-8-deluxe-switch/boxart', 'platforms' => ['Nintendo Switch'], 'genres' => ['Corrida', 'Multijogador']],
                ['id' => 'nintendo:animal_crossing', 'source' => 'nintendo', 'title' => 'Animal Crossing: New Horizons', 'cover' => 'https://assets.nintendo.com/image/upload/ncom/en_US/games/switch/a/animal-crossing-new-horizons-switch/boxart', 'platforms' => ['Nintendo Switch'], 'genres' => ['Simulação', 'Casual']],
                ['id' => 'nintendo:metroid_dread', 'source' => 'nintendo', 'title' => 'Metroid Dread', 'cover' => 'https://assets.nintendo.com/image/upload/ncom/en_US/games/switch/m/metroid-dread-switch/boxart', 'platforms' => ['Nintendo Switch'], 'genres' => ['Ação', 'Metroidvania']],
                ['id' => 'nintendo:pokemon_arceus', 'source' => 'nintendo', 'title' => 'Pokémon Legends: Arceus', 'cover' => 'https://assets.nintendo.com/image/upload/ncom/en_US/games/switch/p/pokemon-legends-arceus-switch/boxart', 'platforms' => ['Nintendo Switch'], 'genres' => ['RPG', 'Aventura']],
            ];

            foreach ($curated as $item) {
                $norm = mb_strtolower(trim($item['title']));
                if (isset($seenTitles[$norm])) {
                    continue;
                }
                $seenTitles[$norm] = true;

                $game = $this->findOrCreateGame([
                    'external_id' => $item['id'],
                    'external_source' => $item['source'] ?? 'steam',
                    'title' => $item['title'],
                    'cover_url' => $item['cover'],
                    'platforms' => $item['platforms'] ?? ['PC'],
                    'genres' => $item['genres'] ?? [],
                ], false);

                $output[] = $game->toArray();
            }

            return $output;
        });

        $total = count($allPopular);
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($allPopular, $offset, $perPage);
        $hasMore = ($offset + $perPage) < $total;

        return [
            'data' => $slice,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'has_more' => $hasMore,
        ];
    }

    /**
     * Garante a existência do jogo na tabela `capy_games`
     */
    public function findOrCreateGame(array $gameData, bool $enrich = false): CapyGame
    {
        $externalSource = $gameData['external_source'] ?? 'manual';
        $externalId = $gameData['external_id'] ?? ('manual:'.Str::uuid());

        $game = CapyGame::where('external_source', $externalSource)
            ->where('external_id', $externalId)
            ->first();

        if ($game) {
            $dirty = false;
            if (empty($game->cover_url) && ! empty($gameData['cover_url'])) {
                $game->cover_url = $gameData['cover_url'];
                $dirty = true;
            }
            if (empty($game->genres) && ! empty($gameData['genres'])) {
                $game->genres = $gameData['genres'];
                $dirty = true;
            }
            if (empty($game->platforms) && ! empty($gameData['platforms'])) {
                $game->platforms = $gameData['platforms'];
                $dirty = true;
            }
            if ($dirty) {
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

        // Se enriquecer detalhes sob demanda via Steam Store API
        if ($enrich && $externalSource === 'steam' && str_starts_with($externalId, 'steam:')) {
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

        if (! $userGame->exists && ($game->slug || $game->title)) {
            $existing = CapyUserGame::where('user_id', $user->id)
                ->whereHas('game', function ($q) use ($game) {
                    $q->where('slug', $game->slug)
                        ->orWhere('title', $game->title);
                })
                ->first();
            if ($existing) {
                $userGame = $existing;
            }
        }

        $oldStatus = $userGame->exists ? $userGame->status : null;
        $requestedStatus = $params['status'] ?? null;

        // Regra do usuário: Platinas (mastered) não podem ser marcadas manualmente (apenas sincronização)
        if ($requestedStatus === 'mastered') {
            $requestedStatus = $oldStatus === 'mastered' ? 'mastered' : 'completed';
        }

        // Regra do usuário: Se o jogo estava em pausa (on_hold) e teve nova atualização, volta para playing
        if ($oldStatus === 'on_hold' && empty($requestedStatus)) {
            $newStatus = 'playing';
        } elseif ($requestedStatus === 'on_hold') {
            // on_hold é apenas automático por inatividade (> 30 dias)
            $newStatus = 'playing';
        } else {
            $newStatus = $requestedStatus ?? ($oldStatus ?: 'playing');
        }

        $userGame->status = $newStatus;

        if (isset($params['platform'])) {
            $userGame->platform = $params['platform'];
        }

        // Soma das horas de todas as plataformas
        if (isset($params['hours_played'])) {
            $targetPlat = $params['platform'] ?? ($userGame->platform ?: 'PC');
            $userGame->setPlatformHours($targetPlat, (float) $params['hours_played']);
        }

        if (isset($params['in_list'])) {
            $userGame->in_list = (bool) $params['in_list'];
            if ($userGame->in_list && empty($userGame->list_order)) {
                $maxOrder = CapyUserGame::where('user_id', $user->id)->max('list_order') ?? 0;
                $userGame->list_order = $maxOrder + 1;
            }
        }

        if (array_key_exists('aspect_ratings', $params)) {
            $userGame->aspect_ratings = $params['aspect_ratings'];
            if (is_array($params['aspect_ratings'])) {
                $validScores = array_filter($params['aspect_ratings'], fn ($v) => $v !== null && is_numeric($v));
                if (! empty($validScores)) {
                    $userGame->rating = round(array_sum($validScores) / count($validScores), 1);
                } elseif (array_key_exists('rating', $params)) {
                    $userGame->rating = $params['rating'] !== null ? (float) $params['rating'] : null;
                }
            }
        } elseif (array_key_exists('rating', $params)) {
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

        // Garante que a plataforma jogada seja adicionada ao catálogo do jogo se ainda não estiver
        if (! empty($userGame->platform)) {
            $currPlats = $game->platforms ?: [];
            if (! in_array($userGame->platform, $currPlats, true)) {
                $currPlats[] = $userGame->platform;
                $game->platforms = array_values(array_unique($currPlats));
                $game->save();
            }
        }

        // 1. Sempre registra atividade no feed dedicado do CapyGames
        $this->recordCapyGameActivity($user, $game, $userGame, $oldStatus, $newStatus);

        // 2. Sincroniza com o Feed Geral da Capihouse APENAS para marcos importantes
        // (completou, platinou, abandonou ou publicou review)
        $this->syncMainCapihouseFeed($user, $game, $userGame);

        return $userGame;
    }

    /**
     * Alterna a presença de um jogo na Lista prioritária (fila de jogos a jogar)
     */
    public function toggleList(User $user, int $gameId): CapyUserGame
    {
        $targetGame = CapyGame::find($gameId);

        $userGame = CapyUserGame::firstOrNew([
            'user_id' => $user->id,
            'game_id' => $gameId,
        ]);

        if (! $userGame->exists && $targetGame && ($targetGame->slug || $targetGame->title)) {
            $existing = CapyUserGame::where('user_id', $user->id)
                ->whereHas('game', function ($q) use ($targetGame) {
                    $q->where('slug', $targetGame->slug)->orWhere('title', $targetGame->title);
                })
                ->first();
            if ($existing) {
                $userGame = $existing;
            }
        }

        if (! $userGame->exists) {
            $maxOrder = CapyUserGame::where('user_id', $user->id)->max('list_order') ?? 0;
            $userGame->in_list = true;
            $userGame->list_order = $maxOrder + 1;
            $userGame->status = 'plan_to_play';
        } else {
            $userGame->in_list = ! $userGame->in_list;
            if ($userGame->in_list && empty($userGame->list_order)) {
                $maxOrder = CapyUserGame::where('user_id', $user->id)->max('list_order') ?? 0;
                $userGame->list_order = $maxOrder + 1;
            }
        }

        $userGame->save();

        return $userGame->load('game');
    }

    /**
     * Reordena a Lista de jogos do usuário de acordo com a ordem desejada para jogar
     */
    public function reorderList(User $user, array $gameIds): array
    {
        foreach ($gameIds as $index => $gId) {
            CapyUserGame::where('user_id', $user->id)
                ->where('game_id', (int) $gId)
                ->update([
                    'list_order' => $index + 1,
                    'in_list' => true,
                ]);
        }

        return $this->getUserLibrary($user->id, 'list')->toArray();
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
                'aspect_ratings' => $userGame->aspect_ratings,
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

        if ($status === 'list') {
            // Apenas jogos que o jogador ativamente adicionou à sua Lista prioritária
            $query->where('in_list', true)
                ->orderBy('list_order', 'asc')
                ->orderByDesc('updated_at');
        } else {
            if ($status && $status !== 'all') {
                $query->where('status', $status);
            }
            $query->orderByDesc('updated_at');
        }

        if ($platform && $platform !== 'all') {
            $query->where('platform', $platform);
        }

        $items = $query->get();

        // Regra do usuário: O status "em pausa" será automático após 30 dias sem nenhuma atualização naquele jogo
        foreach ($items as $ug) {
            if ($ug->status === 'playing' && $ug->updated_at && $ug->updated_at->lt(now()->subDays(30))) {
                $ug->status = 'on_hold';
            }
        }

        return $items;
    }

    /**
     * Enriquece metadados do jogo (sinopse, prints, recursos, conquistas) se ainda não existirem
     */
    public function enrichGameMetadata(CapyGame $game): CapyGame
    {
        $needsSummary = empty($game->summary);
        $needsScreenshots = empty($game->raw_data['screenshots'] ?? null);
        $needsBanner = empty($game->banner_url);
        $needsFeatures = empty($game->raw_data['categories'] ?? null);

        if (! $needsSummary && ! $needsScreenshots && ! $needsBanner && ! $needsFeatures) {
            return $game;
        }

        $raw = $game->raw_data ?? [];

        // 1. Se for jogo da Steam com App ID
        if ($game->external_source === 'steam' || str_starts_with($game->external_id ?? '', 'steam:')) {
            $parts = explode(':', $game->external_id ?? '');
            $appId = $parts[1] ?? null;

            if ($appId) {
                $this->enrichFromSteamAppDetails($game, $appId);
            }
        }

        // 2. Se for jogo do Xbox com Title ID
        if ($game->external_source === 'xbox' || str_starts_with($game->external_id ?? '', 'xbox:')) {
            $titleId = str_replace('xbox:', '', $game->external_id ?? '');
            $openxblKey = config('services.openxbl.api_key');

            if ($openxblKey && $titleId) {
                try {
                    $res = Http::timeout(5)->withHeaders([
                        'X-Authorization' => $openxblKey,
                        'Accept' => 'application/json',
                    ])->get("https://api.xbl.io/v2/marketplace/title/{$titleId}");

                    if ($res->successful()) {
                        $json = $res->json();
                        $product = $json['content']['Products'][0] ?? null;

                        if ($product) {
                            $props = $product['LocalizedProperties'][0] ?? [];

                            if (empty($game->summary) && ! empty($props['ProductDescription'])) {
                                $game->summary = strip_tags($props['ProductDescription']);
                            }
                            if (empty($game->developer) && ! empty($props['DeveloperName'])) {
                                $game->developer = $props['DeveloperName'];
                            }
                            if (empty($game->publisher) && ! empty($props['PublisherName'])) {
                                $game->publisher = $props['PublisherName'];
                            }

                            // Banner / Hero & Screenshots
                            $hero = null;
                            $screenshots = $raw['screenshots'] ?? [];

                            foreach ($props['Images'] ?? [] as $img) {
                                $purpose = $img['ImagePurpose'] ?? '';
                                $uri = $img['Uri'] ?? '';
                                if (str_starts_with($uri, '//')) {
                                    $uri = 'https:'.$uri;
                                }

                                if (in_array($purpose, ['TitledHeroArt', 'SuperHeroArt', 'BrandedKeyArt']) && ! $hero) {
                                    $hero = $uri;
                                } elseif ($purpose === 'Screenshot') {
                                    $screenshots[] = [
                                        'thumbnail' => $uri,
                                        'full' => $uri,
                                    ];
                                }
                            }

                            if (empty($game->banner_url) && $hero) {
                                $game->banner_url = $hero;
                            }

                            if (! empty($screenshots) && empty($raw['screenshots'])) {
                                $raw['screenshots'] = $screenshots;
                            }

                            // Recursos / Categorias mapeadas
                            $attributeMap = [
                                'SinglePlayer' => 'Um jogador',
                                'OnlineMultiplayer' => 'Multijogador online',
                                'Coop' => 'Cooperativo',
                                'OnlineCoop' => 'Cooperativo online',
                                'LocalCoop' => 'Cooperativo local',
                                'CrossPlatformMultiPlayer' => 'Multijogador entre plataformas',
                                'RayTracing' => 'Ray Tracing',
                                'Capability4k' => '4K Ultra HD',
                                'CapabilityHDR' => 'HDR',
                                '60fps' => '60 fps+',
                                '120fps' => '120 fps',
                                'DolbyAtmos' => 'Dolby Atmos',
                                'SpatialAudio' => 'Áudio espacial',
                                'ConsoleGen9Optimized' => 'Otimizado para Xbox Series X|S',
                                'CapabilityXboxEnhanced' => 'Aprimorado para Xbox One X',
                                'XblAchievements' => 'Conquistas Xbox',
                                'XboxLive' => 'Xbox Live',
                                'XPA' => 'Xbox Play Anywhere',
                                'CloudSaves' => 'Nuvem Xbox',
                            ];

                            $categories = $raw['categories'] ?? [];
                            foreach ($product['Properties']['Attributes'] ?? [] as $attr) {
                                $name = $attr['Name'] ?? '';
                                if (isset($attributeMap[$name]) && ! in_array($attributeMap[$name], $categories, true)) {
                                    $categories[] = $attributeMap[$name];
                                }
                            }
                            foreach ($product['Properties']['Categories'] ?? [] as $cat) {
                                if (! in_array($cat, $categories, true)) {
                                    $categories[] = $cat;
                                }
                            }
                            if (! empty($categories)) {
                                $raw['categories'] = $categories;
                            }

                            $game->raw_data = $raw;
                            $game->save();
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Falha ao enriquecer jogo Xbox {$game->id} ({$titleId}): {$e->getMessage()}");
                }
            }
        }

        // 3. Fallback: Se ainda faltar sinopse, screenshots ou categorias, busca na Steam por título
        if (empty($game->summary) || empty($game->raw_data['screenshots'] ?? null)) {
            try {
                $steamSearchRes = Http::timeout(4)->get('https://store.steampowered.com/api/storesearch/', [
                    'term' => $game->title,
                    'l' => 'brazilian',
                    'cc' => 'BR',
                ]);

                if ($steamSearchRes->successful()) {
                    $steamItems = $steamSearchRes->json()['items'] ?? [];
                    if (! empty($steamItems[0]['id'])) {
                        $this->enrichFromSteamAppDetails($game, (string) $steamItems[0]['id']);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Falha na busca Steam por título para enriquecer {$game->title}: {$e->getMessage()}");
            }
        }

        return $game;
    }

    /**
     * Auxiliar para buscar e aplicar metadados do Steam App Details
     */
    protected function enrichFromSteamAppDetails(CapyGame $game, string $appId): void
    {
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
                        $game->summary = strip_tags($data['short_description']);
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

                    $plats = $game->platforms ?? [];
                    if (! empty($data['platforms']['windows']) && ! in_array('PC', $plats, true) && ! in_array('PC (Windows)', $plats, true)) {
                        $plats[] = 'PC';
                    }
                    if (! empty($plats)) {
                        $game->platforms = array_values(array_unique($plats));
                    }

                    $raw = $game->raw_data ?? [];

                    if (empty($raw['screenshots']) && ! empty($data['screenshots'])) {
                        $screenshots = [];
                        foreach ($data['screenshots'] as $s) {
                            $screenshots[] = [
                                'id' => $s['id'] ?? null,
                                'thumbnail' => $s['path_thumbnail'] ?? null,
                                'full' => $s['path_full'] ?? null,
                            ];
                        }
                        $raw['screenshots'] = $screenshots;
                    }

                    if (empty($raw['categories']) && ! empty($data['categories'])) {
                        $raw['categories'] = array_column($data['categories'], 'description');
                    }

                    if (empty($raw['achievements_count']) && ! empty($data['achievements']['total'])) {
                        $raw['achievements_count'] = $data['achievements']['total'];
                    }
                    if (empty($raw['release_date']) && ! empty($data['release_date']['date'])) {
                        $raw['release_date'] = $data['release_date']['date'];
                    }
                    if (empty($raw['metacritic']) && ! empty($data['metacritic']['score'])) {
                        $raw['metacritic'] = $data['metacritic']['score'];
                    }

                    $game->raw_data = $raw;
                    $game->save();
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Falha ao enriquecer jogo {$game->id} via Steam ({$appId}): {$e->getMessage()}");
        }
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

        // Se o usuário não tem vínculo direto por ID, procura por slug ou título idêntico
        if (! $myStatus && ($game->slug || $game->title)) {
            $myStatus = CapyUserGame::where('user_id', $currentUser->id)
                ->whereHas('game', function ($q) use ($game) {
                    $q->where('slug', $game->slug)
                        ->orWhere('title', $game->title);
                })
                ->first();
        }

        if ($myStatus && $myStatus->status === 'playing' && $myStatus->updated_at && $myStatus->updated_at->lt(now()->subDays(30))) {
            $myStatus->status = 'on_hold';
        }

        // Moradores da Capihouse que têm o jogo na biblioteca (por game_id ou pelo mesmo jogo/slug)
        $allUgQuery = CapyUserGame::with('user:id,name,username,avatar_url')
            ->where(function ($q) use ($game, $gameId) {
                $q->where('game_id', $gameId);
                if ($game->slug) {
                    $q->orWhereHas('game', fn ($sub) => $sub->where('slug', $game->slug)->orWhere('title', $game->title));
                }
            })
            ->get();

        $housemates = $allUgQuery
            ->unique('user_id')
            ->map(function ($ug) {
                $status = $ug->status;
                if ($status === 'playing' && $ug->updated_at && $ug->updated_at->lt(now()->subDays(30))) {
                    $status = 'on_hold';
                }

                return [
                    'user' => $ug->user,
                    'status' => $status,
                    'platform' => $ug->platform,
                    'rating' => $ug->rating,
                    'aspect_ratings' => $ug->aspect_ratings,
                    'hours_played' => $ug->hours_played,
                    'review' => $ug->review,
                    'completed_at' => $ug->completed_at,
                ];
            })
            ->values();

        // Média de notas da comunidade
        $ratingsQuery = CapyUserGame::where(function ($q) use ($game, $gameId) {
            $q->where('game_id', $gameId);
            if ($game->slug) {
                $q->orWhereHas('game', fn ($sub) => $sub->where('slug', $game->slug)->orWhere('title', $game->title));
            }
        })->whereNotNull('rating');

        $avgRating = $ratingsQuery->avg('rating');
        $totalRatings = $ratingsQuery->count();

        // Médias comunitárias por aspecto
        $aspectAverages = [];
        $ratedAspectsCount = [];
        foreach ($allUgQuery as $ug) {
            if (! empty($ug->aspect_ratings) && is_array($ug->aspect_ratings)) {
                foreach ($ug->aspect_ratings as $aspectKey => $score) {
                    if ($score !== null && is_numeric($score)) {
                        $aspectAverages[$aspectKey] = ($aspectAverages[$aspectKey] ?? 0) + (float) $score;
                        $ratedAspectsCount[$aspectKey] = ($ratedAspectsCount[$aspectKey] ?? 0) + 1;
                    }
                }
            }
        }
        foreach ($aspectAverages as $aspectKey => $sum) {
            $aspectAverages[$aspectKey] = round($sum / $ratedAspectsCount[$aspectKey], 1);
        }

        // Consolidar todas as plataformas onde o jogo está disponível (independente de onde o usuário joga)
        $allPlatforms = $game->platforms ?: [];

        // 1. Unificar com outras versões cadastradas com mesmo título ou slug
        if ($game->slug || $game->title) {
            $otherGames = CapyGame::where('id', '!=', $game->id)
                ->where(function ($q) use ($game) {
                    if ($game->slug) {
                        $q->where('slug', $game->slug);
                    }
                    if ($game->title) {
                        $q->orWhere('title', $game->title);
                    }
                })
                ->get();
            foreach ($otherGames as $og) {
                if (! empty($og->platforms) && is_array($og->platforms)) {
                    $allPlatforms = array_merge($allPlatforms, $og->platforms);
                }
            }
        }

        // 2. Unificar com plataformas registradas pelos moradores
        foreach ($allUgQuery as $ug) {
            if (! empty($ug->platform)) {
                $allPlatforms[] = $ug->platform;
            }
            if (! empty($ug->platform_hours) && is_array($ug->platform_hours)) {
                $allPlatforms = array_merge($allPlatforms, array_keys($ug->platform_hours));
            }
        }

        // 3. Catálogo de plataformas conhecidas para títulos populares
        $knownPlatforms = $this->getKnownPlatformsForGame($game->title);
        if (! empty($knownPlatforms)) {
            $allPlatforms = array_merge($allPlatforms, $knownPlatforms);
        }

        $cleanPlatforms = array_values(array_unique(array_filter(array_map('trim', $allPlatforms))));
        if (! empty($cleanPlatforms) && $cleanPlatforms !== ($game->platforms ?: [])) {
            $game->platforms = $cleanPlatforms;
            $game->save();
        }

        return [
            'game' => $game,
            'my_status' => $myStatus,
            'community_rating' => $avgRating ? round($avgRating, 1) : null,
            'community_aspect_ratings' => ! empty($aspectAverages) ? $aspectAverages : null,
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

    /**
     * Dicionário de plataformas conhecidas para títulos populares multiplataforma
     */
    public function getKnownPlatformsForGame(string $title): array
    {
        $normalized = mb_strtolower(trim($title));

        $dictionary = [
            'cyberpunk 2077' => ['PC', 'PlayStation 5', 'PlayStation 4', 'Xbox Series X|S', 'Xbox One'],
            'elden ring' => ['PC', 'PlayStation 5', 'PlayStation 4', 'Xbox Series X|S', 'Xbox One'],
            'the witcher 3' => ['PC', 'PlayStation 5', 'PlayStation 4', 'Xbox Series X|S', 'Xbox One', 'Nintendo Switch'],
            "baldur's gate 3" => ['PC', 'PlayStation 5', 'Xbox Series X|S'],
            'red dead redemption 2' => ['PC', 'PlayStation 4', 'Xbox One'],
            'grand theft auto v' => ['PC', 'PlayStation 5', 'PlayStation 4', 'Xbox Series X|S', 'Xbox One'],
            'gta v' => ['PC', 'PlayStation 5', 'PlayStation 4', 'Xbox Series X|S', 'Xbox One'],
            'god of war' => ['PC', 'PlayStation 5', 'PlayStation 4'],
            'the last of us' => ['PC', 'PlayStation 5', 'PlayStation 4'],
            'spider-man' => ['PC', 'PlayStation 5', 'PlayStation 4'],
            'black myth: wukong' => ['PC', 'PlayStation 5'],
            'resident evil 4' => ['PC', 'PlayStation 5', 'PlayStation 4', 'Xbox Series X|S'],
            'resident evil 2' => ['PC', 'PlayStation 5', 'PlayStation 4', 'Xbox Series X|S', 'Xbox One'],
            'resident evil' => ['PC', 'PlayStation 5', 'PlayStation 4', 'Xbox Series X|S', 'Xbox One'],
            'hades' => ['PC', 'PlayStation 5', 'PlayStation 4', 'Xbox Series X|S', 'Xbox One', 'Nintendo Switch'],
            'hollow knight' => ['PC', 'PlayStation 4', 'Xbox One', 'Nintendo Switch'],
            'celeste' => ['PC', 'PlayStation 4', 'Xbox One', 'Nintendo Switch'],
            'dead cells' => ['PC', 'PlayStation 4', 'Xbox One', 'Nintendo Switch'],
            'stardew valley' => ['PC', 'PlayStation 4', 'Xbox One', 'Nintendo Switch'],
            'sea of stars' => ['PC', 'PlayStation 5', 'PlayStation 4', 'Xbox Series X|S', 'Xbox One', 'Nintendo Switch'],
            'hi-fi rush' => ['PC', 'PlayStation 5', 'Xbox Series X|S'],
            'control' => ['PC', 'PlayStation 5', 'PlayStation 4', 'Xbox Series X|S', 'Xbox One'],
            'horizon zero dawn' => ['PC', 'PlayStation 5', 'PlayStation 4'],
            'horizon forbidden west' => ['PC', 'PlayStation 5', 'PlayStation 4'],
            'alan wake 2' => ['PC', 'PlayStation 5', 'Xbox Series X|S'],
            'silent hill 2' => ['PC', 'PlayStation 5'],
            'final fantasy vii remake' => ['PC', 'PlayStation 5', 'PlayStation 4'],
            'final fantasy xvi' => ['PC', 'PlayStation 5'],
            'death stranding' => ['PC', 'PlayStation 5', 'PlayStation 4', 'Xbox Series X|S'],
            'forza horizon 5' => ['PC', 'Xbox Series X|S', 'Xbox One'],
            'starfield' => ['PC', 'Xbox Series X|S'],
            'diablo iv' => ['PC', 'PlayStation 5', 'PlayStation 4', 'Xbox Series X|S', 'Xbox One'],
            'minecraft' => ['PC', 'PlayStation 4', 'Xbox One', 'Nintendo Switch'],
            'zelda' => ['Nintendo Switch'],
            'mario' => ['Nintendo Switch'],
            'metroid' => ['Nintendo Switch'],
            'pokemon' => ['Nintendo Switch'],
            'pokémon' => ['Nintendo Switch'],
            'smash bros' => ['Nintendo Switch'],
            'animal crossing' => ['Nintendo Switch'],
        ];

        foreach ($dictionary as $keyword => $platforms) {
            if (str_contains($normalized, $keyword)) {
                return $platforms;
            }
        }

        return [];
    }
}
