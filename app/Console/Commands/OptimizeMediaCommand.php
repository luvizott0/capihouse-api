<?php

namespace App\Console\Commands;

use App\Enums\MediaType;
use App\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OptimizeMediaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:optimize {--limit=100 : Quantidade máxima de mídias para processar}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Otimiza e converte imagens antigas do storage para o formato WebP compactado.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            $this->error('Extensão GD com suporte a WebP não está disponível no PHP.');
            return Command::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $disk = config('filesystems.default', 'public');

        // Busca imagens que ainda não estejam em .webp
        $medias = Media::where('type', MediaType::IMAGE)
            ->where('path', 'not like', '%.webp')
            ->limit($limit)
            ->get();

        if ($medias->isEmpty()) {
            $this->info('Nenhuma imagem pendente de otimização encontrada.');
            return Command::SUCCESS;
        }

        $this->info("Encontradas {$medias->count()} imagens para otimizar...");
        $bar = $this->output->createProgressBar($medias->count());
        $savedBytes = 0;
        $convertedCount = 0;

        foreach ($medias as $media) {
            $rawPath = $media->getRawOriginal('path') ?? $media->attributes['path'] ?? null;
            if (!$rawPath) {
                $bar->advance();
                continue;
            }

            $cleanPath = ltrim(preg_replace('/^\/?storage\//', '', $rawPath), '/');

            if (!Storage::disk($disk)->exists($cleanPath)) {
                $bar->advance();
                continue;
            }

            $originalSize = Storage::disk($disk)->size($cleanPath);
            $content = Storage::disk($disk)->get($cleanPath);

            $sourceImage = @imagecreatefromstring($content);
            if (!$sourceImage) {
                $bar->advance();
                continue;
            }

            $origWidth = imagesx($sourceImage);
            $origHeight = imagesy($sourceImage);
            $maxDim = 1600;

            $targetWidth = $origWidth;
            $targetHeight = $origHeight;

            if ($origWidth > $maxDim || $origHeight > $maxDim) {
                if ($origWidth >= $origHeight) {
                    $targetHeight = (int) round(($origHeight * $maxDim) / $origWidth);
                    $targetWidth = $maxDim;
                } else {
                    $targetWidth = (int) round(($origWidth * $maxDim) / $origHeight);
                    $targetHeight = $maxDim;
                }
            }

            $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
            imagealphablending($targetImage, false);
            imagesavealpha($targetImage, true);

            imagecopyresampled(
                $targetImage,
                $sourceImage,
                0, 0, 0, 0,
                $targetWidth,
                $targetHeight,
                $origWidth,
                $origHeight
            );

            ob_start();
            imagewebp($targetImage, null, 82);
            $webpData = ob_get_clean();

            imagedestroy($sourceImage);
            imagedestroy($targetImage);

            if ($webpData !== false && !empty($webpData)) {
                $folder = dirname($cleanPath);
                $newFileName = Str::random(40) . '.webp';
                $newPath = ($folder === '.' ? '' : $folder . '/') . $newFileName;

                Storage::disk($disk)->put($newPath, $webpData);
                $newSize = strlen($webpData);

                // Atualiza o registro no banco
                $media->path = $newPath;
                $media->saveQuietly();

                // Remove o arquivo antigo para liberar espaço
                Storage::disk($disk)->delete($cleanPath);

                $savedBytes += max(0, $originalSize - $newSize);
                $convertedCount++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $savedMb = round($savedBytes / (1024 * 1024), 2);
        $this->info("Sucesso! {$convertedCount} imagens convertidas para WebP. Economia de espaço estimada: {$savedMb} MB.");

        return Command::SUCCESS;
    }
}
