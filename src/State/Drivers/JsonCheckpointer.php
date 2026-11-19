<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\State\Drivers;

use RuntimeException;
use Yousef\LaraOrcest\Contracts\CheckpointerContract;
use Yousef\LaraOrcest\State\RunState;

class JsonCheckpointer implements CheckpointerContract
{
    protected string $storagePath;

    public function __construct(?string $storagePath = null)
    {
        $container = \Illuminate\Container\Container::getInstance();
        $this->storagePath = $storagePath ?: ($container !== null && method_exists($container, 'storagePath')
            ? storage_path('app/orcest/checkpoints')
            : sys_get_temp_dir() . '/lara-orcest-checkpoints');

        if (! is_dir($this->storagePath) && ! @mkdir($this->storagePath, 0755, true) && ! is_dir($this->storagePath)) {
            throw new RuntimeException("Cannot create checkpoint directory: {$this->storagePath}");
        }
    }

    public function save(RunState $state): void
    {
        $filePath = $this->getFilePath($state->runId);
        $tempPath = $filePath . '.' . bin2hex(random_bytes(6)) . '.tmp';

        $data = $state->toArray();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException("Failed to encode RunState to JSON for run: {$state->runId}");
        }

        if (file_put_contents($tempPath, $json, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write temporary checkpoint to: {$tempPath}");
        }

        if (! rename($tempPath, $filePath)) {
            @unlink($tempPath);
            throw new RuntimeException("Failed to rename temporary checkpoint to: {$filePath}");
        }
    }

    public function load(string $runId): ?RunState
    {
        $filePath = $this->getFilePath($runId);
        if (! file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            throw new RuntimeException("Corrupted JSON checkpoint for run: {$runId}");
        }

        return RunState::fromArray($data);
    }

    public function list(int $limit = 20): array
    {
        $files = glob($this->storagePath . '/*.json');
        if (! $files) {
            return [];
        }

        // Sort by modification time descending
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $files = array_slice($files, 0, $limit);

        $results = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (! $content) {
                continue;
            }
            $data = json_decode($content, true);
            if (! is_array($data)) {
                continue;
            }

            $results[] = [
                'run_id' => (string) ($data['run_id'] ?? basename($file, '.json')),
                'prompt' => (string) ($data['prompt'] ?? ''),
                'current_node' => (string) ($data['current_node'] ?? 'unknown'),
                'status' => (string) ($data['status'] ?? 'unknown'),
                'updated_at' => date('c', filemtime($file)),
            ];
        }

        return $results;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    protected function getFilePath(string $runId): string
    {
        // Sanitize run ID to prevent path traversal
        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $runId);

        return $this->storagePath . DIRECTORY_SEPARATOR . $safeId . '.json';
    }
}
