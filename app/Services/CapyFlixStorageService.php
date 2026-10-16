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
     * Extensões de vídeo suportadas para reprodução no player.
     */
    protected const VIDEO_EXTENSIONS = ['mp4', 'mkv', 'webm', 'm4v', 'avi', 'mov', 'ts'];

    /**
     * Varre recursivamente um diretório procurando arquivos de vídeo (até $maxDepth níveis).
     *
     * @return string[]
     */
    protected function findVideoFiles(string $dir, int $maxDepth = 2): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $results = [];
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $fullPath = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_file($fullPath)) {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($ext, self::VIDEO_EXTENSIONS, true)) {
                    $results[] = $fullPath;
                }
            } elseif (is_dir($fullPath) && $maxDepth > 0) {
                $sub = $this->findVideoFiles($fullPath, $maxDepth - 1);
                $results = array_merge($results, $sub);
            }
        }

        return $results;
    }

    /**
     * Localiza todas as pastas candidatas da temporada de uma série (suporta subpastas e variações).
     *
     * @return string[]
     */
    protected function resolveSeasonDirectories(string $mediaRoot, string $cleanTitle, int $season): array
    {
        $seriesBase = $mediaRoot.DIRECTORY_SEPARATOR.'séries';
        if (! is_dir($seriesBase)) {
            return [];
        }

        $seriesDirs = [];
        $directSeriesDir = $seriesBase.DIRECTORY_SEPARATOR.$cleanTitle;
        if (is_dir($directSeriesDir)) {
            $seriesDirs[] = $directSeriesDir;
        }

        // Busca flexível por diretório da série (ex: "Hotel Assombrado" vs "Haunted Hotel")
        $allEntries = @scandir($seriesBase) ?: [];
        foreach ($allEntries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $fullPath = $seriesBase.DIRECTORY_SEPARATOR.$entry;
            if (! is_dir($fullPath)) {
                continue;
            }
            if (
                stripos($entry, $cleanTitle) !== false ||
                stripos($cleanTitle, $entry) !== false ||
                str_replace([' ', '-', '_', '.'], '', strtolower($entry)) === str_replace([' ', '-', '_', '.'], '', strtolower($cleanTitle))
            ) {
                if (! in_array($fullPath, $seriesDirs, true)) {
                    $seriesDirs[] = $fullPath;
                }
            }
        }

        $seasonDirs = [];
        foreach ($seriesDirs as $sDir) {
            $candidates = [
                $sDir.DIRECTORY_SEPARATOR."Temporada {$season}",
                $sDir.DIRECTORY_SEPARATOR."Temporada 0{$season}",
                $sDir.DIRECTORY_SEPARATOR."Season {$season}",
                $sDir.DIRECTORY_SEPARATOR."Season 0{$season}",
                $sDir.DIRECTORY_SEPARATOR."Livro {$season}",
                $sDir.DIRECTORY_SEPARATOR."S{$season}",
                $sDir,
            ];
            foreach ($candidates as $cand) {
                if (is_dir($cand) && ! in_array($cand, $seasonDirs, true)) {
                    $seasonDirs[] = $cand;
                }
            }
        }

        return $seasonDirs;
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
            if (is_dir($targetDir)) {
                $videoFiles = $this->findVideoFiles($targetDir, 2);
                foreach ($videoFiles as $vf) {
                    $fn = pathinfo($vf, PATHINFO_FILENAME);
                    if (
                        stripos($fn, $cleanTitle) !== false ||
                        str_replace([' ', '-', '_', '.'], '', strtolower($fn)) === str_replace([' ', '-', '_', '.'], '', strtolower($cleanTitle))
                    ) {
                        $rel = ltrim(str_replace([$mediaRoot, '\\'], ['', '/'], $vf), '/');

                        return '/local-media/'.implode('/', array_map('rawurlencode', explode('/', $rel)));
                    }
                }
            }
        } else {
            $seasonDirs = $this->resolveSeasonDirectories($mediaRoot, $cleanTitle, $season);

            $pattern = '/(?:^|[\s\.\-_\[\(])(?:S?0*'.preg_quote((string) $season, '/').'[xE]|E|Ep|Episode|Episodio)0*'.preg_quote((string) $episode, '/').'(?:[\s\.\-_\]\)]|$)/i';
            $numPattern = '/^0*'.preg_quote((string) $episode, '/').'\.(?:'.implode('|', self::VIDEO_EXTENSIONS).')$/i';

            foreach ($seasonDirs as $sDir) {
                $videoFiles = $this->findVideoFiles($sDir, 2);
                foreach ($videoFiles as $vf) {
                    $fn = basename($vf);
                    if (preg_match($pattern, $fn) || preg_match($numPattern, $fn)) {
                        $rel = ltrim(str_replace([$mediaRoot, '\\'], ['', '/'], $vf), '/');

                        return '/local-media/'.implode('/', array_map('rawurlencode', explode('/', $rel)));
                    }
                }
            }
        }

        return null;
    }

    /**
     * Retorna a lista de números de episódios baixados no NAS para uma temporada específica.
     *
     * @return int[]
     */
    public function getSeasonDownloadedEpisodes(string $mediaType, string $title, int $season = 1): array
    {
        if ($mediaType === 'movie') {
            return $this->findLocalMediaUrl('movie', $title) ? [1] : [];
        }

        $mediaRoot = $this->getMediaRoot();
        $cleanTitle = preg_replace('/[^\w\s\-\.\(\)]/u', '', $title);
        $cleanTitle = trim(preg_replace('/\s+/', ' ', $cleanTitle));

        $seasonDirs = $this->resolveSeasonDirectories($mediaRoot, $cleanTitle, $season);
        $downloaded = [];

        $pattern = '/(?:^|[\s\.\-_\[\(])(?:S?0*'.preg_quote((string) $season, '/').'[xE]|E|Ep|Episode|Episodio)0*(\d+)(?:[\s\.\-_\]\)]|$)/i';
        $numPattern = '/^0*(\d+)\.(?:'.implode('|', self::VIDEO_EXTENSIONS).')$/i';

        foreach ($seasonDirs as $sDir) {
            $videoFiles = $this->findVideoFiles($sDir, 2);
            foreach ($videoFiles as $vf) {
                $fn = basename($vf);
                if (preg_match($pattern, $fn, $m)) {
                    $downloaded[] = (int) $m[1];
                } elseif (preg_match($numPattern, $fn, $m)) {
                    $downloaded[] = (int) $m[1];
                }
            }
        }

        sort($downloaded);

        return array_values(array_unique($downloaded));
    }

    /**
     * Verifica se o filme ou episódio específico já está baixado no NAS.
     */
    public function isMediaDownloaded(string $mediaType, string $title, ?int $season = 1, ?int $episode = 1): bool
    {
        return $this->findLocalMediaUrl($mediaType, $title, $season ?? 1, $episode ?? 1) !== null;
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
