<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Storage;

use Grav\Plugin\Sync\Message\BroadcastMessage;
use RuntimeException;

/**
 * File-backed BroadcastStorage. One JSONL file per channel.
 *
 * Layout:
 *   <dataRoot>/<sha1(channelId)>.jsonl
 *
 * channel ids may include any characters (colons, slashes, @-signs), so we
 * hash to a fixed digest for the filename rather than try to escape them.
 *
 * Each line is a JSON object:
 *   {"event": ?string, "payload": object, "timestamp": int(ms),
 *    "expiresAt": int(unix-seconds)}
 *
 * Concurrency: all read/write operations grab flock(LOCK_EX). The buffer
 * stays small (capped at maxMessages on every append), so the rewrite
 * cost is negligible.
 *
 * Pruning policy on read: drop any entry whose expiresAt is in the past.
 * Pruning happens lazily; nothing forces a sweep otherwise. This is
 * acceptable for a single-host install. Distributed installs that mount
 * shared filesystems should still work; the rewrite is atomic per host.
 */
final class FileBroadcastStorage implements BroadcastStorage
{
    public function __construct(
        private readonly string $dataRoot,
    ) {
        if (!is_dir($dataRoot)) {
            if (!@mkdir($dataRoot, 0755, true) && !is_dir($dataRoot)) {
                throw new RuntimeException("FileBroadcastStorage: cannot create data root: {$dataRoot}");
            }
        }
    }

    public function append(string $channelId, BroadcastMessage $message, int $ttlSeconds, int $maxMessages): void
    {
        if ($ttlSeconds <= 0 || $maxMessages <= 0) {
            return;
        }

        $path = $this->pathFor($channelId);
        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            throw new RuntimeException("FileBroadcastStorage: cannot open {$path}");
        }
        try {
            flock($fp, LOCK_EX);
            $entries = $this->readAllLocked($fp);
            $now = time();
            $entries = array_values(array_filter(
                $entries,
                static fn (array $e): bool => (int)($e['expiresAt'] ?? 0) >= $now
            ));
            $entries[] = [
                'event' => $message->eventName,
                'payload' => $message->payload,
                'timestamp' => $message->timestamp,
                'expiresAt' => $now + $ttlSeconds,
            ];
            if (count($entries) > $maxMessages) {
                $entries = array_slice($entries, -$maxMessages);
            }
            $this->writeAllLocked($fp, $entries);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public function since(string $channelId, ?int $sinceTimestamp): array
    {
        $path = $this->pathFor($channelId);
        if (!is_file($path)) {
            return [];
        }

        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return [];
        }
        try {
            flock($fp, LOCK_EX);
            $entries = $this->readAllLocked($fp);
            $now = time();
            $live = [];
            $changed = false;
            foreach ($entries as $e) {
                if ((int)($e['expiresAt'] ?? 0) < $now) {
                    $changed = true;
                    continue;
                }
                $live[] = $e;
            }
            if ($changed) {
                $this->writeAllLocked($fp, $live);
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        $out = [];
        foreach ($live as $e) {
            $ts = (int)($e['timestamp'] ?? 0);
            if ($sinceTimestamp !== null && $ts <= $sinceTimestamp) {
                continue;
            }
            $out[] = [
                'event' => $e['event'] ?? null,
                'payload' => is_array($e['payload'] ?? null) ? $e['payload'] : [],
                'timestamp' => $ts,
            ];
        }
        return $out;
    }

    public function clear(string $channelId): void
    {
        $path = $this->pathFor($channelId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function pathFor(string $channelId): string
    {
        return rtrim($this->dataRoot, '/') . '/' . sha1($channelId) . '.jsonl';
    }

    /**
     * Read every line in the open file as JSON. Skips malformed lines
     * silently; we never want a single bad write to permanently brick a
     * channel.
     *
     * @return list<array<string, mixed>>
     */
    private function readAllLocked($fp): array
    {
        rewind($fp);
        $entries = [];
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }
        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function writeAllLocked($fp, array $entries): void
    {
        rewind($fp);
        ftruncate($fp, 0);
        foreach ($entries as $e) {
            fwrite($fp, json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        }
        fflush($fp);
    }
}
