<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageOptimizerService
{
    /**
     * Otimiza e salva uma imagem no storage, convertendo para WebP e limitando a 1600px.
     * Se for vídeo ou GIF, salva diretamente sem reprocessar.
     *
     * @param  string  $folder  Diretório de destino no storage (ex: "posts/1")
     * @param  int  $maxDimension  Dimensão máxima em pixels (padrão 1600)
     * @param  int  $quality  Qualidade da compressão WebP (0-100, padrão 82)
     * @return string Caminho relativo no storage
     */
    public static function storeOptimized(
        UploadedFile $file,
        string $folder,
        ?string $disk = null,
        int $maxDimension = 1600,
        int $quality = 82
    ): string {
        $disk = $disk ?? config('filesystems.default', 'public');
        $mime = $file->getMimeType() ?? '';

        // Se for vídeo ou GIF animado, salva diretamente sem alterar
        if (str_starts_with($mime, 'video/') || $mime === 'image/gif' || $mime === 'image/svg+xml') {
            return $file->store($folder, $disk);
        }

        // Se a extensão GD não estiver disponível, fallback para salvamento padrão
        if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
            return $file->store($folder, $disk);
        }

        try {
            $content = file_get_contents($file->getRealPath());
            if ($content === false) {
                return $file->store($folder, $disk);
            }

            $sourceImage = @imagecreatefromstring($content);
            if (! $sourceImage) {
                return $file->store($folder, $disk);
            }

            $origWidth = imagesx($sourceImage);
            $origHeight = imagesy($sourceImage);

            $targetWidth = $origWidth;
            $targetHeight = $origHeight;

            // Calcula redimensionamento mantendo a proporção
            if ($origWidth > $maxDimension || $origHeight > $maxDimension) {
                if ($origWidth >= $origHeight) {
                    $targetHeight = (int) round(($origHeight * $maxDimension) / $origWidth);
                    $targetWidth = $maxDimension;
                } else {
                    $targetWidth = (int) round(($origWidth * $maxDimension) / $origHeight);
                    $targetHeight = $maxDimension;
                }
            }

            // Cria a nova imagem com suporte a transparência alpha
            $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
            imagealphablending($targetImage, false);
            imagesavealpha($targetImage, true);

            // Redimensionamento de alta qualidade
            imagecopyresampled(
                $targetImage,
                $sourceImage,
                0, 0, 0, 0,
                $targetWidth,
                $targetHeight,
                $origWidth,
                $origHeight
            );

            // Grava em buffer de memória temporário
            ob_start();
            imagewebp($targetImage, null, $quality);
            $webpData = ob_get_clean();

            // Libera memória das imagens GD
            imagedestroy($sourceImage);
            imagedestroy($targetImage);

            if ($webpData === false || empty($webpData)) {
                return $file->store($folder, $disk);
            }

            // Gera nome de arquivo com extensão .webp
            $filename = Str::random(40).'.webp';
            $finalPath = trim($folder, '/').'/'.$filename;

            Storage::disk($disk)->put($finalPath, $webpData);

            return $finalPath;
        } catch (\Throwable $e) {
            report($e);

            return $file->store($folder, $disk);
        }
    }
}
