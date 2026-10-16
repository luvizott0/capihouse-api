<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CapyFlixDownloadService;
use App\Services\CapyFlixStorageService;
use App\Services\CapyFlixStreamResolverService;
use App\Services\QBittorrentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CapyFlixController extends Controller
{
    public function __construct(
        protected CapyFlixStorageService $storageService,
        protected CapyFlixDownloadService $downloadService,
        protected CapyFlixStreamResolverService $streamResolverService,
        protected QBittorrentService $qbittorrentService
    ) {}

    /**
     * Retorna o status de quota de 300 GB e os arquivos do NAS.
     */
    public function storage(): JsonResponse
    {
        $status = $this->storageService->getStorageStatus();

        return response()->json($status);
    }

    /**
     * Exclui um arquivo do NAS para liberar espaço na quota de 300 GB.
     */
    public function deleteFile(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $deleted = $this->storageService->deleteFile($request->input('path'));

        if (! $deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Arquivo não encontrado ou erro ao excluir.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Arquivo removido com sucesso.',
            'storage' => $this->storageService->getStorageStatus(),
        ]);
    }

    /**
     * Retorna a lista de downloads ativos / recentes do NAS.
     */
    public function downloads(): JsonResponse
    {
        return response()->json([
            'downloads' => array_values($this->downloadService->getDownloads()),
        ]);
    }

    /**
     * Inicia uma nova tarefa de download direto para o NAS respeitando a quota de 300 GB.
     */
    public function startDownload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string',
            'media_type' => 'required|in:movie,series,anime,cartoon',
            'season' => 'nullable|integer|min:1',
            'episode' => 'nullable|integer|min:1',
            'url' => 'nullable|string',
            'expected_bytes' => 'nullable|integer|min:1',
        ]);

        try {
            $task = $this->downloadService->startDownload($validated);

            return response()->json([
                'success' => true,
                'task' => $task,
                'message' => 'Download iniciado para o NAS.',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Cancela uma tarefa de download.
     */
    public function cancelDownload(string $id): JsonResponse
    {
        $cancelled = $this->downloadService->cancelDownload($id);

        return response()->json([
            'success' => $cancelled,
            'message' => $cancelled ? 'Download cancelado.' : 'Tarefa não encontrada.',
        ]);
    }

    /**
     * Consulta o status de download de um filme ou temporada (episódios baixados no disco e na fila).
     */
    public function mediaStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string',
            'media_type' => 'required|in:movie,series,anime,cartoon',
            'season' => 'nullable|integer|min:1',
        ]);

        $title = $validated['title'];
        $mediaType = $validated['media_type'];
        $season = (int) ($validated['season'] ?? 1);

        $downloadedEpisodes = $this->storageService->getSeasonDownloadedEpisodes($mediaType, $title, $season);
        $isDownloaded = $mediaType === 'movie'
            ? $this->storageService->isMediaDownloaded($mediaType, $title)
            : count($downloadedEpisodes) > 0;

        $active = [];
        foreach ($this->downloadService->getDownloads() as $dl) {
            if (
                ($dl['title'] ?? '') === $title &&
                ($dl['media_type'] ?? '') === $mediaType &&
                (int) ($dl['season'] ?? 1) === $season &&
                in_array($dl['status'] ?? '', ['queued', 'downloading', 'pending_source'])
            ) {
                $active[] = [
                    'id' => $dl['id'],
                    'season' => (int) ($dl['season'] ?? 1),
                    'episode' => (int) ($dl['episode'] ?? 1),
                    'status' => $dl['status'],
                    'progress' => (int) ($dl['progress'] ?? 0),
                ];
            }
        }

        return response()->json([
            'title' => $title,
            'media_type' => $mediaType,
            'season' => $season,
            'is_downloaded' => $isDownloaded,
            'downloaded_episodes' => $downloadedEpisodes,
            'active_downloads' => $active,
            'debrid_configured' => $this->streamResolverService->getDebridConfig()['has_token'],
            'qbittorrent_available' => $this->qbittorrentService->isAvailable(),
        ]);
    }

    /**
     * Retorna o status de conexão com o cliente qBittorrent local.
     */
    public function qbittorrentStatus(): JsonResponse
    {
        $available = $this->qbittorrentService->isAvailable();

        return response()->json([
            'enabled' => filter_var(env('QBITTORRENT_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'available' => $available,
            'version' => $available ? $this->qbittorrentService->getVersion() : null,
            'host' => env('QBITTORRENT_HOST', '127.0.0.1'),
            'port' => (int) env('QBITTORRENT_PORT', 8085),
            'base_url' => $this->qbittorrentService->getBaseUrl(),
        ]);
    }

    /**
     * Inicia download em lote (múltiplos episódios ou temporada inteira) bloqueando duplicados.
     */
    public function batchDownload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string',
            'media_type' => 'required|in:movie,series,anime,cartoon',
            'season' => 'nullable|integer|min:1',
            'episodes' => 'nullable|array',
            'episodes.*' => 'integer|min:1',
            'tmdb_id' => 'nullable|integer',
            'imdb_id' => 'nullable|string',
            'url' => 'nullable|string',
        ]);

        $result = $this->downloadService->batchDownload($validated);

        return response()->json([
            'success' => $result['success'] || $result['skipped_count'] > 0,
            'result' => $result,
            'message' => count($result['queued']) > 0
                ? count($result['queued']).' download(s) iniciado(s) para o NAS.'
                : 'Nenhum novo download necessário (itens já baixados ou na fila).',
        ]);
    }

    /**
     * Resolve streams de alta velocidade (Torbox / Debrid / Streams Diretos) para o player nativo.
     */
    public function resolveStreams(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tmdb_id' => 'nullable|integer',
            'imdb_id' => 'nullable|string',
            'title' => 'nullable|string',
            'media_type' => 'required|in:movie,series,anime,cartoon',
            'season' => 'nullable|integer|min:1',
            'episode' => 'nullable|integer|min:1',
            'token' => 'nullable|string',
            'provider' => 'nullable|string',
        ]);

        $streams = $this->streamResolverService->resolveStreams($validated);

        return response()->json([
            'streams' => $streams,
            'debrid_configured' => $this->streamResolverService->getDebridConfig()['has_token'],
        ]);
    }

    /**
     * Retorna a configuração de Debrid (sem vazar chaves sensíveis).
     */
    public function getDebridConfig(): JsonResponse
    {
        return response()->json($this->streamResolverService->getDebridConfig());
    }

    /**
     * Salva o token do Torbox ou Real-Debrid para desbloquear streams diretos sem anúncios.
     */
    public function saveDebridConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => 'required|string|in:torbox,realdebrid',
            'token' => 'required|string|min:4',
        ]);

        $config = $this->streamResolverService->saveDebridConfig($validated['provider'], $validated['token']);

        return response()->json([
            'success' => true,
            'config' => $config,
            'message' => 'Configuração de Debrid salva com sucesso.',
        ]);
    }
}
