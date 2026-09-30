<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StorageHealthService
{
    protected static ?bool $fakeStatus = null;

    /**
     * Define um status simulado para testes.
     */
    public static function fake(?bool $available = false): void
    {
        static::$fakeStatus = $available;
    }

    /**
     * Reseta o status simulado.
     */
    public static function resetFake(): void
    {
        static::$fakeStatus = null;
    }

    /**
     * Verifica a disponibilidade do NAS / Storage.
     * Utiliza cache curto (15s) para evitar sobrecarga de requisições na rede.
     *
     * @param  bool  $fresh  Se true, ignora o cache e faz a verificação imediata.
     * @return array{available: bool, disk: string, message: string, checked_at: string}
     */
    public function check(bool $fresh = false): array
    {
        if (static::$fakeStatus !== null) {
            return [
                'available' => static::$fakeStatus,
                'disk' => config('filesystems.default', 'public'),
                'message' => static::$fakeStatus ? 'Armazenamento disponível (simulado).' : 'Servidor NAS indisponível (simulado).',
                'checked_at' => now()->toIso8601String(),
            ];
        }

        if (config('filesystems.nas.simulate_offline', env('NAS_SIMULATE_OFFLINE', false))) {
            return [
                'available' => false,
                'disk' => config('filesystems.default', 'public'),
                'message' => 'Servidor NAS offline (simulação ativa via configuração).',
                'checked_at' => now()->toIso8601String(),
            ];
        }

        $cacheKey = 'system:storage_health_status';

        if (! $fresh && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->performCheck();

        // Armazena no cache por 15 segundos
        Cache::put($cacheKey, $result, now()->addSeconds(15));

        return $result;
    }

    /**
     * Executa a checagem real na rede ou no disco ativo.
     */
    protected function performCheck(): array
    {
        $disk = config('filesystems.default', 'public');
        $nasHost = config('filesystems.nas.host', env('NAS_HOST'));
        $nasPort = (int) config('filesystems.nas.port', env('NAS_PORT', 9000));
        $timeout = (float) config('filesystems.nas.timeout', env('NAS_TIMEOUT', 2.0));

        // 1. Se houver host explícito de NAS configurado, testa via socket TCP de forma ultrarrápida
        if (! empty($nasHost)) {
            $socketCheck = $this->checkTcpSocket($nasHost, $nasPort, $timeout);
            if (! $socketCheck) {
                return [
                    'available' => false,
                    'disk' => $disk,
                    'message' => "Servidor NAS inacessível em {$nasHost}:{$nasPort}.",
                    'checked_at' => now()->toIso8601String(),
                ];
            }
        }

        // 2. Se o disco padrão for S3 (MinIO no servidor NAS)
        if ($disk === 's3') {
            $endpoint = config('filesystems.disks.s3.endpoint');

            if (! empty($endpoint)) {
                $endpointHealth = $this->checkHttpEndpoint($endpoint, $timeout);
                if (! $endpointHealth['available']) {
                    return [
                        'available' => false,
                        'disk' => 's3',
                        'message' => 'Servidor de armazenamento S3/MinIO não respondeu na rede local.',
                        'checked_at' => now()->toIso8601String(),
                    ];
                }

                return [
                    'available' => true,
                    'disk' => 's3',
                    'message' => 'Servidor de armazenamento S3/MinIO operacional.',
                    'checked_at' => now()->toIso8601String(),
                ];
            }

            // Fallback para S3 sem endpoint customizado
            try {
                Storage::disk('s3')->exists('_healthcheck_probe');

                return [
                    'available' => true,
                    'disk' => 's3',
                    'message' => 'Armazenamento S3 operacional.',
                    'checked_at' => now()->toIso8601String(),
                ];
            } catch (\Throwable $e) {
                Log::warning('Falha no healthcheck S3: '.$e->getMessage());

                return [
                    'available' => false,
                    'disk' => 's3',
                    'message' => 'Armazenamento S3 indisponível: '.$e->getMessage(),
                    'checked_at' => now()->toIso8601String(),
                ];
            }
        }

        // 3. Disco Local: testa gravação/leitura rápida
        try {
            $tempFile = '_health_probe_'.bin2hex(random_bytes(6)).'.tmp';
            Storage::disk($disk)->put($tempFile, 'ok');
            Storage::disk($disk)->delete($tempFile);

            return [
                'available' => true,
                'disk' => $disk,
                'message' => 'Armazenamento operacional.',
                'checked_at' => now()->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            Log::warning('Falha no teste de gravação do disco local: '.$e->getMessage());

            return [
                'available' => false,
                'disk' => $disk,
                'message' => 'Armazenamento local inacessível: '.$e->getMessage(),
                'checked_at' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Testa conexão TCP com timeout rígido.
     */
    protected function checkTcpSocket(string $host, int $port, float $timeout): bool
    {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if ($fp) {
            fclose($fp);

            return true;
        }

        return false;
    }

    /**
     * Testa endpoint HTTP do MinIO / S3 com timeout baixo.
     * Retorna falso se houver timeout, erro de conexão ou status HTTP >= 500 (ex: 502 Bad Gateway do Nginx Proxy Manager).
     */
    protected function checkHttpEndpoint(string $endpoint, float $timeout): array
    {
        try {
            $cleanEndpoint = rtrim($endpoint, '/');
            $healthUrl = $cleanEndpoint.'/minio/health/live';

            $response = Http::timeout($timeout)
                ->connectTimeout(min(1.0, $timeout))
                ->get($healthUrl);

            if ($response->successful()) {
                return ['available' => true];
            }

            // Se o /minio/health/live retornou 404 (ex: outro gateway S3), tenta a raiz
            if ($response->status() === 404) {
                $rootResponse = Http::timeout($timeout)
                    ->connectTimeout(min(1.0, $timeout))
                    ->get($cleanEndpoint);

                // MinIO/S3 costuma responder 200, 400 ou 403 se o serviço está ativo
                if (in_array($rootResponse->status(), [200, 400, 403])) {
                    return ['available' => true];
                }
            }

            // Status >= 500 indica proxy com upstream caído (ex: 502/504) ou servidor com falha crítica
            return ['available' => false];
        } catch (\Throwable $e) {
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }
}
