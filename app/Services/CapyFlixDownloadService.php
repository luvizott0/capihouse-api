<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CapyFlixDownloadService
{
    public function __construct(
        protected CapyFlixStorageService $storageService,
        protected CapyFlixStreamResolverService $streamResolverService,
        protected QBittorrentService $qbittorrentService
    ) {}

    /**
     * Retorna a lista de tarefas de download sincronizando tarefas ativas do qBittorrent.
     */
    public function getDownloads(): array
    {
        $downloads = Cache::get('capyflix:active_downloads', []);

        // Sincroniza tarefas ativas gerenciadas pelo qBittorrent
        $hasQbit = false;
        foreach ($downloads as $d) {
            if (($d['engine'] ?? '') === 'qbittorrent' && in_array($d['status'] ?? '', ['downloading', 'queued'])) {
                $hasQbit = true;
                break;
            }
        }

        if ($hasQbit && $this->qbittorrentService->isAvailable()) {
            $torrents = $this->qbittorrentService->getTorrents('capyflix');
            $qbitMap = [];
            foreach ($torrents as $t) {
                $qbitMap[$t['hash']] = $t;
            }

            $updated = false;
            foreach ($downloads as $id => &$task) {
                if (($task['engine'] ?? '') === 'qbittorrent' && ! empty($task['torrent_hash'])) {
                    $h = strtolower($task['torrent_hash']);
                    if (isset($qbitMap[$h])) {
                        $qt = $qbitMap[$h];
                        $task['progress'] = $qt['progress'];
                        $task['downloaded_bytes'] = $qt['downloaded_bytes'];
                        if ($qt['size_bytes'] > 0) {
                            $task['total_bytes'] = $qt['size_bytes'];
                        }
                        $task['dlspeed_formatted'] = $qt['dlspeed_formatted'];
                        $task['num_seeds'] = $qt['num_seeds'];

                        if ($qt['is_completed']) {
                            $task['status'] = 'completed';
                            $task['progress'] = 100;
                        }
                        $updated = true;
                    }
                }
            }

            if ($updated) {
                Cache::put('capyflix:active_downloads', $downloads, now()->addDays(2));
            }
        }

        // Auto-heal: se houver tarefas em 'pending_source' e o qBittorrent estiver disponível, tenta resolver a fonte
        if ($this->qbittorrentService->isAvailable()) {
            $healUpdated = false;
            foreach ($downloads as $id => &$task) {
                if (($task['status'] ?? '') === 'pending_source' && empty($task['magnet']) && empty($task['url'])) {
                    try {
                        $streams = $this->streamResolverService->resolveStreams([
                            'title' => $task['title'] ?? '',
                            'media_type' => $task['media_type'] ?? 'movie',
                            'season' => (int) ($task['season'] ?? 1),
                            'episode' => (int) ($task['episode'] ?? 1),
                        ]);

                        if (! empty($streams)) {
                            $top = $streams[0];
                            $mag = $top['magnet'] ?? null;
                            $hash = $top['infoHash'] ?? null;

                            if ($mag) {
                                $cleanTitle = Str::slug($task['title'] ?? 'media');
                                $season = (int) ($task['season'] ?? 1);
                                $ep = (int) ($task['episode'] ?? 1);
                                $targetDir = dirname($task['target_path']);

                                $addRes = $this->qbittorrentService->addTorrent($mag, $targetDir, 'capyflix', "{$cleanTitle}-s{$season}e{$ep}");
                                if ($addRes['success']) {
                                    $task['engine'] = 'qbittorrent';
                                    $task['status'] = 'downloading';
                                    $task['magnet'] = $mag;
                                    $task['torrent_hash'] = $hash ?: $this->extractHashFromMagnet($mag);
                                    $task['error'] = null;
                                    $healUpdated = true;
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        // mantém pending_source
                    }
                }
            }

            if ($healUpdated) {
                Cache::put('capyflix:active_downloads', $downloads, now()->addDays(2));
            }
        }

        return $downloads;
    }

    /**
     * Registra e inicia uma nova tarefa de download para o NAS.
     */
    public function startDownload(array $data): array
    {
        $mediaRoot = $this->storageService->getMediaRoot();
        $title = $data['title'] ?? 'Sem Título';
        $mediaType = $data['media_type'] ?? 'movie';
        $season = (int) ($data['season'] ?? 1);
        $episode = (int) ($data['episode'] ?? 1);
        $downloadUrl = $data['url'] ?? '';
        $expectedBytes = (int) ($data['expected_bytes'] ?? (1.5 * 1024 * 1024 * 1024)); // ~1.5 GB default

        // 1. Validar se o arquivo já foi baixado no NAS
        if ($this->storageService->findLocalMediaUrl($mediaType, $title, $season, $episode) !== null) {
            $label = $mediaType === 'movie' ? 'Este filme' : "O episódio {$season}x".str_pad((string) $episode, 2, '0', STR_PAD_LEFT);
            throw new \RuntimeException("{$label} já está salvo no seu NAS.");
        }

        // 2. Validar se já existe na fila ou baixando
        $downloads = $this->getDownloads();
        foreach ($downloads as $dlKey => $dl) {
            if (
                ($dl['title'] ?? '') === $title &&
                ($dl['media_type'] ?? '') === $mediaType &&
                (int) ($dl['season'] ?? 1) === $season &&
                (int) ($dl['episode'] ?? 1) === $episode
            ) {
                if (in_array($dl['status'] ?? '', ['queued', 'downloading'])) {
                    $label = $mediaType === 'movie' ? 'Este filme' : "O episódio {$season}x".str_pad((string) $episode, 2, '0', STR_PAD_LEFT);
                    throw new \RuntimeException("{$label} já está na fila de downloads ou sendo baixado.");
                }
                // Se estava como pending_source ou failed, substitui o anterior
                unset($downloads[$dlKey]);
            }
        }

        // 3. Validar se a quota de 300 GB suporta este download
        if (! $this->storageService->canFit($expectedBytes)) {
            throw new \RuntimeException('O limite de armazenamento de 300 GB do NAS seria ultrapassado com este download.');
        }

        // 4. Preparar diretório de destino
        $cleanTitle = preg_replace('/[^\w\s\-\.\(\)]/u', '', $title);
        $cleanTitle = trim(preg_replace('/\s+/', ' ', $cleanTitle));

        if ($mediaType === 'movie') {
            $targetDir = $mediaRoot.DIRECTORY_SEPARATOR.'filmes';
            $fileName = "{$cleanTitle}.mp4";
        } else {
            $targetDir = $mediaRoot.DIRECTORY_SEPARATOR.'séries'.DIRECTORY_SEPARATOR.$cleanTitle.DIRECTORY_SEPARATOR."Temporada {$season}";
            $epFormatted = str_pad($episode, 2, '0', STR_PAD_LEFT);
            $fileName = "{$season}x{$epFormatted} - {$cleanTitle}.mp4";
        }

        if (! is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }

        $targetPath = $targetDir.DIRECTORY_SEPARATOR.$fileName;
        $id = Str::uuid()->toString();

        $task = [
            'id' => $id,
            'title' => $title,
            'media_type' => $mediaType,
            'season' => $season,
            'episode' => $episode,
            'file_name' => $fileName,
            'target_path' => $targetPath,
            'url' => $downloadUrl,
            'engine' => 'http',
            'torrent_hash' => null,
            'magnet' => null,
            'status' => 'queued', // queued, downloading, completed, failed, pending_source
            'progress' => 0,
            'downloaded_bytes' => 0,
            'total_bytes' => $expectedBytes,
            'created_at' => now()->toIso8601String(),
            'error' => null,
        ];

        $downloads = $this->getDownloads();
        $downloads[$id] = $task;
        Cache::put('capyflix:active_downloads', $downloads, now()->addDays(2));

        // Se for um link Magnet ou se tiver info_hash -> envia para o qBittorrent Local
        $magnet = $data['magnet'] ?? (str_starts_with($downloadUrl, 'magnet:?') ? $downloadUrl : null);
        $infoHash = $data['info_hash'] ?? null;
        if (! $magnet && $infoHash) {
            $cleanTitleEncoded = rawurlencode($cleanTitle);
            $magnet = "magnet:?xt=urn:btih:{$infoHash}&dn={$cleanTitleEncoded}&tr=udp%3A%2F%2Ftracker.opentrackr.org%3A1337%2Fannounce&tr=udp%3A%2F%2Fopen.stealth.si%3A80%2Fannounce&tr=udp%3A%2F%2Ftracker.torrent.eu.org%3A451%2Fannounce";
        }

        if ($magnet) {
            if ($this->qbittorrentService->isAvailable()) {
                $qbitRes = $this->qbittorrentService->addTorrent($magnet, $targetDir, 'capyflix', "{$cleanTitle}-s{$season}e{$episode}");
                if ($qbitRes['success']) {
                    $downloads[$id]['engine'] = 'qbittorrent';
                    $downloads[$id]['status'] = 'downloading';
                    $downloads[$id]['torrent_hash'] = $infoHash ?: $this->extractHashFromMagnet($magnet);
                    $downloads[$id]['magnet'] = $magnet;
                    Cache::put('capyflix:active_downloads', $downloads, now()->addDays(2));

                    return $downloads[$id];
                }
            } else {
                $downloads[$id]['engine'] = 'qbittorrent';
                $downloads[$id]['status'] = 'pending_source';
                $downloads[$id]['error'] = 'qBittorrent não detectado na porta 8085. Inicie o container qBittorrent para baixar via P2P.';
                Cache::put('capyflix:active_downloads', $downloads, now()->addDays(2));

                return $downloads[$id];
            }
        }

        // Se a URL de download estiver vazia, fica marcado como pendente de fonte
        if (empty($downloadUrl)) {
            $downloads[$id]['status'] = 'pending_source';
            Cache::put('capyflix:active_downloads', $downloads, now()->addDays(2));

            return $downloads[$id];
        }

        // Executar o download de forma 100% assíncrona em background para não travar o servidor nem estourar os 30s
        $this->dispatchBackgroundProcess($id);

        return $this->getDownloads()[$id] ?? $task;
    }

    /**
     * Despacha o processamento do download em um processo separado em segundo plano.
     */
    public function dispatchBackgroundProcess(string $id): void
    {
        $php = PHP_BINARY ?: 'php';
        $artisan = base_path('artisan');

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'start /B "" "'.str_replace('/', '\\', $php).'" "'.str_replace('/', '\\', $artisan).'" capyflix:process-download '.escapeshellarg($id);
            @pclose(@popen($cmd, 'r'));
        } else {
            $cmd = escapeshellarg($php).' '.escapeshellarg($artisan).' capyflix:process-download '.escapeshellarg($id).' > /dev/null 2>&1 &';
            @exec($cmd);
        }
    }

    /**
     * Processa a transferência de bytes via stream HTTP em processo dedicado.
     */
    public function processDownload(string $id): void
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
        @ignore_user_abort(true);

        $downloads = $this->getDownloads();
        if (! isset($downloads[$id])) {
            return;
        }

        $task = $downloads[$id];
        $task['status'] = 'downloading';
        $downloads[$id] = $task;
        Cache::put('capyflix:active_downloads', $downloads, now()->addDays(2));

        try {
            $targetPath = $task['target_path'];
            $fp = fopen($targetPath, 'w+');

            if (! $fp) {
                throw new \RuntimeException("Não foi possível criar o arquivo de destino no NAS: {$targetPath}");
            }

            $ch = curl_init($task['url']);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 7200); // 2 horas máx
            curl_setopt($ch, CURLOPT_BUFFERSIZE, 1024 * 1024); // 1 MB buffer

            // Callback de progresso e cancelamento
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            $lastProgressSave = 0;
            $lastCancelCheck = 0;
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($ch, $dlTotal, $dlNow) use ($id, &$lastProgressSave, &$lastCancelCheck) {
                $now = time();

                // Verifica cancelamento no cache no máximo a cada 2 segundos
                if ($now - $lastCancelCheck >= 2) {
                    $lastCancelCheck = $now;
                    try {
                        $items = Cache::get('capyflix:active_downloads');
                        if (is_array($items) && ! isset($items[$id])) {
                            return 1; // Tarefa foi cancelada pelo usuário
                        }
                    } catch (\Throwable $e) {
                        // Não abortar em caso de lock transitório
                    }
                }

                if ($dlTotal > 0) {
                    $percent = round(($dlNow / $dlTotal) * 100);
                    // Atualiza cache a cada 3% ou a cada 2 segundos
                    if ($now - $lastProgressSave >= 2 || $percent >= 100) {
                        $lastProgressSave = $now;
                        try {
                            $items = Cache::get('capyflix:active_downloads', []);
                            if (isset($items[$id])) {
                                $items[$id]['progress'] = $percent;
                                $items[$id]['downloaded_bytes'] = (int) $dlNow;
                                $items[$id]['total_bytes'] = (int) $dlTotal;
                                Cache::put('capyflix:active_downloads', $items, now()->addDays(2));
                            }
                        } catch (\Throwable $e) {
                            // Ignora lock transitório
                        }
                    }
                }

                return 0;
            });

            $success = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            fclose($fp);

            $downloads = $this->getDownloads();
            if ($success && $httpCode >= 200 && $httpCode < 300) {
                $downloads[$id]['status'] = 'completed';
                $downloads[$id]['progress'] = 100;
            } else {
                $downloads[$id]['status'] = 'failed';
                $downloads[$id]['error'] = $curlError ?: "HTTP Status {$httpCode}";
                // Limpa apenas arquivo corrompido ou parcial
                if (file_exists($targetPath)) {
                    @unlink($targetPath);
                }
            }
            Cache::put('capyflix:active_downloads', $downloads, now()->addDays(2));
        } catch (\Throwable $e) {
            Log::error('Erro durante download no NAS: '.$e->getMessage());
            $downloads = $this->getDownloads();
            if (isset($downloads[$id])) {
                $downloads[$id]['status'] = 'failed';
                $downloads[$id]['error'] = $e->getMessage();
                Cache::put('capyflix:active_downloads', $downloads, now()->addDays(2));
            }
        }
    }

    /**
     * Cancela ou remove uma tarefa de download da lista.
     */
    public function cancelDownload(string $id): bool
    {
        $downloads = $this->getDownloads();
        if (isset($downloads[$id])) {
            $task = $downloads[$id];
            // Se for tarefa gerenciada pelo qBittorrent, remove do cliente torrent
            if (($task['engine'] ?? '') === 'qbittorrent' && ! empty($task['torrent_hash'])) {
                $this->qbittorrentService->deleteTorrent($task['torrent_hash'], ($task['status'] ?? '') !== 'completed');
            } elseif (($task['status'] ?? '') !== 'completed' && file_exists($task['target_path'])) {
                @unlink($task['target_path']);
            }
            unset($downloads[$id]);
            Cache::put('capyflix:active_downloads', $downloads, now()->addDays(2));

            return true;
        }

        return false;
    }

    /**
     * Inicia o download de múltiplos episódios ou da temporada inteira para o NAS,
     * ignorando duplicados já presentes no disco ou na fila de downloads.
     */
    public function batchDownload(array $data): array
    {
        $title = $data['title'] ?? 'Sem Título';
        $mediaType = $data['media_type'] ?? 'series';
        $season = (int) ($data['season'] ?? 1);
        $episodes = $data['episodes'] ?? [];
        $tmdbId = $data['tmdb_id'] ?? null;
        $imdbId = $data['imdb_id'] ?? null;
        $providedUrl = $data['url'] ?? null;

        if ($mediaType === 'movie') {
            $episodes = [1];
        }

        if (empty($episodes)) {
            $episodes = [1];
        }

        $queued = [];
        $skipped = [];

        foreach ($episodes as $ep) {
            $ep = (int) $ep;
            $epLabel = $mediaType === 'movie' ? $title : "{$season}x".str_pad((string) $ep, 2, '0', STR_PAD_LEFT);

            // 1. Verificar se já existe no disco
            if ($this->storageService->findLocalMediaUrl($mediaType, $title, $season, $ep) !== null) {
                $skipped[] = [
                    'season' => $season,
                    'episode' => $ep,
                    'reason' => 'already_downloaded',
                    'message' => "{$epLabel} já está salvo no seu NAS.",
                ];

                continue;
            }

            // 2. Verificar se já está na fila
            $active = false;
            foreach ($this->getDownloads() as $dl) {
                if (
                    ($dl['title'] ?? '') === $title &&
                    ($dl['media_type'] ?? '') === $mediaType &&
                    (int) ($dl['season'] ?? 1) === $season &&
                    (int) ($dl['episode'] ?? 1) === $ep &&
                    in_array($dl['status'] ?? '', ['queued', 'downloading'])
                ) {
                    $active = true;
                    break;
                }
            }

            if ($active) {
                $skipped[] = [
                    'season' => $season,
                    'episode' => $ep,
                    'reason' => 'already_in_queue',
                    'message' => "{$epLabel} já está na fila ou sendo baixado.",
                ];

                continue;
            }

            // 3. Resolver URL ou Magnet de acordo com a preferência de áudio/legenda
            $url = $providedUrl;
            $magnet = $data['magnet'] ?? null;
            $infoHash = $data['info_hash'] ?? null;

            if (empty($url) && empty($magnet) && ($tmdbId || $imdbId || $title)) {
                try {
                    $streams = $this->streamResolverService->resolveStreams([
                        'tmdb_id' => $tmdbId,
                        'imdb_id' => $imdbId,
                        'title' => $title,
                        'media_type' => $mediaType,
                        'season' => $season,
                        'episode' => $ep,
                    ]);

                    // Primeiro verifica se há stream HTTP direto (Debrid ou CDN)
                    foreach ($streams as $s) {
                        if (! empty($s['url']) && ($s['canDownloadToNas'] ?? false)) {
                            $url = $s['url'];
                            break;
                        }
                    }

                    // Se não houver link direto HTTP, seleciona o torrent #1 (já ordenado por DUB para animações e ORIGINAL+LEG para filmes/séries)
                    if (empty($url) && ! empty($streams)) {
                        $topTorrent = $streams[0];
                        $magnet = $topTorrent['magnet'] ?? null;
                        $infoHash = $topTorrent['infoHash'] ?? null;
                    }
                } catch (\Throwable $e) {
                    // Fallback para url vazia (ficará pending_source)
                }
            }

            // 4. Iniciar tarefa de download
            try {
                $task = $this->startDownload([
                    'title' => $title,
                    'media_type' => $mediaType,
                    'season' => $season,
                    'episode' => $ep,
                    'url' => $url ?: '',
                    'magnet' => $magnet,
                    'info_hash' => $infoHash,
                ]);
                $queued[] = $task;
            } catch (\Throwable $e) {
                $skipped[] = [
                    'season' => $season,
                    'episode' => $ep,
                    'reason' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => count($queued) > 0,
            'queued_count' => count($queued),
            'skipped_count' => count($skipped),
            'queued' => $queued,
            'skipped' => $skipped,
        ];
    }

    /**
     * Extrai o hash BTIH de um link magnet.
     */
    protected function extractHashFromMagnet(string $magnet): ?string
    {
        if (preg_match('/xt=urn:btih:([a-zA-Z0-9]+)/i', $magnet, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }
}
