<?php

namespace InfoPixel\ProjectSnapshot\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class SnapshotCommand extends Command
{
    protected $signature = 'snapshot {--out= : Output file path override}';

    protected $description = 'Export a project snapshot (tree + code bundle) into a single markdown file';

    public function handle(): int
    {
        try {
            // Safety: if someone *did* ship this to prod with dev deps installed, refuse.
            if (app()->environment('production')) {
                $this->error('❌ Snapshot is disabled in production environment.');
                return self::FAILURE;
            }

            $cfg = config('project-snapshot', []);
            $outName = $this->option('out') ?: ($cfg['output'] ?? 'project.snapshot.md');

            $outputPath  = storage_path('app/' . ltrim($outName, '/'));
            $projectRoot = base_path();

            $include      = (array)($cfg['include'] ?? ['app', 'routes', 'config', 'database', 'resources']);
            $exclude      = (array)($cfg['exclude'] ?? ['vendor', 'node_modules', 'storage', 'bootstrap/cache', '.git', '.idea', '.vscode']);
            $neverInclude = (array)($cfg['never_include'] ?? ['.env', '.env.*', 'oauth-private.key', 'oauth-public.key']);
            $allowedExt   = (array)($cfg['allowed_extensions'] ?? ['php','js','ts','css','scss','json','md','yml','yaml']);

            $scrubEnabled      = (bool) data_get($cfg, 'scrub.enabled', true);
            $redaction         = (string) data_get($cfg, 'scrub.redaction', '***REDACTED***');
            $scrubPatterns     = (array) data_get($cfg, 'scrub.patterns', []);
            $scrubKeyAllowlist = (array) data_get($cfg, 'scrub.key_allowlist', []);

            $files = $this->collectFiles($projectRoot, $include, $exclude, $neverInclude, $allowedExt);

            if (empty($files)) {
                $this->warn('No files matched your include/exclude rules.');
                return self::SUCCESS;
            }

            File::ensureDirectoryExists(dirname($outputPath));

            $progress = $this->output->createProgressBar(count($files));
            $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
            $progress->start();

            $generatedAtIso   = now()->toIso8601String();
            $generatedAtHuman = now()->format('D, j M Y \a\t H:i (T)'); // e.g. Sun, 14 Dec 2025 at 18:52 (UTC)

            $projectName = (string) config('app.name', basename($projectRoot));

            // Proper hierarchical tree
            $treeLines = $this->buildFileTreeLines($files);

            $totalBytes     = 0;
            $bundleSections = [];

            foreach ($files as $relPath) {
                $absPath = $projectRoot . DIRECTORY_SEPARATOR . $relPath;

                $content = File::get($absPath);
                $totalBytes += strlen($content);

                if ($scrubEnabled) {
                    $content = $this->scrubSecrets($content, $redaction, $scrubPatterns, $scrubKeyAllowlist);
                }

                $bundleSections[] =
                    "## FILE: {$relPath}\n" .
                    "```" . $this->guessFenceLanguage($relPath) . "\n" .
                    rtrim($content) . "\n" .
                    "```\n";

                $progress->advance();
            }

            $progress->finish();
            $this->newLine(2);

            $metadataBlock = $this->buildMetadataBlock(
                projectName: $projectName,
                generatedAtIso: $generatedAtIso,
                generatedAtHuman: $generatedAtHuman,
                environment: app()->environment(),
                fileCount: count($files),
                totalBytes: $totalBytes,
                include: $include,
                exclude: $exclude,
                neverInclude: $neverInclude,
                allowedExt: $allowedExt,
                scrubEnabled: $scrubEnabled,
                generator: (string) data_get($cfg, 'metadata.generator', 'infopixel/laravel-project-snapshot')
            );

            $markdown =
                "# Project Snapshot\n\n" .
                $metadataBlock . "\n\n" .
                "## File Tree\n" .
                "```text\n" . implode("\n", $treeLines) . "\n```\n\n" .
                implode("\n", $bundleSections);

            File::put($outputPath, $markdown);

            $this->info("✅ Snapshot complete. Wrote " . count($files) . " files to:\n{$outputPath}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("❌ Snapshot failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    private function collectFiles(
        string $projectRoot,
        array $include,
        array $exclude,
        array $neverInclude,
        array $allowedExt
    ): array {
        $results = [];

        foreach ($include as $path) {
            $abs = $projectRoot . DIRECTORY_SEPARATOR . $path;
            if (!File::exists($abs)) {
                continue;
            }

            if (File::isFile($abs)) {
                $rel = $this->normalizeRelPath($projectRoot, $abs);
                if ($this->shouldIncludeFile($rel, $exclude, $neverInclude, $allowedExt)) {
                    $results[] = $rel;
                }
                continue;
            }

            foreach (File::allFiles($abs) as $file) {
                $rel = $this->normalizeRelPath($projectRoot, $file->getPathname());
                if ($this->shouldIncludeFile($rel, $exclude, $neverInclude, $allowedExt)) {
                    $results[] = $rel;
                }
            }
        }

        $results = array_values(array_unique($results));
        sort($results);

        return $results;
    }

    private function shouldIncludeFile(string $relPath, array $exclude, array $neverInclude, array $allowedExt): bool
    {
        $relPath = str_replace('\\', '/', $relPath);

        // Excluded folders
        foreach ($exclude as $ex) {
            $ex = trim(str_replace('\\', '/', $ex), '/');
            if ($ex !== '' && (Str::startsWith($relPath, $ex . '/') || $relPath === $ex)) {
                return false;
            }
        }

        // Never include exact or wildcard patterns (by basename)
        foreach ($neverInclude as $pattern) {
            $pattern = str_replace('\\', '/', $pattern);

            if (Str::contains($pattern, '*')) {
                $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
                if (preg_match($regex, basename($relPath))) {
                    return false;
                }
            } else {
                if (basename($relPath) === basename($pattern) || $relPath === ltrim($pattern, '/')) {
                    return false;
                }
            }
        }

        // Extension allowlist
        $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowedExt, true)) {
            return false;
        }

        return true;
    }

    /**
     * Build a proper hierarchical tree like:
     * - app/
     *   - Models/
     *     - User.php
     */
    private function buildFileTreeLines(array $files): array
    {
        // Build a nested tree structure
        $tree = [];

        foreach ($files as $relPath) {
            $relPath = str_replace('\\', '/', $relPath);
            $parts   = array_values(array_filter(explode('/', $relPath), fn ($p) => $p !== ''));

            $node =& $tree;
            foreach ($parts as $i => $part) {
                $isFile = ($i === count($parts) - 1);

                if ($isFile) {
                    $node['__files'][] = $part;
                } else {
                    $node['__dirs'][$part] ??= [];
                    $node =& $node['__dirs'][$part];
                }
            }
        }

        // Render tree
        $lines = [];
        $this->renderTreeNode($tree, $lines, 0);
        return $lines;
    }

    private function renderTreeNode(array $node, array &$lines, int $depth): void
    {
        $indent = str_repeat('  ', $depth);

        $dirs  = $node['__dirs'] ?? [];
        $files = $node['__files'] ?? [];

        ksort($dirs);
        sort($files);

        foreach ($dirs as $dirName => $child) {
            $lines[] = $indent . '- ' . $dirName . '/';
            $this->renderTreeNode($child, $lines, $depth + 1);
        }

        foreach ($files as $fileName) {
            $lines[] = $indent . '- ' . $fileName;
        }
    }

    private function buildMetadataBlock(
        string $projectName,
        string $generatedAtIso,
        string $generatedAtHuman,
        string $environment,
        int $fileCount,
        int $totalBytes,
        array $include,
        array $exclude,
        array $neverInclude,
        array $allowedExt,
        bool $scrubEnabled,
        string $generator
    ): string {
        $bytesKb = round($totalBytes / 1024, 2);

        return
            "## Snapshot Metadata\n" .
            "- **Project:** {$projectName}\n" .
            "- **Generated:** {$generatedAtHuman}\n" .
            "- **Generated (ISO):** {$generatedAtIso}\n" .
            "- **Generator:** {$generator}\n" .
            "- **Environment:** {$environment}\n" .
            "- **Files included:** {$fileCount}\n" .
            "- **Approx size (raw):** {$bytesKb} KB\n" .
            "- **Include roots:** " . implode(', ', $include) . "\n" .
            "- **Excluded paths:** " . implode(', ', $exclude) . "\n" .
            "- **Excluded files (globs):** " . implode(', ', $neverInclude) . "\n" .
            "- **Allowed extensions:** " . implode(', ', $allowedExt) . "\n" .
            "- **Secret scrubbing:** " . ($scrubEnabled ? 'enabled' : 'disabled') . "\n\n" .
            "### Notes\n" .
            "- **.env and other sensitive key files are excluded by pattern** (see *Excluded files* above).\n" .
            "- **Secrets are also scrubbed inside included files** (JWT/Bearer/AWS/Stripe/common secret assignments).";
    }

    private function scrubSecrets(string $content, string $redaction, array $patterns, array $keyAllowlist): string
    {
        // Regex patterns (token/JWT/bearer/etc)
        foreach ($patterns as $pattern) {
            $content = preg_replace($pattern, $redaction, $content) ?? $content;
        }

        // KEY=VALUE or "KEY": "VALUE" lines for specific allowlisted keys
        if (!empty($keyAllowlist)) {
            $keyRegex = implode('|', array_map(fn ($k) => preg_quote($k, '/'), $keyAllowlist));

            // .env style: KEY=VALUE
            $content = preg_replace(
                "/^({$keyRegex})\\s*=\\s*.*$/mi",
                "$1={$redaction}",
                $content
            ) ?? $content;

            // JSON-ish: "KEY": "VALUE"
            $content = preg_replace(
                "/(\"(?:{$keyRegex})\"\\s*:\\s*\")([^\"]+)(\")/mi",
                "$1{$redaction}$3",
                $content
            ) ?? $content;
        }

        return $content;
    }

    private function guessFenceLanguage(string $relPath): string
    {
        $lower = strtolower($relPath);

        // Blade files are still PHP-ish for readability
        if (Str::endsWith($lower, '.blade.php')) {
            return 'php';
        }

        return match (strtolower(pathinfo($relPath, PATHINFO_EXTENSION))) {
            'php' => 'php',
            'js' => 'javascript',
            'ts' => 'typescript',
            'css' => 'css',
            'scss' => 'scss',
            'json' => 'json',
            'yml', 'yaml' => 'yaml',
            'md' => 'markdown',
            default => '',
        };
    }

    private function normalizeRelPath(string $base, string $path): string
    {
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $path = str_replace('\\', '/', $path);

        if (Str::startsWith($path, $base . '/')) {
            return substr($path, strlen($base) + 1);
        }

        return ltrim($path, '/');
    }
}
