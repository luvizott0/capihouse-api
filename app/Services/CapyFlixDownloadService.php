<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CapyFlixDownloadService
{
    public function __construct(
        protected CapyFlixStorageService $storageService
    ) {}

    /**
     * Retorna a lista de tarefas de download.
     */
    public function getDownloads(): array
    {
        return Cache::get('capyflix:active_downloads', []);
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

        // 1. Validar se a quota de 300 GB suporta este download
        if (! $this->storageService->canFit($expectedBytes)) {
            throw new \RuntimeException('O limite de armazenamento de 300 GB do NAS seria ultrapassado com este download.');
        }

        // 2. Preparar diretório de destino
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
            'status' => 'queued', // queued, downloading, completed, failed
            'progress' => 0,
            'downloaded_bytes' => 0,
            'total_bytes' => $expectedBytes,
            'created_at' => now()->toIso8601String(),
            'error' => null,
        ];

        $downloads = $this->getDownloads();
        $downloads[$id] = $task;
        Cache::put('capyflix:active_downloads', $downloads, now()->addDays(2));

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
            // Remove o arquivo do disco APENAS se o download NÃO estiver concluído (ou seja, foi cancelado durante o download ou falhou)
            if (($task['status'] ?? '') !== 'completed' && file_exists($task['target_path'])) {
                @unlink($task['target_path']);
            }
            unset($downloads[$id]);
            Cache::put('capyflix:active_downloads', $downloads, now()->addDays(2));

            return true;
        }

        return false;
    }
}
