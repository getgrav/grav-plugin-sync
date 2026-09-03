<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Storage;

use Grav\Plugin\Sync\SyncStorage;
use PDO;
use PDOException;
use RuntimeException;

/**
 * SQLite-backed SyncStorage.
 *
 * Layout (per room):
 *   <dataRoot>/<routeHash>/<template>[.<lang>].sqlite   — per-room database
 *   <dataRoot>/<routeHash>/meta.json                    — reverse-lookup info
 *
 * One database per room rather than a single global db so that:
 *   - high-concurrency writers on different rooms don't contend on the same
 *     sqlite writer lock,
 *   - deleteRoom() stays a `rm -rf` of the room dir,
 *   - the existing routeHash dir layout (and meta.json) is preserved.
 *
 * Cursor model: `end_offset` is the cumulative virtual byte position of each
 * row, computed as `prev_end_offset + 4 + len(update_blob)`. This intentionally
 * matches FileSyncStorage's on-disk byte layout so the opaque cursor returned
 * to clients is interchangeable between backends and `max_log_bytes` (a
 * byte-count squash trigger) behaves identically.
 *
 * Concurrency: WAL mode + `synchronous=NORMAL` lets concurrent readers run
 * lock-free while a single writer holds the writer lock. `initIfEmpty` takes
 * the writer lock up front via `BEGIN IMMEDIATE` to resolve the empty-room
 * race the same way the file backend's `flock(LOCK_EX)` does.
 *
 * Snapshot writes are atomic by virtue of being a single transactional
 * REPLACE — no tmp-rename dance required.
 */
final class SqliteSyncStorage implements SyncStorage
{
    use PrunesRoomFiles;

    /** @var array<string, PDO> Cached PDO handles keyed by absolute db path. */
    private array $connections = [];

    public function __construct(
        private readonly string $dataRoot,
        private readonly int $maxUpdateBytes = 10_000_000,
    ) {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('SqliteSyncStorage: pdo_sqlite extension is not loaded');
        }
        if (!is_dir($dataRoot)) {
            if (!@mkdir($dataRoot, 0755, true) && !is_dir($dataRoot)) {
                throw new RuntimeException("SqliteSyncStorage: cannot create data root: {$dataRoot}");
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

        $pdo = $this->db($roomId);
        // BEGIN IMMEDIATE grabs the writer lock before we read MAX(end_offset);
        // a deferred BEGIN would let two concurrent appenders both observe the
        // same max and race on insert, with one losing to SQLITE_BUSY when it
        // tries to upgrade. See initIfEmpty for the same pattern.
        $pdo->exec('BEGIN IMMEDIATE');
        $committed = false;
        try {
            $newEnd = $this->currentSize($pdo) + 4 + strlen($update);
            $stmt = $pdo->prepare(
                'INSERT INTO updates (end_offset, update_blob, client_id, created_at) VALUES (?, ?, ?, ?)'
            );
            $stmt->bindValue(1, $newEnd, PDO::PARAM_INT);
            $stmt->bindValue(2, $update, PDO::PARAM_LOB);
            $stmt->bindValue(3, $clientId, $clientId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(4, time(), PDO::PARAM_INT);
            $stmt->execute();
            $pdo->exec('COMMIT');
            $committed = true;
            return $newEnd;
        } catch (PDOException $e) {
            if (!$committed) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (PDOException) {
                    // Best-effort rollback — the original error wins.
                }
            }
            throw new RuntimeException('sync: append failed: ' . $e->getMessage(), 0, $e);
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

        $pdo = $this->db($roomId);
        // BEGIN IMMEDIATE takes the writer lock immediately, serializing any
        // concurrent caller racing into the same fresh room. Whoever loses
        // the race observes a non-zero size and bails with seeded=false.
        // We issue the BEGIN/COMMIT directly via exec() because PDO's
        // beginTransaction() only emits a deferred BEGIN, which would not
        // give us the writer lock until the first write — defeating the
        // whole point of the lock-up-front pattern.
        $pdo->exec('BEGIN IMMEDIATE');
        $committed = false;
        try {
            $size = $this->currentSize($pdo);
            if ($size > 0) {
                $pdo->exec('COMMIT');
                $committed = true;
                return ['seeded' => false, 'size' => $size];
            }
            $newEnd = 4 + strlen($seed);
            $stmt = $pdo->prepare(
                'INSERT INTO updates (end_offset, update_blob, client_id, created_at) VALUES (?, ?, NULL, ?)'
            );
            $stmt->bindValue(1, $newEnd, PDO::PARAM_INT);
            $stmt->bindValue(2, $seed, PDO::PARAM_LOB);
            $stmt->bindValue(3, time(), PDO::PARAM_INT);
            $stmt->execute();
            $pdo->exec('COMMIT');
            $committed = true;
            return ['seeded' => true, 'size' => $newEnd];
        } catch (PDOException $e) {
            if (!$committed) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (PDOException) {
                    // Best-effort rollback — the original error wins.
                }
            }
            throw new RuntimeException('sync: init failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getUpdatesSince(string $roomId, int $offset): array
    {
        if (!$this->dbExists($roomId)) {
            return ['updates' => [], 'offset' => 0, 'size' => 0];
        }
        if ($offset < 0) {
            $offset = 0;
        }

        $pdo = $this->db($roomId);
        $size = $this->currentSize($pdo);
        if ($offset >= $size) {
            return ['updates' => [], 'offset' => $size, 'size' => $size];
        }

        $stmt = $pdo->prepare(
            'SELECT end_offset, update_blob FROM updates WHERE end_offset > :off ORDER BY end_offset ASC'
        );
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $updates = [];
        $newOffset = $offset;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $blob = $row['update_blob'];
            // SQLite BLOBs can come back as resources in some PDO configs;
            // normalize to a binary string.
            if (is_resource($blob)) {
                $blob = stream_get_contents($blob);
            }
            if (!is_string($blob)) {
                continue;
            }
            $updates[] = $blob;
            $newOffset = (int)$row['end_offset'];
        }

        return ['updates' => $updates, 'offset' => $newOffset, 'size' => $size];
    }

    public function logSize(string $roomId): int
    {
        if (!$this->dbExists($roomId)) {
            return 0;
        }
        return $this->currentSize($this->db($roomId));
    }

    public function loadSnapshot(string $roomId): ?array
    {
        if (!$this->dbExists($roomId)) {
            return null;
        }
        $pdo = $this->db($roomId);
        $stmt = $pdo->query(
            'SELECT snapshot, state_vector, updated_at FROM snapshot WHERE id = 1'
        );
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!$row) {
            return null;
        }
        $snap = $row['snapshot'];
        $sv = $row['state_vector'];
        if (is_resource($snap)) {
            $snap = stream_get_contents($snap);
        }
        if (is_resource($sv)) {
            $sv = stream_get_contents($sv);
        }
        if (!is_string($snap) || !is_string($sv)) {
            return null;
        }
        return [
            'snapshot' => $snap,
            'stateVector' => $sv,
            'updatedAt' => (int)$row['updated_at'],
        ];
    }

    public function writeSnapshot(string $roomId, string $snapshot, string $stateVector): void
    {
        $pdo = $this->db($roomId);
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO snapshot (id, snapshot, state_vector, updated_at) VALUES (1, ?, ?, ?)
                 ON CONFLICT(id) DO UPDATE SET snapshot=excluded.snapshot,
                                               state_vector=excluded.state_vector,
                                               updated_at=excluded.updated_at'
            );
            $stmt->bindValue(1, $snapshot, PDO::PARAM_LOB);
            $stmt->bindValue(2, $stateVector, PDO::PARAM_LOB);
            $stmt->bindValue(3, time(), PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new RuntimeException('sync: snapshot write failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function truncateUpdates(string $roomId, int $beforeOffset): void
    {
        if (!$this->dbExists($roomId) || $beforeOffset <= 0) {
            return;
        }
        $pdo = $this->db($roomId);
        try {
            $stmt = $pdo->prepare('DELETE FROM updates WHERE end_offset <= :off');
            $stmt->bindValue(':off', $beforeOffset, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new RuntimeException('sync: truncate failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Windows in particular won't unlink an open database, so release any handle
     * pointing at a file we're about to remove.
     *
     * @param array $files
     * @return void
     */
    protected function forgetRoomFiles(array $files): void
    {
        foreach ($files as $file) {
            unset($this->connections[$file]);
        }
    }

    public function deleteRoom(string $roomId): void
    {
        $dir = $this->roomDir($roomId);
        if (!is_dir($dir)) {
            return;
        }
        // Drop any cached handle pointing at files inside this dir before we
        // unlink them — Windows in particular won't let you remove an open db.
        foreach (array_keys($this->connections) as $path) {
            if (str_starts_with($path, $dir . DIRECTORY_SEPARATOR) || str_starts_with($path, $dir . '/')) {
                unset($this->connections[$path]);
            }
        }
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    public function exists(string $roomId): bool
    {
        if (!$this->dbExists($roomId)) {
            return false;
        }
        $pdo = $this->db($roomId);
        $hasUpdates = (int)$pdo->query('SELECT COUNT(*) FROM updates')->fetchColumn() > 0;
        if ($hasUpdates) {
            return true;
        }
        return (int)$pdo->query('SELECT COUNT(*) FROM snapshot')->fetchColumn() > 0;
    }

    // ------------------------------------------------------------------

    private function currentSize(PDO $pdo): int
    {
        $val = $pdo->query('SELECT COALESCE(MAX(end_offset), 0) FROM updates')->fetchColumn();
        return (int)$val;
    }

    private function db(string $roomId): PDO
    {
        $path = $this->dbPath($roomId);
        if (isset($this->connections[$path])) {
            return $this->connections[$path];
        }

        $this->ensureRoomDir($roomId);
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // busy_timeout must be set before any other pragma so subsequent waits
        // are transparent rather than surfacing SQLITE_BUSY to callers.
        $pdo->exec('PRAGMA busy_timeout=5000');
        // Switching journal_mode to WAL requires an exclusive lock, and
        // SQLite's busy handler is NOT consulted for that one pragma — so
        // simultaneous first-opens from multiple processes can return
        // SQLITE_BUSY here even with busy_timeout set. Skip the switch if WAL
        // is already in effect (the common case after the first opener wins),
        // and retry with backoff on contention for the actual first switch.
        $current = strtolower((string)$pdo->query('PRAGMA journal_mode')->fetchColumn());
        if ($current !== 'wal') {
            $attempts = 0;
            while (true) {
                try {
                    $pdo->exec('PRAGMA journal_mode=WAL');
                    break;
                } catch (PDOException $e) {
                    if (++$attempts >= 50) {
                        throw $e;
                    }
                    // 1ms..50ms random backoff to break up lock-step racers.
                    usleep(random_int(1000, 50_000));
                }
            }
        }
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS updates (
                end_offset  INTEGER PRIMARY KEY,
                update_blob BLOB    NOT NULL,
                client_id   TEXT,
                created_at  INTEGER NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS snapshot (
                id           INTEGER PRIMARY KEY CHECK (id = 1),
                snapshot     BLOB    NOT NULL,
                state_vector BLOB    NOT NULL,
                updated_at   INTEGER NOT NULL
            )'
        );

        $this->connections[$path] = $pdo;
        return $pdo;
    }

    private function dbExists(string $roomId): bool
    {
        return is_file($this->dbPath($roomId));
    }

    private function dbPath(string $roomId): string
    {
        $parts = $this->split($roomId);
        return $this->roomDir($roomId) . '/' . $this->fileBase($parts['template'], $parts['lang']) . '.sqlite';
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
