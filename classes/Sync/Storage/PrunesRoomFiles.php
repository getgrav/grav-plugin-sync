<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Storage;

/**
 * Age-based removal of room storage, shared by the file and sqlite backends.
 *
 * Both lay rooms out the same way -- `<dataRoot>/<routeHash>/<template>[.<lang>].<ext>`
 * with a `meta.json` alongside -- so one walk serves both. A room's files are
 * grouped by their base name and judged together on the newest mtime among
 * them, so a log and its snapshot are never separated.
 *
 * Rooms have no expiry of their own: one outlives its page whenever the page is
 * deleted, renamed, or has its template changed without the storage being told,
 * and nothing ever reads it again. Grav's own cache gets a scheduled purge for
 * the same reason.
 */
trait PrunesRoomFiles
{
    /**
     * Delete every room whose files have all been untouched since $cutoff.
     *
     * @param int $cutoff unix timestamp; rooms older than this are removed
     * @return int number of rooms removed
     */
    public function pruneRoomsIdleSince(int $cutoff): int
    {
        $removed = 0;

        foreach (glob($this->dataRoot . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $rooms = [];
            foreach (glob($dir . '/*') ?: [] as $file) {
                $name = basename($file);
                if ($name === 'meta.json' || !is_file($file)) {
                    continue;
                }
                // "default.fr.log" and "default.fr.state" are one room.
                $rooms[pathinfo($name, PATHINFO_FILENAME)][] = $file;
            }

            foreach ($rooms as $files) {
                $newest = 0;
                foreach ($files as $file) {
                    $newest = max($newest, (int) @filemtime($file));
                }
                if ($newest === 0 || $newest >= $cutoff) {
                    continue;
                }

                $this->forgetRoomFiles($files);
                foreach ($files as $file) {
                    @unlink($file);
                }
                $removed++;
            }

            // Nothing but the reverse-lookup file left: the whole route is gone.
            $left = array_diff(glob($dir . '/*') ?: [], [$dir . '/meta.json']);
            if (!$left) {
                @unlink($dir . '/meta.json');
                @rmdir($dir);
            }
        }

        return $removed;
    }

    /**
     * Drop anything held open against these files before they are unlinked.
     *
     * @param array $files
     * @return void
     */
    protected function forgetRoomFiles(array $files): void
    {
    }
}
