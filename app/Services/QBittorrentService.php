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
