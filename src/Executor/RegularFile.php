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
     * Copy $path to $destination, a new file outside the container's reach, from the handle that
     * passed the checks, and return the bytes copied. Null when $path is not a regular file.
     *
     * Uploading $path by name would reopen it, and a container still running can swap a symlink
     * in for that open and back again. The copy is what gets uploaded instead.
     */
    public static function copy(string $path, string $destination): ?int
    {
        $source = self::open($path);
        if ($source === null) {
            return null;
        }

        try {
            // 'x' fails if anything already sits at $destination, a symlink included.
            $target = @\fopen($destination, 'xb');
            if ($target === false) {
                throw new \Exception('Failed to create ' . $destination);
            }

            try {
                $copied = \stream_copy_to_stream($source, $target);
                if ($copied === false) {
                    throw new \Exception('Failed to copy ' . $path);
                }

                return $copied;
            } finally {
                \fclose($target);
            }
        } finally {
            \fclose($source);
        }
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
     * The open handle when $path is a regular file that opens as itself, otherwise null:
     * a symlink, FIFO, device, directory or missing path.
     *
     * @return resource|null
     */
    private static function open(string $path)
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

        return $handle;
    }
}
