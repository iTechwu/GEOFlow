<?php

namespace App\Services\Admin\SiteThemeReplication;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ThemeReplicationStorageLock
{
    public function __construct(
        private readonly ThemeReplicationStorageGuard $storageGuard,
        private readonly ThemeReplicationPackagePathGuard $pathGuard,
    ) {}

    public function run(int $replicationId, Closure $operation): mixed
    {
        $replicationId = $this->pathGuard->positiveInteger($replicationId);
        $timeoutMilliseconds = $this->timeoutMilliseconds();
        $distributedLock = Cache::lock(
            'geoflow:theme-replication-storage:'.$replicationId,
            max(2, (int) ceil($timeoutMilliseconds / 1000) + 1),
        );
        if (! $this->acquireDistributedLock($distributedLock, $timeoutMilliseconds)) {
            throw new RuntimeException(__('admin.theme_replication.error.package_create_failed'));
        }

        try {
            $handle = $this->acquire($replicationId, $timeoutMilliseconds);

            try {
                return $operation();
            } finally {
                @flock($handle, LOCK_UN);
                fclose($handle);
            }
        } finally {
            $distributedLock->release();
        }
    }

    /** @return resource */
    private function acquire(int $replicationId, int $timeoutMilliseconds)
    {
        $disk = Storage::disk('local');
        $lockDirectory = 'geoflow-theme-replication-package-locks';
        $lockDirectoryReal = $this->storageGuard->ensureStorageDirectory($lockDirectory);
        $lockAbsolutePath = $disk->path($lockDirectory.'/'.$replicationId.'.lock');
        if (is_link($lockAbsolutePath)) {
            $this->reject();
        }

        $handle = @fopen($lockAbsolutePath, 'c+b');
        if ($handle === false) {
            throw new RuntimeException(__('admin.theme_replication.error.package_create_failed'));
        }
        try {
            $this->assertLockIdentity($handle, $lockAbsolutePath, $lockDirectoryReal);
        } catch (RuntimeException $exception) {
            fclose($handle);
            throw $exception;
        }

        $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
        do {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                try {
                    $this->assertLockIdentity($handle, $lockAbsolutePath, $lockDirectoryReal);
                } catch (RuntimeException $exception) {
                    @flock($handle, LOCK_UN);
                    fclose($handle);
                    throw $exception;
                }

                return $handle;
            }
            $remainingMicroseconds = intdiv(max(0, $deadline - hrtime(true)), 1000);
            if ($remainingMicroseconds > 0) {
                usleep(min(10_000, $remainingMicroseconds));
            }
        } while (hrtime(true) < $deadline);

        fclose($handle);
        throw new RuntimeException(__('admin.theme_replication.error.package_create_failed'));
    }

    private function acquireDistributedLock(Lock $lock, int $timeoutMilliseconds): bool
    {
        $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
        do {
            if ($lock->get()) {
                return true;
            }
            $remainingMicroseconds = intdiv(max(0, $deadline - hrtime(true)), 1000);
            if ($remainingMicroseconds > 0) {
                usleep(min(10_000, $remainingMicroseconds));
            }
        } while (hrtime(true) < $deadline);

        return false;
    }

    private function timeoutMilliseconds(): int
    {
        $timeout = $this->pathGuard->positiveInteger(
            config('geoflow.theme_replication_package_lock_timeout_milliseconds')
        );
        if ($timeout > 60_000) {
            $this->reject();
        }

        return $timeout;
    }

    /** @param resource $handle */
    private function assertLockIdentity($handle, string $path, string $directoryReal): void
    {
        $pathStat = @lstat($path);
        $handleStat = fstat($handle);
        $real = realpath($path);
        if (
            $pathStat === false
            || $handleStat === false
            || is_link($path)
            || ($pathStat['mode'] & 0170000) !== 0100000
            || ($handleStat['mode'] & 0170000) !== 0100000
            || $pathStat['dev'] !== $handleStat['dev']
            || $pathStat['ino'] !== $handleStat['ino']
            || ! is_string($real)
            || ! str_starts_with($real, rtrim($directoryReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
        ) {
            $this->reject();
        }
    }

    private function reject(): never
    {
        throw new RuntimeException(__('admin.theme_replication.error.invalid_package_path'));
    }
}
