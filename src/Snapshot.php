<?php

namespace Spatie\DbSnapshots;

use Carbon\Carbon;
use Illuminate\Filesystem\FilesystemAdapter as Disk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Spatie\DbSnapshots\Events\DeletedSnapshot;
use Spatie\DbSnapshots\Events\DeletingSnapshot;
use Spatie\DbSnapshots\Events\LoadedSnapshot;
use Spatie\DbSnapshots\Events\LoadingSnapshot;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class Snapshot
{
    public Disk $disk;

    public string $fileName;

    public string $name;

    public ?string $compressionExtension = null;

    private bool $useStream = false;

    public const STREAM_BUFFER_SIZE = 16384;

    public function __construct(Disk $disk, string $fileName)
    {
        $this->disk = $disk;

        $this->fileName = $fileName;

        $pathinfo = pathinfo($fileName);

        if ($pathinfo['extension'] === 'gz') {
            $this->compressionExtension = $pathinfo['extension'];
            $fileName = $pathinfo['filename'];
        }

        $this->name = pathinfo($fileName, PATHINFO_FILENAME);
    }

    public function useStream()
    {
        $this->useStream = true;

        return $this;
    }

    public function load(string $connectionName = null, bool $dropTables = true): void
    {
        event(new LoadingSnapshot($this));

        if ($connectionName !== null) {
            DB::setDefaultConnection($connectionName);
        }

        if ($dropTables) {
            $this->dropAllCurrentTables();
        }

        $this->useStream ? $this->loadStream($connectionName) : $this->loadAsync($connectionName);

        event(new LoadedSnapshot($this));
    }

    protected function loadAsync(string $connectionName = null)
    {
        $dbDumpContents = $this->disk->get($this->fileName);

        if ($this->compressionExtension === 'gz') {
            $dbDumpContents = gzdecode($dbDumpContents);
        }

        DB::connection($connectionName)->unprepared($dbDumpContents);
    }

    protected function isASqlComment(string $line): bool
    {
        return substr($line, 0, 2) === '--';
    }

    protected function shouldIgnoreLine(string $line): bool
    {
        $line = trim($line);

        return empty($line) || $this->isASqlComment($line);
    }

    protected function loadStream(string $connectionName = null)
    {
        $directory = (new TemporaryDirectory(config('db-snapshots.temporary_directory_path')))->create();

        config([
            'filesystems.disks.' . self::class => [
                'driver' => 'local',
                'root' => $directory->path(),
                'throw' => false,
            ]
        ]);

        LazyCollection::make(function () {
            Storage::disk(self::class)->writeStream($this->fileName, $this->disk->readStream($this->fileName));

            $stream = $this->compressionExtension === 'gz'
                ? gzopen(Storage::disk(self::class)->path($this->fileName), 'r')
                : Storage::disk(self::class)->readStream($this->fileName);

            $statement = '';
            while (! feof($stream)) {
                $chunk = $this->compressionExtension === 'gz'
                        ? gzgets($stream, self::STREAM_BUFFER_SIZE)
                        : fread($stream, self::STREAM_BUFFER_SIZE);

                $lines = explode("\n", $chunk);
                foreach ($lines as $idx => $line) {
                    if ($this->shouldIgnoreLine($line)) {
                        continue;
                    }

                    $statement .= $line;

                    // Carry-over the last line to the next chunk since it
                    // is possible that this chunk finished mid-line right on
                    // a semi-colon.
                    if (count($lines) == $idx + 1) {
                        break;
                    }

                    if (substr(trim($statement), -1, 1) === ';') {
                        yield $statement;
                        $statement = '';
                    }
                }
            }

            if (substr(trim($statement), -1, 1) === ';') {
                yield $statement;
            }
        })->each(function (string $statement) use ($connectionName) {
            DB::connection($connectionName)->unprepared($statement);
        })->after(function () use ($directory) {
           $directory->delete();
        });
    }

    public function delete(): void
    {
        event(new DeletingSnapshot($this));

        $this->disk->delete($this->fileName);

        event(new DeletedSnapshot($this->fileName, $this->disk));
    }

    public function size(): int
    {
        return $this->disk->size($this->fileName);
    }

    public function createdAt(): Carbon
    {
        return Carbon::createFromTimestamp($this->disk->lastModified($this->fileName));
    }

    protected function dropAllCurrentTables()
    {
        DB::connection(DB::getDefaultConnection())
            ->getSchemaBuilder()
            ->dropAllTables();

        DB::reconnect();
    }
}
