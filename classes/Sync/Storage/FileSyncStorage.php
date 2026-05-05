<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Storage;

use Grav\Plugin\Sync\SyncStorage;
use RuntimeException;

/**
 * File-backed SyncStorage.
 *
 * Layout (per room):
 *   <dataRoot>/<routeHash>/<template>[.<lang>].log     — append-only update log
 *   <dataRoot>/<routeHash>/<template>[.<lang>].state   — latest snapshot
 *   <dataRoot>/<routeHash>/meta.json                   — reverse-lookup info
 *
 * dataRoot is intentionally outside user/pages so sync data never appears as
 * extra "pages" to Grav. routeHash = md5(route) — the route is otherwise an
 * arbitrary string and we don't want it to collide with the page-folder
 * naming rules (numeric prefixes, language suffixes, etc.).
 *
 * Log format (uses PHP pack 'N' = big-endian uint32):
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
 * Room id format: see RoomRegistry. Two- or three-segment "@"-delimited
 * string: <route>@<template> or <route>@<template>@<lang>.
 */
final class FileSyncStorage implements SyncStorage
{
    public function __construct(
        private readonly string $dataRoot,
        private readonly int $maxUpdateBytes = 10_000_000,
    ) {
        if (!is_dir($dataRoot)) {
            if (!@mkdir($dataRoot, 0755, true) && !is_dir($dataRoot)) {
                throw new RuntimeException("FileSyncStorage: cannot create data root: {$dataRoot}");
            }
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
        $this->ensureRoomDir($roomId);

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

    public function initIfEmpty(string $roomId, string $seed): array
    {
        if ($seed === '') {
            throw new RuntimeException('sync: refusing to seed empty bytes');
        }
        if (strlen($seed) > $this->maxUpdateBytes) {
            throw new RuntimeException('sync: seed exceeds max size');
        }

        $logPath = $this->logPath($roomId);
        $this->ensureRoomDir($roomId);

        // Open with c+b: create if missing, read+write, no truncation. The
        // exclusive lock then serializes against any concurrent appender
        // racing into the same empty room. clearstatcache is required
        // because PHP caches filesize() results within the request.
        $fp = fopen($logPath, 'c+b');
        if (!$fp) {
            throw new RuntimeException("sync: cannot open log for init: {$logPath}");
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException('sync: could not acquire init lock');
            }
            clearstatcache(true, $logPath);
            $size = (int)(filesize($logPath) ?: 0);
            if ($size > 0) {
                flock($fp, LOCK_UN);
                return ['seeded' => false, 'size' => $size];
            }
            fseek($fp, 0, SEEK_END);
            fwrite($fp, pack('N', strlen($seed)));
            fwrite($fp, $seed);
            fflush($fp);
            $newSize = (int)ftell($fp);
            flock($fp, LOCK_UN);
            return ['seeded' => true, 'size' => $newSize];
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
        $this->ensureRoomDir($roomId);
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
        $parts = $this->split($roomId);
        return $this->roomDir($roomId) . '/' . $this->fileBase($parts['template'], $parts['lang']) . '.log';
    }

    private function snapshotPath(string $roomId): string
    {
        $parts = $this->split($roomId);
        return $this->roomDir($roomId) . '/' . $this->fileBase($parts['template'], $parts['lang']) . '.state';
    }

    private function fileBase(string $template, ?string $lang): string
    {
        return $lang === null ? $template : $template . '.' . $lang;
    }

    private function roomDir(string $roomId): string
    {
        $parts = $this->split($roomId);
        return $this->dataRoot . '/' . md5($parts['route']);
    }

    /**
     * Create the room dir (idempotent) and drop a meta.json so an admin can
     * reverse-lookup what page a hash belongs to. Meta is best-effort —
     * failure to write it is not fatal because the log is the source of
     * truth.
     */
    private function ensureRoomDir(string $roomId): void
    {
        $dir = $this->roomDir($roomId);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException("sync: cannot create room dir: {$dir}");
            }
        }
        $metaPath = $dir . '/meta.json';
        if (!is_file($metaPath)) {
            $parts = $this->split($roomId);
            $meta = [
                'route' => $parts['route'],
                'createdAt' => time(),
            ];
            @file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_SLASHES) ?: '{}');
        }
    }

    /**
     * Room id formats:
     *   "<route>@<template>"                — default language
     *   "<route>@<template>@<lang>"         — explicit language
     *
     * @return array{route: string, template: string, lang: ?string}
     */
    private function split(string $roomId): array
    {
        if ($roomId === '') {
            throw new RuntimeException('sync: empty roomId');
        }
        $parts = explode('@', $roomId);
        $count = count($parts);
        if ($count < 2 || $count > 3) {
            throw new RuntimeException('sync: malformed roomId');
        }
        $route = $parts[0];
        $template = $parts[1];
        $lang = $parts[2] ?? null;
        if ($route === '' || $template === '') {
            throw new RuntimeException('sync: malformed roomId');
        }
        $this->validateRoute($route);
        if (!preg_match('/^[a-z0-9_-]+$/i', $template)) {
            throw new RuntimeException("sync: invalid template segment: {$template}");
        }
        if ($lang !== null) {
            if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', $lang)) {
                throw new RuntimeException("sync: invalid lang segment: {$lang}");
            }
            $lang = strtolower($lang);
        }
        return ['route' => $route, 'template' => $template, 'lang' => $lang];
    }

    /**
     * Route is hashed before it touches the filesystem, so traversal is not
     * possible — but we still validate to keep room ids well-formed and
     * reject obvious garbage.
     */
    private function validateRoute(string $route): void
    {
        $route = trim($route, "/ \t\n\r\0\x0B");
        if ($route === '') {
            throw new RuntimeException('sync: empty route');
        }
        foreach (explode('/', $route) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                throw new RuntimeException("sync: unsafe route segment: {$seg}");
            }
            if (!preg_match('/^[a-z0-9._-]+$/i', $seg)) {
                throw new RuntimeException("sync: invalid route segment: {$seg}");
            }
        }
    }
}
