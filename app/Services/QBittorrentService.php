<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QBittorrentService
{
    protected string $host;

    protected int $port;

    protected string $username;

    protected string $password;

    protected bool $enabled;

    public function __construct()
    {
        $this->host = env('QBITTORRENT_HOST', '127.0.0.1');
        $this->port = (int) env('QBITTORRENT_PORT', 8085);
        $this->username = env('QBITTORRENT_USERNAME', 'admin');
        $this->password = env('QBITTORRENT_PASSWORD', 'adminadmin');
        $this->enabled = filter_var(env('QBITTORRENT_ENABLED', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Retorna a URL base da WebUI do qBittorrent.
     */
    public function getBaseUrl(): string
    {
        return "http://{$this->host}:{$this->port}";
    }

    /**
     * Verifica se o qBittorrent está habilitado e acessível.
     */
    public function isAvailable(): bool
    {
        if (! $this->enabled) {
            return false;
        }

        try {
            $response = Http::timeout(2)->get("{$this->getBaseUrl()}/api/v2/app/webapiVersion");

            return $response->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Retorna a versão da Web API do qBittorrent.
     */
    public function getVersion(): ?string
    {
        try {
            $response = Http::timeout(3)->get("{$this->getBaseUrl()}/api/v2/app/version");

            return $response->successful() ? trim($response->body()) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Autentica na WebUI do qBittorrent e retorna o cookie de sessão (SID).
     */
    protected function getAuthCookie(): ?string
    {
        $cached = Cache::get('capyflix:qbittorrent_sid');
        if (! empty($cached)) {
            return $cached;
        }

        try {
            $response = Http::asForm()->timeout(4)->post("{$this->getBaseUrl()}/api/v2/auth/login", [
                'username' => $this->username,
                'password' => $this->password,
            ]);

            if ($response->successful() && str_contains($response->body(), 'Ok.')) {
                $cookies = $response->cookies();
                $sidCookie = $cookies->getCookieByName('SID');
                $sid = $sidCookie ? $sidCookie->getValue() : null;

                if (! empty($sid)) {
                    Cache::put('capyflix:qbittorrent_sid', $sid, now()->addHours(12));

                    return $sid;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Falha ao autenticar no qBittorrent: '.$e->getMessage());
        }

        return null;
    }

    /**
     * Adiciona um torrent por Magnet Link ou URL de arquivo .torrent.
     */
    public function addTorrent(string $urls, string $savePath, string $category = 'capyflix', ?string $tags = null): array
    {
        $sid = $this->getAuthCookie();
        $client = Http::timeout(10);

        if ($sid) {
            $client = $client->withCookies(['SID' => $sid], parse_url($this->getBaseUrl(), PHP_URL_HOST));
        }

        $params = [
            'urls' => $urls,
            'savepath' => $savePath,
            'category' => $category,
            'autoTMM' => 'false',
        ];

        if ($tags) {
            $params['tags'] = $tags;
        }

        try {
            $response = $client->asForm()->post("{$this->getBaseUrl()}/api/v2/torrents/add", $params);

            $body = trim($response->body());
            $isSuccess = ($response->successful() && ($body === 'Ok.' || empty($body)))
                || $response->status() === 409
                || str_contains($body, 'Conflict');

            if ($isSuccess) {
                return [
                    'success' => true,
                    'message' => 'Torrent adicionado com sucesso ao qBittorrent.',
                ];
            }

            // Se o SID expirou, limpa cache e tenta uma vez mais
            if ($response->status() === 403) {
                Cache::forget('capyflix:qbittorrent_sid');
                $newSid = $this->getAuthCookie();
                if ($newSid) {
                    $retry = Http::timeout(10)
                        ->withCookies(['SID' => $newSid], parse_url($this->getBaseUrl(), PHP_URL_HOST))
                        ->asForm()
                        ->post("{$this->getBaseUrl()}/api/v2/torrents/add", $params);

                    $retryBody = trim($retry->body());
                    $retrySuccess = ($retry->successful() && ($retryBody === 'Ok.' || empty($retryBody)))
                        || $retry->status() === 409
                        || str_contains($retryBody, 'Conflict');

                    return [
                        'success' => $retrySuccess,
                        'message' => $retryBody,
                    ];
                }
            }

            return [
                'success' => false,
                'message' => $response->body() ?: 'Erro desconhecido ao adicionar torrent.',
            ];
        } catch (\Throwable $e) {
            Log::error('Erro ao adicionar torrent no qBittorrent: '.$e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Lista torrents ativos ou filtrados por categoria/tag.
     */
    public function getTorrents(?string $category = null, ?string $tag = null): array
    {
        $sid = $this->getAuthCookie();
        $client = Http::timeout(5);

        if ($sid) {
            $client = $client->withCookies(['SID' => $sid], parse_url($this->getBaseUrl(), PHP_URL_HOST));
        }

        $queryParams = [];
        if ($category) {
            $queryParams['category'] = $category;
        }
        if ($tag) {
            $queryParams['tag'] = $tag;
        }

        try {
            $response = $client->get("{$this->getBaseUrl()}/api/v2/torrents/info", $queryParams);

            if ($response->successful()) {
                $rawTorrents = $response->json() ?? [];
                $normalized = [];

                foreach ($rawTorrents as $t) {
                    $progressPercent = round(($t['progress'] ?? 0) * 100, 1);
                    $dlSpeed = (int) ($t['dlspeed'] ?? 0);
                    $isCompleted = ($t['progress'] ?? 0) >= 1.0 || in_array($t['state'] ?? '', ['uploading', 'pausedUP', 'completed']);

                    $normalized[] = [
                        'hash' => strtolower($t['hash'] ?? ''),
                        'name' => $t['name'] ?? 'Torrent',
                        'progress' => $progressPercent,
                        'state' => $t['state'] ?? 'unknown',
                        'is_completed' => $isCompleted,
                        'dlspeed_bytes' => $dlSpeed,
                        'dlspeed_formatted' => $this->formatBytes($dlSpeed).'/s',
                        'size_bytes' => (int) ($t['total_size'] ?? 0),
                        'size_formatted' => $this->formatBytes((int) ($t['total_size'] ?? 0)),
                        'downloaded_bytes' => (int) ($t['completed'] ?? 0),
                        'downloaded_formatted' => $this->formatBytes((int) ($t['completed'] ?? 0)),
                        'eta_seconds' => (int) ($t['eta'] ?? 0),
                        'num_seeds' => (int) ($t['num_seeds'] ?? 0),
                        'save_path' => $t['save_path'] ?? '',
                        'category' => $t['category'] ?? '',
                        'tags' => $t['tags'] ?? '',
                    ];
                }

                return $normalized;
            }
        } catch (\Throwable $e) {
            Log::warning('Erro ao consultar torrents no qBittorrent: '.$e->getMessage());
        }

        return [];
    }

    /**
     * Obtém informações de um torrent específico pelo Hash.
     */
    public function getTorrentByHash(string $hash): ?array
    {
        $hash = strtolower(trim($hash));
        $all = $this->getTorrents();

        foreach ($all as $t) {
            if ($t['hash'] === $hash) {
                return $t;
            }
        }

        return null;
    }

    /**
     * Pausa um torrent.
     */
    public function pauseTorrent(string $hash): bool
    {
        return $this->postAction('pause', ['hashes' => $hash]);
    }

    /**
     * Resume um torrent.
     */
    public function resumeTorrent(string $hash): bool
    {
        return $this->postAction('resume', ['hashes' => $hash]);
    }

    /**
     * Remove um torrent (opcionalmente excluindo arquivos do disco).
     */
    public function deleteTorrent(string $hash, bool $deleteFiles = false): bool
    {
        return $this->postAction('delete', [
            'hashes' => $hash,
            'deleteFiles' => $deleteFiles ? 'true' : 'false',
        ]);
    }

    /**
     * Retorna a lista de arquivos pertencentes a um torrent.
     */
    public function getTorrentFiles(string $hash): array
    {
        $hash = strtolower(trim($hash));
        $sid = $this->getAuthCookie();
        $client = Http::timeout(5);

        if ($sid) {
            $client = $client->withCookies(['SID' => $sid], parse_url($this->getBaseUrl(), PHP_URL_HOST));
        }

        try {
            $response = $client->get("{$this->getBaseUrl()}/api/v2/torrents/files", [
                'hash' => $hash,
            ]);

            if ($response->successful()) {
                return $response->json() ?? [];
            }
        } catch (\Throwable $e) {
            Log::warning("Erro ao consultar arquivos do torrent {$hash}: ".$e->getMessage());
        }

        return [];
    }

    /**
     * Define a prioridade de um ou mais arquivos dentro de um torrent.
     * Prioridade: 0 = Não baixar, 1 = Normal, 6 = Alta, 7 = Máxima
     */
    public function setFilePriority(string $hash, array $fileIndices, int $priority = 0): bool
    {
        if (empty($fileIndices)) {
            return true;
        }

        $hash = strtolower(trim($hash));
        $idParam = implode('|', array_unique($fileIndices));

        return $this->postAction('filePrio', [
            'hash' => $hash,
            'id' => $idParam,
            'priority' => (string) $priority,
        ]);
    }

    /**
     * Configura o download seletivo para baixar apenas os episódios solicitados de uma temporada.
     * Arquivos que não correspondem aos episódios solicitados recebem prioridade 0 (não baixar).
     */
    public function configureSelectiveEpisodes(string $hash, int $season, array $episodesToKeep): bool
    {
        $files = $this->getTorrentFiles($hash);
        if (empty($files)) {
            return false;
        }

        $episodesToKeep = array_map('intval', $episodesToKeep);
        $videoExtensions = ['mp4', 'mkv', 'avi', 'webm', 'm4v', 'mov', 'ts'];

        $toIgnore = [];
        $toDownload = [];
        $videoFiles = [];

        foreach ($files as $f) {
            $idx = $f['index'] ?? null;
            if ($idx === null) {
                continue;
            }

            $name = $f['name'] ?? '';
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (! in_array($ext, $videoExtensions, true)) {
                $toIgnore[] = $idx;

                continue;
            }

            $detectedEp = null;
            $detectedSeason = null;

            if (preg_match('/s0*(\d+)[\.\-_ ]*e0*(\d+)/i', $name, $m)) {
                $detectedSeason = (int) $m[1];
                $detectedEp = (int) $m[2];
            } elseif (preg_match('/0*(\d+)x0*(\d+)/i', $name, $m)) {
                $detectedSeason = (int) $m[1];
                $detectedEp = (int) $m[2];
            } elseif (preg_match('/(?:ep|episode|episodio|e)[\.\-_ ]*0*(\d+)/i', $name, $m)) {
                $detectedEp = (int) $m[1];
                $detectedSeason = $season;
            }

            if ($detectedEp !== null) {
                if ($detectedSeason === null || $detectedSeason === $season) {
                    if (in_array($detectedEp, $episodesToKeep, true)) {
                        $toDownload[] = $idx;
                    } else {
                        $toIgnore[] = $idx;
                    }
                } else {
                    $toIgnore[] = $idx;
                }
            } else {
                $videoFiles[] = [
                    'index' => $idx,
                    'name' => $name,
                ];
            }
        }

        if (! empty($videoFiles)) {
            if (count($videoFiles) === 1 && count($files) <= 3) {
                $singleIdx = $videoFiles[0]['index'];
                if (! empty($episodesToKeep)) {
                    $toDownload[] = $singleIdx;
                } else {
                    $toIgnore[] = $singleIdx;
                }
            } else {
                usort($videoFiles, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
                foreach ($videoFiles as $order => $vf) {
                    $seqEp = $order + 1;
                    if (in_array($seqEp, $episodesToKeep, true)) {
                        $toDownload[] = $vf['index'];
                    } else {
                        $toIgnore[] = $vf['index'];
                    }
                }
            }
        }

        if (! empty($toIgnore)) {
            $this->setFilePriority($hash, $toIgnore, 0);
        }

        if (! empty($toDownload)) {
            $this->setFilePriority($hash, $toDownload, 1);
        }

        return true;
    }

    /**
     * Localiza o arquivo específico de um episódio dentro do torrent e retorna seus dados.
     */
    public function getEpisodeFileStatus(string $hash, int $season, int $episode): ?array
    {
        $files = $this->getTorrentFiles($hash);
        if (empty($files)) {
            return null;
        }

        $videoExtensions = ['mp4', 'mkv', 'avi', 'webm', 'm4v', 'mov', 'ts'];
        $videoFiles = [];

        foreach ($files as $f) {
            $name = $f['name'] ?? '';
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (! in_array($ext, $videoExtensions, true)) {
                continue;
            }

            $detectedEp = null;
            $detectedSeason = null;

            if (preg_match('/s0*(\d+)[\.\-_ ]*e0*(\d+)/i', $name, $m)) {
                $detectedSeason = (int) $m[1];
                $detectedEp = (int) $m[2];
            } elseif (preg_match('/0*(\d+)x0*(\d+)/i', $name, $m)) {
                $detectedSeason = (int) $m[1];
                $detectedEp = (int) $m[2];
            } elseif (preg_match('/(?:ep|episode|episodio|e)[\.\-_ ]*0*(\d+)/i', $name, $m)) {
                $detectedEp = (int) $m[1];
                $detectedSeason = $season;
            }

            if ($detectedEp !== null) {
                if (($detectedSeason === null || $detectedSeason === $season) && $detectedEp === $episode) {
                    return $f;
                }
            } else {
                $videoFiles[] = $f;
            }
        }

        if (! empty($videoFiles)) {
            if (count($videoFiles) === 1 && $episode === 1) {
                return $videoFiles[0];
            }
            usort($videoFiles, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
            $targetIndex = $episode - 1;
            if (isset($videoFiles[$targetIndex])) {
                return $videoFiles[$targetIndex];
            }
        }

        return null;
    }

    /**
     * Executa uma ação de postagem simples na Web API.
     */
    protected function postAction(string $endpoint, array $params): bool
    {
        $sid = $this->getAuthCookie();
        $client = Http::timeout(5);

        if ($sid) {
            $client = $client->withCookies(['SID' => $sid], parse_url($this->getBaseUrl(), PHP_URL_HOST));
        }

        try {
            $res = $client->asForm()->post("{$this->getBaseUrl()}/api/v2/torrents/{$endpoint}", $params);

            return $res->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Formata bytes para leitura amigável (MB, GB).
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 2).' '.$units[$i];
    }
}
