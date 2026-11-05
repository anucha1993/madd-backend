<?php

namespace App\Support;

/**
 * Read-only view of the program for the Administrator AI assistant: list / read / search source
 * files of the backend (and the frontend when it sits next to it) and the tail of the Laravel log.
 * Nothing here writes. Secrets stay out: .env*, keys, vendor / node_modules / storage are not
 * listed or readable, and token-looking strings in the log are blanked.
 */
class AssistantCode
{
    public const MAX_READ_LINES = 400;

    private const MAX_SEARCH_HITS = 80;

    private const EXTENSIONS = ['php', 'ts', 'tsx', 'js', 'mjs', 'json', 'md', 'xml', 'css', 'sql', 'yml', 'yaml'];

    private const SKIP_DIRS = ['vendor', 'node_modules', '.git', '.next', 'storage', 'bootstrap/cache', 'public/build', 'coverage'];

    /** Root name => absolute path, for the roots that exist here. */
    public static function roots(): array
    {
        return array_filter([
            'backend' => base_path(),
            'frontend' => realpath(base_path('../madd-frontend')) ?: null,
        ]);
    }

    /** @return list<string> "backend/app/Services/…" paths under $dir matching $pattern (glob-ish, e.g. *Rate*). */
    public static function listFiles(string $dir, string $pattern = '*'): array
    {
        [$root, $abs] = self::resolve($dir === '' ? 'backend' : $dir, true);
        $files = [];
        foreach (self::walk($abs) as $file) {
            if (fnmatch($pattern, basename($file), FNM_CASEFOLD)) {
                $files[] = self::display($root, $file);
                if (count($files) >= 300) {
                    break;
                }
            }
        }

        return $files;
    }

    /** @return array{path: string, from: int, to: int, total_lines: int, content: string} */
    public static function read(string $path, int $from = 1, ?int $to = null): array
    {
        [$root, $abs] = self::resolve($path, false);
        $lines = file($abs, FILE_IGNORE_NEW_LINES) ?: [];
        $from = max(1, $from);
        $to = min(count($lines), $to ?? $from + self::MAX_READ_LINES - 1, $from + self::MAX_READ_LINES - 1);
        $out = [];
        for ($i = $from; $i <= $to; $i++) {
            $out[] = $i.': '.$lines[$i - 1];
        }

        return ['path' => self::display($root, $abs), 'from' => $from, 'to' => $to, 'total_lines' => count($lines), 'content' => implode("\n", $out)];
    }

    /** @return list<string> "path:line: text" for a regex (case-insensitive) across $dir. */
    public static function search(string $regex, string $dir = ''): array
    {
        $pattern = '~'.str_replace('~', '\~', $regex).'~i';
        if (@preg_match($pattern, '') === false) {
            throw new \InvalidArgumentException('regex ไม่ถูกต้อง');
        }
        $hits = [];
        foreach ($dir === '' ? array_keys(self::roots()) : [$dir] as $start) {
            [$root, $abs] = self::resolve($start, true);
            foreach (self::walk($abs) as $file) {
                foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $n => $line) {
                    if (preg_match($pattern, $line)) {
                        $hits[] = self::display($root, $file).':'.($n + 1).': '.mb_substr(trim($line), 0, 200);
                        if (count($hits) >= self::MAX_SEARCH_HITS) {
                            return $hits;
                        }
                    }
                }
            }
        }

        return $hits;
    }

    /** The last $lines lines of storage/logs/laravel.log, with tokens / keys blanked. */
    public static function logTail(int $lines = 150, string $contains = ''): string
    {
        $file = storage_path('logs/laravel.log');
        if (! is_file($file)) {
            return '(ไม่มีไฟล์ log)';
        }
        $handle = fopen($file, 'r');
        fseek($handle, max(0, filesize($file) - 2_000_000)); // the last ~2 MB is plenty
        $all = explode("\n", stream_get_contents($handle));
        fclose($handle);
        if ($contains !== '') {
            $all = array_values(array_filter($all, fn ($l) => stripos($l, $contains) !== false));
        }
        $text = implode("\n", array_slice($all, -min(500, max(1, $lines))));

        return preg_replace(['/(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '/\b(sk|dca_sk|pk)[-_][A-Za-z0-9_-]{8,}/', '/("?(?:password|secret|token|api_?key|client_secret)"?\s*[:=]\s*)"?[^",\s}]+/i'], ['$1 [hidden]', '[hidden]', '$1[hidden]'], $text);
    }

    /** [root name, absolute path] of a "backend/…" / "frontend/…" path, refusing anything outside or secret. */
    private static function resolve(string $path, bool $dir): array
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        [$rootName, $rest] = array_pad(explode('/', $path, 2), 2, '');
        $roots = self::roots();
        if (! isset($roots[$rootName])) {
            [$rootName, $rest] = ['backend', $path]; // a bare "app/…" means the backend
        }
        $abs = realpath($roots[$rootName].($rest !== '' ? '/'.$rest : ''));
        $base = realpath($roots[$rootName]);
        if (! $abs || ! str_starts_with(str_replace('\\', '/', $abs), str_replace('\\', '/', $base)) || ($dir ? ! is_dir($abs) : ! is_file($abs))) {
            throw new \InvalidArgumentException("ไม่พบ {$path}");
        }
        $relative = ltrim(substr(str_replace('\\', '/', $abs), strlen(str_replace('\\', '/', $base))), '/');
        if (self::isHidden($relative) || (! $dir && ! in_array(strtolower(pathinfo($abs, PATHINFO_EXTENSION)), self::EXTENSIONS, true))) {
            throw new \InvalidArgumentException("{$path} เปิดไม่ได้ (ไฟล์ตั้งค่าลับ / ไม่ใช่ source code)");
        }

        return [$rootName, $abs];
    }

    private static function isHidden(string $relative): bool
    {
        foreach (self::SKIP_DIRS as $skip) {
            if ($relative === $skip || str_starts_with($relative, $skip.'/') || str_contains($relative, '/'.$skip.'/')) {
                return true;
            }
        }

        return (bool) preg_match('~(^|/)(\.env[^/]*|auth\.json|[^/]*\.(key|pem))$~i', $relative);
    }

    /** @return \Generator<string> source files under $abs, skipping vendor / secrets. */
    private static function walk(string $abs): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
            function (\SplFileInfo $f) {
                $name = $f->getFilename();

                return $f->isDir() ? ! in_array($name, ['vendor', 'node_modules', '.git', '.next', 'storage', 'cache', 'build', 'coverage'], true)
                    : in_array(strtolower($f->getExtension()), self::EXTENSIONS, true) && ! preg_match('~^(\.env|auth\.json)~i', $name);
            },
        ));
        foreach ($iterator as $file) {
            yield str_replace('\\', '/', $file->getPathname());
        }
    }

    private static function display(string $root, string $abs): string
    {
        $base = str_replace('\\', '/', realpath(self::roots()[$root]));

        return $root.'/'.ltrim(substr(str_replace('\\', '/', $abs), strlen($base)), '/');
    }
}
