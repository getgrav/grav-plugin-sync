<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Storage;

use Grav\Plugin\Sync\SyncStorage;
use RuntimeException;

/**
 * File-backed SyncStorage.
 *
 * Layout (per room):
 *   <pagesRoot>/<route>/.sync/<template>.log     — append-only update log
 *   <pagesRoot>/<route>/.sync/<template>.state   — latest snapshot (optional)
 *
 * Log format (little-endian-agnostic; uses PHP pack 'N' = big-endian uint32):
 *   [4 bytes BE length N][N bytes: binary Yjs update]
 *   [4 bytes BE length N][N bytes: binary Yjs update]
 *   ...
 *
 * Cursor model: absolute byte offset into the log file. Clients pull with
 * whatever offset the server returned last; server returns new offset plus
 * any updates in between.
 *
 * Concurrency: appends use flock(LOCK_EX) to serialize. Reads use LOCK_SH.
 * Snapshot writes use rename-swap for atomicity.
 *
 * Note: roomId may be any string; we route it through a safe-path sanitizer
 * in pathFor() so a malicious id cannot escape the pages root.
 */
final class FileSyncStorage implements SyncStorage
{
    public function __construct(
        private readonly string $pagesRoot,
        private readonly int $maxUpdateBytes = 10_000_000,
    ) {
        if (!is_dir($pagesRoot)) {
            throw new RuntimeException("FileSyncStorage: pages root does not exist: {$pagesRoot}");
        }
    }

    public function appendUpdate(string $roomId, string $update, ?string $clientId = null): int
    {
        if ($update === '') {
            throw new RuntimeException('sync: refusing to append empty update');
        }
        if (strlen($update) > $this->maxUpdateBytes) {
            throw new RuntimeException('sync: update exceeds max size');
        }

        $logPath = $this->logPath($roomId);
        $this->ensureDir(dirname($logPath));

        $fp = fopen($logPath, 'ab');
        if (!$fp) {
            throw new RuntimeException("sync: cannot open log for append: {$logPath}");
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException('sync: could not acquire append lock');
            }
            fwrite($fp, pack('N', strlen($update)));
            fwrite($fp, $update);
            fflush($fp);
            $size = ftell($fp);
            flock($fp, LOCK_UN);
            return (int)$size;
        } finally {
            fclose($fp);
        }
    }

    public function getUpdatesSince(string $roomId, int $offset): array
    {
        $logPath = $this->logPath($roomId);
        if (!is_file($logPath)) {
            return ['updates' => [], 'offset' => 0, 'size' => 0];
        }
        $size = (int)filesize($logPath);
        if ($offset < 0) {
            $offset = 0;
        }
        if ($offset >= $size) {
            return ['updates' => [], 'offset' => $size, 'size' => $size];
        }

        $fp = fopen($logPath, 'rb');
        if (!$fp) {
            throw new RuntimeException("sync: cannot open log for read: {$logPath}");
        }
        try {
            flock($fp, LOCK_SH);
            fseek($fp, $offset);
            $updates = [];
            while (ftell($fp) < $size) {
                $lenBytes = fread($fp, 4);
                if (strlen($lenBytes) < 4) {
                    break;
                }
                $unpacked = unpack('N', $lenBytes);
                $len = $unpacked[1] ?? 0;
                if ($len <= 0 || $len > $this->maxUpdateBytes) {
                    // Corruption; stop at last valid entry.
                    break;
                }
                $data = fread($fp, $len);
                if (strlen($data) < $len) {
                    break;
                }
                $updates[] = $data;
            }
            $newOffset = (int)ftell($fp);
            flock($fp, LOCK_UN);
            return ['updates' => $updates, 'offset' => $newOffset, 'size' => $size];
        } finally {
            fclose($fp);
        }
    }

    public function logSize(string $roomId): int
    {
        $logPath = $this->logPath($roomId);
        return is_file($logPath) ? (int)filesize($logPath) : 0;
    }

    public function loadSnapshot(string $roomId): ?array
    {
        $path = $this->snapshotPath($roomId);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || strlen($raw) < 12) {
            return null;
        }
        // Snapshot format:
        //   [4 bytes BE snapshotLen][4 bytes BE stateVectorLen][4 bytes BE updatedAt]
        //   [snapshotLen bytes][stateVectorLen bytes]
        $hdr = unpack('NsnapLen/NsvLen/NupdatedAt', substr($raw, 0, 12));
        $snapLen = $hdr['snapLen'] ?? 0;
        $svLen = $hdr['svLen'] ?? 0;
        $updatedAt = $hdr['updatedAt'] ?? 0;
        $expected = 12 + $snapLen + $svLen;
        if (strlen($raw) < $expected) {
            return null;
        }
        return [
            'snapshot' => substr($raw, 12, $snapLen),
            'stateVector' => substr($raw, 12 + $snapLen, $svLen),
            'updatedAt' => (int)$updatedAt,
        ];
    }

    public function writeSnapshot(string $roomId, string $snapshot, string $stateVector): void
    {
        $path = $this->snapshotPath($roomId);
        $this->ensureDir(dirname($path));
        $header = pack('NNN', strlen($snapshot), strlen($stateVector), time());
        $payload = $header . $snapshot . $stateVector;

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $written = file_put_contents($tmp, $payload, LOCK_EX);
        if ($written === false || $written !== strlen($payload)) {
            @unlink($tmp);
            throw new RuntimeException("sync: snapshot write failed: {$path}");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("sync: snapshot rename failed: {$path}");
        }
    }

    public function truncateUpdates(string $roomId, int $beforeOffset): void
    {
        $logPath = $this->logPath($roomId);
        if (!is_file($logPath)) {
            return;
        }
        $size = (int)filesize($logPath);
        if ($beforeOffset <= 0) {
            return;
        }
        if ($beforeOffset >= $size) {
            // Truncate fully; use LOCK_EX to avoid racing with an append.
            $fp = fopen($logPath, 'cb');
            if (!$fp) {
                throw new RuntimeException('sync: cannot open log for truncate');
            }
            try {
                flock($fp, LOCK_EX);
                ftruncate($fp, 0);
                flock($fp, LOCK_UN);
            } finally {
                fclose($fp);
            }
            return;
        }

        // Partial truncate: read tail, rewrite atomically via rename-swap.
        $fp = fopen($logPath, 'rb');
        if (!$fp) {
            throw new RuntimeException('sync: cannot open log for partial truncate');
        }
        try {
            flock($fp, LOCK_EX);
            fseek($fp, $beforeOffset);
            $tail = stream_get_contents($fp);
            $tmp = $logPath . '.tmp.' . bin2hex(random_bytes(4));
            if (file_put_contents($tmp, $tail ?? '', LOCK_EX) === false) {
                @unlink($tmp);
                throw new RuntimeException('sync: partial-truncate write failed');
            }
            if (!rename($tmp, $logPath)) {
                @unlink($tmp);
                throw new RuntimeException('sync: partial-truncate rename failed');
            }
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
    }

    public function deleteRoom(string $roomId): void
    {
        $dir = $this->roomDir($roomId);
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    public function exists(string $roomId): bool
    {
        return is_file($this->logPath($roomId)) || is_file($this->snapshotPath($roomId));
    }

    // ------------------------------------------------------------------

    private function logPath(string $roomId): string
    {
        [, $template] = $this->split($roomId);
        return $this->roomDir($roomId) . '/' . $template . '.log';
    }

    private function snapshotPath(string $roomId): string
    {
        [, $template] = $this->split($roomId);
        return $this->roomDir($roomId) . '/' . $template . '.state';
    }

    private function roomDir(string $roomId): string
    {
        [$route] = $this->split($roomId);
        $routePath = $this->sanitizeRoute($route);
        return $this->pagesRoot . '/' . $routePath . '/.sync';
    }

    /**
     * Room id format: "route@template" or just "route" (template defaults to
     * "default"). Route may include language suffix as /path.<lang> — we
     * don't interpret it here; it's just part of the folder path.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $roomId): array
    {
        if ($roomId === '') {
            throw new RuntimeException('sync: empty roomId');
        }
        $atPos = strrpos($roomId, '@');
        if ($atPos === false) {
            return [$roomId, 'default'];
        }
        $route = substr($roomId, 0, $atPos);
        $template = substr($roomId, $atPos + 1);
        if ($route === '' || $template === '') {
            throw new RuntimeException('sync: malformed roomId');
        }
        return [$route, $template];
    }

    /**
     * Prevent path traversal. Route segments must not contain '..' and must
     * be composed of safe characters. We also collapse leading/trailing
     * slashes.
     */
    private function sanitizeRoute(string $route): string
    {
        $route = trim($route, "/ \t\n\r\0\x0B");
        if ($route === '') {
            throw new RuntimeException('sync: empty route');
        }
        $segments = explode('/', $route);
        $safe = [];
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                throw new RuntimeException("sync: unsafe route segment: {$seg}");
            }
            if (!preg_match('/^[a-z0-9._-]+$/i', $seg)) {
                throw new RuntimeException("sync: invalid route segment: {$seg}");
            }
            $safe[] = $seg;
        }
        return implode('/', $safe);
    }

    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("sync: cannot create directory: {$dir}");
        }
    }
}
