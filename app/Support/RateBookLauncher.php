<?php

namespace App\Support;

/**
 * Starts `php artisan rate-book:sync` as a detached background process, so "Sync now" works even
 * where no scheduler is running (the run takes ~20-30 minutes — far too long for a web request).
 * Only the Rate Book command is started; nothing else the scheduler would run. If a scheduler
 * does run as well, SyncRateBook's lock lets just one of them do the work.
 */
class RateBookLauncher
{
    public function launch(): void
    {
        $php = self::phpBinary();
        $artisan = base_path('artisan');

        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start "" /B "'.$php.'" "'.$artisan.'" rate-book:sync > NUL 2>&1', 'r'));

            return;
        }

        exec(escapeshellarg($php).' '.escapeshellarg($artisan).' rate-book:sync > /dev/null 2>&1 &');
    }

    /**
     * The CLI php: RATE_BOOK_PHP_BINARY if set (needed on PHP-FPM hosts like Plesk, where PHP_BINARY
     * is the fpm daemon and `php` on PATH may be a different PHP version), else this process's own.
     */
    private static function phpBinary(): string
    {
        $configured = config('services.rate_book.php_binary');
        if ($configured) {
            return $configured;
        }

        return PHP_BINARY && ! str_contains(strtolower(basename(PHP_BINARY)), 'fpm') ? PHP_BINARY : 'php';
    }
}
