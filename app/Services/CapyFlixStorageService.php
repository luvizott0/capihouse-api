<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class CapyFlixStorageService
{
    public const MAX_STORAGE_BYTES = 322122547200; // 300 GB (300 * 1024 * 1024 * 1024)

    public const WARN_STORAGE_BYTES = 306016419840; // 285 GB (95%)

    /**
     * Obtém o diretório raiz de mídia do NAS.
     */
    public function getMediaRoot(): string
    {
        $custom = env('CAPYFLIX_MEDIA_ROOT');
        if (! empty($custom) && is_dir($custom)) {
            return rtrim($custom, '/\\');
        }

        if (PHP_OS_FAMILY === 'Windows' && is_dir('X:\\')) {
            return 'X:';
        }

        if (is_dir('/mnt/capyflix')) {
            return '/mnt/capyflix';
        }

        // Fallback local se X:\ ou /mnt/capyflix não estiver montado
        $fallback = storage_path('app/capyflix-media');
        if (! is_dir($fallback)) {
            @mkdir($fallback, 0777, true);
        }

        return $fallback;
    }

    /**
     * Retorna o status de armazenamento e quota de 300 GB do NAS.
     */
    public function getStorageStatus(): array
    {
        $mediaRoot = $this->getMediaRoot();
        $scan = $this->scanDirectory($mediaRoot);

        $totalBytes = $scan['totalBytes'];
        $percentUsed = self::MAX_STORAGE_BYTES > 0
            ? round(($totalBytes / self::MAX_STORAGE_BYTES) * 100, 1)
            : 0;

        return [
            'media_root' => $mediaRoot,
            'total_bytes' => $totalBytes,
            'total_formatted' => $this->formatBytes($totalBytes),
            'max_bytes' => self::MAX_STORAGE_BYTES,
            'max_formatted' => $this->formatBytes(self::MAX_STORAGE_BYTES),
            'free_bytes' => max(0, self::MAX_STORAGE_BYTES - $totalBytes),
            'free_formatted' => $this->formatBytes(max(0, self::MAX_STORAGE_BYTES - $totalBytes)),
            'percent_used' => min(100, $percentUsed),
            'is_near_limit' => $totalBytes >= self::WARN_STORAGE_BYTES,
            'is_exceeded' => $totalBytes >= self::MAX_STORAGE_BYTES,
            'file_count' => count($scan['files']),
            'files' => $scan['files'],
        ];
    }

    /**
     * Verifica se um novo arquivo de determinado tamanho cabe na quota de 300 GB.
     */
    public function canFit(int $additionalBytes): bool
    {
        $status = $this->getStorageStatus();

        return ($status['total_bytes'] + $additionalBytes) <= self::MAX_STORAGE_BYTES;
    }

    /**
     * Exclui um arquivo do NAS para liberar espaço.
     */
    public function deleteFile(string $relativePath): bool
    {
        $mediaRoot = $this->getMediaRoot();
        $safeRelative = str_replace(['..', '\\'], ['', '/'], ltrim($relativePath, '/\\'));
        $fullPath = $mediaRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $safeRelative);

        if (file_exists($fullPath) && is_file($fullPath)) {
            return @unlink($fullPath);
        }

        return false;
    }

    /**
     * Localiza a URL de streaming local (/local-media/...) se o arquivo já existir no NAS.
     */
    public function findLocalMediaUrl(string $mediaType, string $title, int $season = 1, int $episode = 1): ?string
    {
        $mediaRoot = $this->getMediaRoot();
        $cleanTitle = preg_replace('/[^\w\s\-\.\(\)]/u', '', $title);
        $cleanTitle = trim(preg_replace('/\s+/', ' ', $cleanTitle));

        if ($mediaType === 'movie') {
            $targetDir = $mediaRoot.DIRECTORY_SEPARATOR.'filmes';
            $fileName = "{$cleanTitle}.mp4";
            $fullPath = $targetDir.DIRECTORY_SEPARATOR.$fileName;
            if (file_exists($fullPath)) {
                return '/local-media/filmes/'.rawurlencode($fileName);
            }
        } else {
            $epFormatted = str_pad($episode, 2, '0', STR_PAD_LEFT);
            $targetDir = $mediaRoot.DIRECTORY_SEPARATOR.'séries'.DIRECTORY_SEPARATOR.$cleanTitle.DIRECTORY_SEPARATOR."Temporada {$season}";
            $fileName = "{$season}x{$epFormatted} - {$cleanTitle}.mp4";
            $fullPath = $targetDir.DIRECTORY_SEPARATOR.$fileName;
            if (file_exists($fullPath)) {
                return '/local-media/s%C3%A9ries/'.rawurlencode($cleanTitle).'/Temporada%20'.$season.'/'.rawurlencode($fileName);
            }

            // Fallback: busca por prefixo do episódio na pasta da temporada
            if (is_dir($targetDir)) {
                $files = @scandir($targetDir) ?: [];
                foreach ($files as $f) {
                    if (str_starts_with($f, "{$season}x{$epFormatted}") || str_starts_with($f, "S{$season}E{$epFormatted}")) {
                        return '/local-media/s%C3%A9ries/'.rawurlencode($cleanTitle).'/Temporada%20'.$season.'/'.rawurlencode($f);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Varre o diretório e calcula os tamanhos.
     */
    protected function scanDirectory(string $dir): array
    {
        $totalBytes = 0;
        $files = [];

        if (! is_dir($dir)) {
            return ['totalBytes' => 0, 'files' => []];
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isFile()) {
                    $size = $item->getSize();
                    $totalBytes += $size;

                    $fullPath = $item->getPathname();
                    $relativePath = ltrim(substr($fullPath, strlen($dir)), '/\\');

                    $files[] = [
                        'name' => $item->getFilename(),
                        'relative_path' => str_replace('\\', '/', $relativePath),
                        'size' => $size,
                        'size_formatted' => $this->formatBytes($size),
                        'modified_at' => date('Y-m-d H:i:s', $item->getMTime()),
                        'category' => str_contains($relativePath, 'filmes') ? 'movie' : (str_contains($relativePath, 'séries') ? 'series' : 'other'),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Erro ao varrer diretório NAS CapyFlix: '.$e->getMessage());
        }

        return [
            'totalBytes' => $totalBytes,
            'files' => $files,
        ];
    }

    /**
     * Formata bytes para leitura humana.
     */
    public function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 2).' '.$units[$i];
    }
}
