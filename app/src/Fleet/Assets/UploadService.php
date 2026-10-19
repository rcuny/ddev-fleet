<?php

declare(strict_types=1);

namespace App\Fleet\Assets;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;
use Psr\Clock\ClockInterface;

/**
 * Chunked, resumable uploads into a project's assets directory.
 *
 * The browser asks to begin an upload (name, size), gets the chunk size and sends the chunks in any order, any number
 * of times, then asks to complete it. Staging: `uploads/<id>/` (0700) under FLEET_HOME holds `meta.json` and one
 * `<index>.part` file per chunk. A chunk is written to a temporary file and renamed, so a part is either absent or whole,
 * and sending it twice just replaces it. Completing takes a per-upload flock (a second complete is refused while the
 * first runs), concatenates the parts into a hidden temporary file next to the target (same filesystem, so the last step
 * is atomic), deleting each part as it is consumed so the peak disk use is the file plus one chunk, and then puts it in
 * place through AssetStore::publish(). The sha256 is computed on the way and returned.
 *
 * Limits: a file is at most the host's cap (HostSettings::assetUploadMaxBytes, 2 GiB by default); a chunk is at most
 * MAX_CHUNK_BYTES (so neither php-fpm nor Caddy body limits matter), and an upload has at most MAX_CHUNKS chunks.
 * An upload nobody touched for ABANDON_AFTER seconds is removed by the next begin() and by `fleet asset:gc`.
 *
 * Errors: ValidationFailed (a value is wrong), NotFound (no such upload), Refused with an HTTP-like code (see the
 * constants) for everything else. The callers have already checked that the project exists.
 *
 * @phpstan-type Meta array{project: string, dir: string, name: string, size: int, chunkSize: int, chunks: int, fingerprint: string, overwrite: bool}
 */
final readonly class UploadService
{
    public const int MAX_CHUNK_BYTES = 8_388_608;
    public const int MIN_CHUNK_BYTES = 65_536;
    public const int MAX_CHUNKS = 65_536;
    public const int ABANDON_AFTER = 86_400;

    /** Refused codes. */
    public const int CONFLICT = 409;
    public const int INCOMPLETE = 422;
    public const int BUSY = 423;
    public const int TOO_LARGE = 413;
    public const int NO_SPACE = 507;

    private const string META = 'meta.json';
    private const string LOCK = '.lock';
    private const int BLOCK = 1_048_576;

    public function __construct(
        private FleetPaths $paths,
        private AssetStore $store,
        private HostSettingsRepository $settings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The cap on one file, in bytes.
     */
    public function maxBytes(): int
    {
        return $this->settings->get()?->assetUploadMaxBytes() ?? HostSettings::DEFAULT_ASSET_UPLOAD_MAX_BYTES;
    }

    /**
     * Starts an upload, or resumes the one already staged for the same project, folder, name, size and fingerprint.
     *
     * @param int|null $chunkSize   the chunk size the browser would like; clamped to MIN_CHUNK_BYTES..MAX_CHUNK_BYTES
     * @param string   $fingerprint anything the browser derives from the file (name, size, last modified): equal
     *                              fingerprints resume the same upload; empty never resumes
     *
     * @throws ValidationFailed
     * @throws Refused          too large (413), no space (507), the name is taken (409), a symbolic link is in the way
     */
    public function begin(string $project, string $dir, string $name, int $size, ?int $chunkSize = null, string $fingerprint = '', bool $overwrite = false): UploadState
    {
        $this->cleanupUploads();

        AssetPath::segments($dir);
        AssetPath::name($name);
        if ($size < 1) {
            throw new ValidationFailed(['size' => 'The file is empty.']);
        }
        if (\strlen($fingerprint) > 512) {
            throw new ValidationFailed(['fingerprint' => 'The fingerprint is longer than 512 bytes.']);
        }
        $max = $this->maxBytes();
        if ($size > $max) {
            throw new Refused(\sprintf('The file is %s, more than the upload limit of %s. Copy bigger files into %s over SSH instead.', ByteSize::format($size), ByteSize::format($max), $this->paths->assetsDir($project)), self::TOO_LARGE);
        }
        $chunkSize = max(self::MIN_CHUNK_BYTES, min($chunkSize ?? self::MAX_CHUNK_BYTES, self::MAX_CHUNK_BYTES));
        if ((int) ceil($size / $chunkSize) > self::MAX_CHUNKS) {
            throw new Refused('The file is too large for a browser upload.', self::TOO_LARGE);
        }

        // Fails now, not after the whole file is sent, when the target cannot be written.
        [$targetDirectory] = $this->store->prepareTarget($project, $dir, $name, $overwrite);

        // The chunks are staged under uploads/ and the file is assembled next to its target under assets/: both may be
        // different filesystems, so both need room (on one filesystem the parts are deleted as they are copied).
        $this->ensureUploadsDirectory();
        foreach ([$this->paths->uploadsDir(), $targetDirectory] as $where) {
            $free = @disk_free_space($where);
            if (false !== $free && $free < $size + self::MAX_CHUNK_BYTES) {
                throw new Refused('There is not enough free disk space for that file.', self::NO_SPACE);
            }
        }

        if ('' !== $fingerprint) {
            foreach ($this->staged() as $id => $meta) {
                if ($meta['project'] === $project && $meta['dir'] === $dir && $meta['name'] === $name && $meta['size'] === $size && $meta['fingerprint'] === $fingerprint) {
                    $meta['overwrite'] = $overwrite;
                    $this->writeMeta($id, $meta);

                    return $this->state($id, $meta);
                }
            }
        }

        $id = bin2hex(random_bytes(16));
        $directory = $this->paths->uploadDir($id);
        if (!@mkdir($directory, 0o700) && !is_dir($directory)) {
            throw new Refused('The upload could not be started.');
        }
        @chmod($directory, 0o700);
        $meta = [
            'project' => $project,
            'dir' => $dir,
            'name' => $name,
            'size' => $size,
            'chunkSize' => $chunkSize,
            'chunks' => (int) ceil($size / $chunkSize),
            'fingerprint' => $fingerprint,
            'overwrite' => $overwrite,
        ];
        $this->writeMeta($id, $meta);

        return $this->state($id, $meta);
    }

    /**
     * @throws NotFound
     */
    public function status(string $project, string $id): UploadState
    {
        $meta = $this->load($project, $id);

        return $this->state($id, $meta);
    }

    /**
     * Stores one chunk. The body must be exactly the chunk's length: MIN..MAX chunk size for all but the last, the
     * remainder for the last. Sending a chunk again replaces it.
     *
     * @param resource|string $body
     *
     * @throws NotFound
     * @throws ValidationFailed field `chunk`
     * @throws Refused
     */
    public function putChunk(string $project, string $id, int $index, mixed $body): UploadState
    {
        $meta = $this->load($project, $id);
        if ($index < 0 || $index >= $meta['chunks']) {
            throw new ValidationFailed(['chunk' => \sprintf('There is no chunk %d: this upload has %d.', $index, $meta['chunks'])]);
        }
        $expected = $this->chunkLength($meta, $index);

        $directory = $this->paths->uploadDir($id);
        $temporary = \sprintf('%s/%d.%s.tmp', $directory, $index, bin2hex(random_bytes(4)));
        $out = @fopen($temporary, 'x');
        if (false === $out) {
            throw new Refused('The chunk could not be stored.');
        }
        $in = \is_string($body) ? self::memory($body) : $body;
        $received = 0;
        try {
            while ($received <= $expected && !feof($in)) {
                $block = fread($in, max(1, min(self::BLOCK, $expected + 1 - $received)));
                if (false === $block || '' === $block) {
                    break;
                }
                $received += \strlen($block);
                if ($received <= $expected) {
                    self::writeAll($out, $block);
                }
            }
            fclose($out);
            if ($received !== $expected) {
                throw new ValidationFailed(['chunk' => \sprintf('Chunk %d should be %d bytes; %s arrived.', $index, $expected, $received > $expected ? 'more' : $received.' bytes')]);
            }
            if (!@rename($temporary, $directory.'/'.$index.'.part')) {
                throw new Refused('The chunk could not be stored.');
            }
        } catch (\Throwable $e) {
            @unlink($temporary);
            throw $e;
        }
        @touch($directory.'/'.self::META, $this->clock->now()->getTimestamp());

        return $this->state($id, $meta);
    }

    /**
     * Assembles the chunks into the file and puts it in place.
     *
     * @throws NotFound
     * @throws Refused  chunks are missing (422), another request is completing it (423), the name is taken (409)
     */
    public function complete(string $project, string $id): UploadResult
    {
        $meta = $this->load($project, $id);
        $directory = $this->paths->uploadDir($id);

        $lock = @fopen($directory.'/'.self::LOCK, 'c');
        if (false === $lock) {
            throw new NotFound('That upload does not exist.');
        }
        if (!flock($lock, \LOCK_EX | \LOCK_NB)) {
            fclose($lock);
            throw new Refused('That upload is being completed by another request.', self::BUSY);
        }

        try {
            // A request that held the lock before us may have finished: the upload is gone then.
            if (!is_file($directory.'/'.self::META)) {
                throw new NotFound('That upload does not exist.');
            }
            $missing = $this->missing($id, $meta);
            if ([] !== $missing) {
                throw new Refused(\sprintf('The upload is not complete: chunk %s %s missing.', implode(', ', \array_slice($missing, 0, 10)).(\count($missing) > 10 ? ' and more' : ''), 1 === \count($missing) ? 'is' : 'are'), self::INCOMPLETE);
            }

            [$targetDirectory, $target] = $this->store->prepareTarget($project, $meta['dir'], $meta['name'], $meta['overwrite']);
            $temporary = $targetDirectory.'/'.AssetPath::TEMP_PREFIX.$id.'.tmp';

            try {
                $sha256 = $this->assemble($directory, $meta, $temporary);
                $this->store->publish($temporary, $target, $meta['overwrite']);
            } catch (\Throwable $e) {
                @unlink($temporary);
                if ($this->parts($id) !== range(0, $meta['chunks'] - 1)) {
                    // Assembling consumes the parts: with some gone there is nothing left to resume from.
                    $this->remove($directory);
                }
                throw $e instanceof Refused || $e instanceof ValidationFailed ? $e : new Refused('Assembling the file failed: '.$e->getMessage());
            }

            $this->remove($directory);

            return new UploadResult('' === $meta['dir'] ? $meta['name'] : $meta['dir'].'/'.$meta['name'], $meta['name'], $meta['size'], $sha256);
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Throws away an upload and its chunks.
     *
     * @throws NotFound
     */
    public function abort(string $project, string $id): void
    {
        $this->load($project, $id);
        $this->remove($this->paths->uploadDir($id));
    }

    /**
     * Removes the uploads nobody touched for ABANDON_AFTER seconds.
     *
     * @return array{int, int} [uploads removed, bytes freed]
     */
    public function cleanupUploads(): array
    {
        $root = $this->paths->uploadsDir();
        $entries = is_dir($root) ? @scandir($root) : false;
        if (false === $entries) {
            return [0, 0];
        }
        $removed = $bytes = 0;
        $now = $this->clock->now()->getTimestamp();
        foreach ($entries as $entry) {
            if (1 !== preg_match('/^[0-9a-f]{32}$/D', $entry)) {
                continue;
            }
            $directory = $root.'/'.$entry;
            $stat = @lstat($directory);
            if (false === $stat || ($stat['mode'] & 0o170000) !== 0o040000) {
                continue;
            }
            $meta = @filemtime($directory.'/'.self::META);
            $touched = false === $meta ? $stat['mtime'] : $meta;
            if ($now - $touched > self::ABANDON_AFTER) {
                $bytes += $this->remove($directory);
                ++$removed;
            }
        }

        return [$removed, $bytes];
    }

    /**
     * `fleet asset:gc`: abandoned uploads, plus the hidden temporary files an assembly that was killed left next to
     * its target (older than ABANDON_AFTER too).
     */
    public function gc(): GcResult
    {
        [$uploads, $bytes] = $this->cleanupUploads();
        $temporary = 0;
        $root = $this->paths->home().'/assets';
        if (is_dir($root) && !is_link($root)) {
            $now = $this->clock->now()->getTimestamp();
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            foreach ($iterator as $file) {
                \assert($file instanceof \SplFileInfo);
                // RecursiveDirectoryIterator does not descend into symbolic links; a link itself is never touched.
                if ($file->isLink() || !$file->isFile() || !str_starts_with($file->getFilename(), AssetPath::TEMP_PREFIX) || !str_ends_with($file->getFilename(), '.tmp')) {
                    continue;
                }
                if ($now - $file->getMTime() > self::ABANDON_AFTER) {
                    $bytes += $file->getSize();
                    if (@unlink($file->getPathname())) {
                        ++$temporary;
                    }
                }
            }
        }

        return new GcResult($uploads, $temporary, $bytes);
    }

    /**
     * Concatenates the parts into $temporary, deleting each one once it is copied. Returns the sha256 hex digest.
     *
     * @param Meta $meta
     *
     * @throws \RuntimeException
     */
    private function assemble(string $directory, array $meta, string $temporary): string
    {
        @unlink($temporary);
        $out = @fopen($temporary, 'x');
        if (false === $out) {
            throw new \RuntimeException('the temporary file could not be created');
        }
        @chmod($temporary, 0o600);
        $hash = hash_init('sha256');
        $written = 0;
        try {
            for ($index = 0; $index < $meta['chunks']; ++$index) {
                $part = $directory.'/'.$index.'.part';
                $in = @fopen($part, 'r');
                if (false === $in) {
                    throw new \RuntimeException('a chunk could not be read');
                }
                while (!feof($in)) {
                    $block = fread($in, self::BLOCK);
                    if (false === $block) {
                        fclose($in);
                        throw new \RuntimeException('a chunk could not be read');
                    }
                    if ('' === $block) {
                        break;
                    }
                    hash_update($hash, $block);
                    self::writeAll($out, $block);
                    $written += \strlen($block);
                }
                fclose($in);
                @unlink($part);
            }
            if ($written !== $meta['size']) {
                throw new \RuntimeException('the size of the assembled file does not match');
            }
            fflush($out);
            if (\function_exists('fsync')) {
                fsync($out);
            }
        } finally {
            fclose($out);
        }

        return hash_final($hash);
    }

    /**
     * @param Meta $meta
     *
     * @return list<int>
     */
    private function missing(string $id, array $meta): array
    {
        $have = array_flip($this->parts($id));
        $directory = $this->paths->uploadDir($id);
        $missing = [];
        for ($index = 0; $index < $meta['chunks']; ++$index) {
            $size = isset($have[$index]) ? @filesize($directory.'/'.$index.'.part') : false;
            if ($size !== $this->chunkLength($meta, $index)) {
                $missing[] = $index;
            }
        }

        return $missing;
    }

    /**
     * @return list<int> the indexes of the chunks that are stored, ascending
     */
    private function parts(string $id): array
    {
        $names = @scandir($this->paths->uploadDir($id));
        $parts = [];
        foreach (false === $names ? [] : $names as $name) {
            if (1 === preg_match('/^(\d{1,5})\.part$/D', $name, $match)) {
                $parts[] = (int) $match[1];
            }
        }
        sort($parts);

        return $parts;
    }

    /**
     * @param Meta $meta
     */
    private function chunkLength(array $meta, int $index): int
    {
        return $index < $meta['chunks'] - 1 ? $meta['chunkSize'] : $meta['size'] - $meta['chunkSize'] * ($meta['chunks'] - 1);
    }

    /**
     * @param Meta $meta
     */
    private function state(string $id, array $meta): UploadState
    {
        return new UploadState($id, $meta['project'], $meta['dir'], $meta['name'], $meta['size'], $meta['chunkSize'], $meta['chunks'], $this->parts($id));
    }

    /**
     * @return Meta
     *
     * @throws NotFound the upload does not exist, is another project's, or was abandoned
     */
    private function load(string $project, string $id): array
    {
        if (1 !== preg_match('/^[0-9a-f]{32}$/D', $id)) {
            throw new NotFound('That upload does not exist.');
        }
        $meta = $this->readMeta($id);
        if (null === $meta || $meta['project'] !== $project) {
            throw new NotFound('That upload does not exist.');
        }
        $touched = @filemtime($this->paths->uploadDir($id).'/'.self::META);
        if (false !== $touched && $this->clock->now()->getTimestamp() - $touched > self::ABANDON_AFTER) {
            $this->remove($this->paths->uploadDir($id));
            throw new NotFound('That upload has expired. Start it again.');
        }

        return $meta;
    }

    /**
     * @return Meta|null
     */
    private function readMeta(string $id): ?array
    {
        $json = @file_get_contents($this->paths->uploadDir($id).'/'.self::META);
        if (false === $json) {
            return null;
        }
        $data = json_decode($json, true);
        if (!\is_array($data)
            || !\is_string($data['project'] ?? null) || !\is_string($data['dir'] ?? null) || !\is_string($data['name'] ?? null)
            || !\is_int($data['size'] ?? null) || !\is_int($data['chunkSize'] ?? null) || !\is_int($data['chunks'] ?? null)
            || !\is_string($data['fingerprint'] ?? null) || !\is_bool($data['overwrite'] ?? null)
            || $data['size'] < 1 || $data['chunkSize'] < 1 || $data['chunks'] < 1 || $data['chunks'] > self::MAX_CHUNKS) {
            return null;
        }

        return [
            'project' => $data['project'], 'dir' => $data['dir'], 'name' => $data['name'], 'size' => $data['size'],
            'chunkSize' => $data['chunkSize'], 'chunks' => $data['chunks'], 'fingerprint' => $data['fingerprint'], 'overwrite' => $data['overwrite'],
        ];
    }

    /**
     * @return \Generator<string, Meta>
     */
    private function staged(): \Generator
    {
        $names = @scandir($this->paths->uploadsDir());
        foreach (false === $names ? [] : $names as $id) {
            if (1 !== preg_match('/^[0-9a-f]{32}$/D', $id)) {
                continue;
            }
            $meta = $this->readMeta($id);
            if (null !== $meta) {
                yield $id => $meta;
            }
        }
    }

    /**
     * @param Meta $meta
     */
    private function writeMeta(string $id, array $meta): void
    {
        $file = $this->paths->uploadDir($id).'/'.self::META;
        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (false === @file_put_contents($temporary, json_encode($meta, \JSON_THROW_ON_ERROR)) || !@rename($temporary, $file)) {
            @unlink($temporary);
            throw new Refused('The upload could not be recorded.');
        }
        @chmod($file, 0o600);
        @touch($file, $this->clock->now()->getTimestamp());
    }

    private function ensureUploadsDirectory(): void
    {
        $directory = $this->paths->uploadsDir();
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new Refused('The upload area could not be created.');
        }
    }

    /**
     * Deletes an upload's directory: its files, never anything it links to. Returns the bytes freed.
     */
    private function remove(string $directory): int
    {
        $bytes = 0;
        foreach (@scandir($directory) ?: [] as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $path = $directory.'/'.$name;
            $size = is_link($path) ? 0 : (int) @filesize($path);
            if (@unlink($path)) {
                $bytes += $size;
            }
        }
        @rmdir($directory);

        return $bytes;
    }

    /**
     * @param resource $handle
     *
     * @throws \RuntimeException
     */
    private static function writeAll(mixed $handle, string $data): void
    {
        $length = \strlen($data);
        $done = 0;
        while ($done < $length) {
            $n = @fwrite($handle, substr($data, $done));
            if (false === $n || 0 === $n) {
                throw new \RuntimeException('writing failed (disk full?)');
            }
            $done += $n;
        }
    }

    /**
     * @return resource
     */
    private static function memory(string $data): mixed
    {
        $handle = fopen('php://memory', 'r+');
        \assert(false !== $handle);
        fwrite($handle, $data);
        rewind($handle);

        return $handle;
    }
}
