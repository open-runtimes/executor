<?php

namespace OpenRuntimes\Executor;

/**
 * A file in a directory that a runtime or build container can write: its logs, logging,
 * build output and cache mounts.
 *
 * The container controls what sits at the path, so it is untrusted. A symlink there made the
 * executor read its own files (its environment, with OPR_EXECUTOR_SECRET) and hand them back
 * as logs or build output. Only a regular file that is still the same file once opened is used.
 */
final class RegularFile
{
    /**
     * The lstat of $path when it is a regular file that opens as itself, otherwise null:
     * a symlink, FIFO, device, directory or missing path.
     *
     * @return array<int|string, int>|null
     */
    public static function stat(string $path): ?array
    {
        $handle = self::open($path, $stat);
        if ($handle === null) {
            return null;
        }

        \fclose($handle);

        return $stat;
    }

    /**
     * Whether $path is still the regular file $stat described, with the same size.
     *
     * @param array<int|string, int> $stat
     */
    public static function unchanged(string $path, array $stat): bool
    {
        $now = self::stat($path);

        return $now !== null && $now['dev'] === $stat['dev'] && $now['ino'] === $stat['ino'] && $now['size'] === $stat['size'];
    }

    /**
     * Up to $length bytes of $path from $offset (all of it when $length is null), or '' when
     * $path is not a regular file.
     */
    public static function read(string $path, int $offset = 0, ?int $length = null): string
    {
        $handle = self::open($path);
        if ($handle === null) {
            return '';
        }

        try {
            return (string) \stream_get_contents($handle, $length, $offset);
        } finally {
            \fclose($handle);
        }
    }

    /**
     * @param array<int|string, int>|null $stat
     * @return resource|null
     */
    private static function open(string $path, ?array &$stat = null)
    {
        $link = @\lstat($path);
        // lstat describes the entry itself, so a symlink is refused rather than followed.
        if ($link === false || ($link['mode'] & 0170000) !== 0100000) {
            return null;
        }

        // 'n' opens with O_NONBLOCK: a FIFO swapped in after the lstat would otherwise block
        // this open forever with no writer. It opens at once instead, and the inode check refuses it.
        $handle = @\fopen($path, 'rbn');
        if ($handle === false) {
            return null;
        }

        // Swapped for a symlink between lstat and fopen, the open followed it to another inode.
        $opened = \fstat($handle);
        if ($opened === false || $opened['dev'] !== $link['dev'] || $opened['ino'] !== $link['ino']) {
            \fclose($handle);
            return null;
        }

        $stat = $link;

        return $handle;
    }
}
