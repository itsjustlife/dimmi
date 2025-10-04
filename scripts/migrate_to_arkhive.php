<?php
declare(strict_types=1);

/**
 * Migration utility for reorganizing legacy DATA/ and ARKHIVE/ trees into the
 * new topic-aware ARKHIVE layout.
 */

function show_help(): void
{
    $script = basename(__FILE__);
    echo "Usage: php {$script} [--dry-run|--apply] [--help]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --dry-run   Simulate the migration and print planned actions (default).\n";
    echo "  --apply     Execute the migration, write files, and create reports.\n";
    echo "  --help      Display this help text.\n";
}

$options = getopt('', ['dry-run', 'apply', 'help']);

if (isset($options['help'])) {
    show_help();
    exit(0);
}

$modeApply = isset($options['apply']);
$modeDryRun = isset($options['dry-run']) || !$modeApply;

if ($modeApply && $modeDryRun && isset($options['dry-run'])) {
    fwrite(STDERR, "Cannot use --apply and --dry-run simultaneously.\n");
    exit(1);
}

/**
 * Ascends directories from the provided start path to find the project root.
 */
function find_project_root(string $start): ?string
{
    $dir = realpath($start);
    if ($dir === false) {
        return null;
    }

    while ($dir !== '/' && $dir !== '') {
        if (file_exists($dir . '/CLOUD/cloud.php')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    return file_exists($dir . '/CLOUD/cloud.php') ? $dir : null;
}

$projectRoot = find_project_root(__DIR__);
if ($projectRoot === null) {
    fwrite(STDERR, "Unable to locate project root (missing CLOUD/cloud.php).\n");
    exit(1);
}

// Normalise root to remove trailing slash
$projectRoot = rtrim($projectRoot, '/');

$sourceTrees = [
    'DATA' => $projectRoot . '/DATA',
    'ARKHIVE' => $projectRoot . '/ARKHIVE',
];

foreach ($sourceTrees as $label => $path) {
    if (!is_dir($path)) {
        fwrite(STDERR, "Warning: {$label} directory not found at {$path}.\n");
    }
}

/**
 * Recursively gather file paths from a base directory.
 *
 * @return array<int, array{source:string, relative:string, base:string}>
 */
function collect_files(string $base, string $prefix): array
{
    if (!is_dir($base)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $base,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            continue;
        }
        $relative = $prefix . '/' . ltrim(str_replace($base, '', $file->getPathname()), '/');
        $files[] = [
            'source' => $file->getPathname(),
            'relative' => trim($relative, '/'),
            'base' => $prefix,
        ];
    }

    return $files;
}

$allFiles = [];
foreach ($sourceTrees as $prefix => $path) {
    $allFiles = array_merge($allFiles, collect_files($path, $prefix));
}

if (empty($allFiles)) {
    fwrite(STDOUT, "No files discovered for migration.\n");
    exit(0);
}

/**
 * Determine the topic name from a relative path.
 */
function determine_topic(string $relative): string
{
    $parts = explode('/', $relative);
    if (count($parts) <= 1) {
        return 'general';
    }
    $topic = trim($parts[1]);
    if ($topic === '') {
        return 'general';
    }
    return strtolower(preg_replace('~[^a-z0-9]+~i', '-', $topic));
}

/**
 * Determine whether a path should be treated as an asset, note, or structure.
 */
function classify_path(string $relative): string
{
    $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    $textExtensions = ['md', 'markdown', 'mkd', 'html', 'htm', 'txt'];
    $structureExtensions = ['json', 'yaml', 'yml', 'xml'];

    if (in_array($ext, $textExtensions, true)) {
        return 'note';
    }
    if (in_array($ext, $structureExtensions, true)) {
        return 'structure';
    }
    return 'asset';
}

/**
 * Slugify a file or directory name for consistency.
 */
function slugify(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('~[^a-z0-9]+~', '-', $name);
    $name = trim($name, '-');
    return $name !== '' ? $name : 'item';
}

/**
 * Resolve the target path for the migrated file.
 */
function build_target_path(array $fileInfo): array
{
    $relative = $fileInfo['relative'];
    $topic = determine_topic($relative);
    $baseName = pathinfo($relative, PATHINFO_FILENAME);
    $extension = pathinfo($relative, PATHINFO_EXTENSION);
    $classification = classify_path($relative);

    switch ($classification) {
        case 'structure':
            $targetBase = "ARKHIVE/structures/{$topic}";
            $fileName = slugify($baseName);
            $target = $targetBase . '/' . $fileName;
            if ($extension !== '') {
                $target .= '.' . $extension;
            }
            break;
        case 'note':
            $targetBase = "ARKHIVE/notes/{$topic}";
            $noteFolder = slugify($baseName);
            $targetBase .= '/' . $noteFolder;
            $target = $targetBase . '/' . $noteFolder . '.md';
            break;
        default:
            $targetBase = "ARKHIVE/assets/{$topic}";
            $fileName = slugify($baseName);
            $target = $targetBase . '/' . $fileName;
            if ($extension !== '') {
                $target .= '.' . $extension;
            }
            break;
    }

    return [
        'target' => $target,
        'topic' => $topic,
        'classification' => $classification,
    ];
}

/**
 * Ensures unique target file paths by appending numeric suffixes on collisions.
 */
function ensure_unique_target(string $target, array &$used): string
{
    $candidate = $target;
    $counter = 1;
    $extension = pathinfo($target, PATHINFO_EXTENSION);
    $pathWithoutExt = $extension === '' ? $target : substr($target, 0, -strlen($extension) - 1);

    while (isset($used[strtolower($candidate)])) {
        $candidate = sprintf('%s-%d', $pathWithoutExt, $counter);
        if ($extension !== '') {
            $candidate .= '.' . $extension;
        }
        $counter++;
    }

    $used[strtolower($candidate)] = true;
    return $candidate;
}

$usedTargets = [];
$migrationPlan = [];
$renameMap = [];

foreach ($allFiles as $file) {
    $targetInfo = build_target_path($file);
    $resolvedTarget = ensure_unique_target($targetInfo['target'], $usedTargets);
    $migrationPlan[] = array_merge($file, [
        'target' => $resolvedTarget,
        'topic' => $targetInfo['topic'],
        'classification' => $targetInfo['classification'],
    ]);
    $renameMap[$file['relative']] = $resolvedTarget;
}

ksort($renameMap);

$mapsDir = $projectRoot . '/ARKHIVE/_maps';
if (!$modeDryRun && !is_dir($mapsDir)) {
    mkdir($mapsDir, 0777, true);
}

$renameMapPath = $mapsDir . '/rename-map.json';
$renameMapJson = json_encode($renameMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($modeDryRun) {
    fwrite(STDOUT, "Planned rename map:\n{$renameMapJson}\n");
} else {
    file_put_contents($renameMapPath, $renameMapJson . "\n");
    fwrite(STDOUT, "Wrote rename map to {$renameMapPath}\n");
}

/**
 * Normalise path separators and resolve .. segments.
 */
function normalise_path(string $path): string
{
    $parts = [];
    $segments = preg_split('~[\\/]~', $path);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $segment;
    }
    $prefix = str_starts_with($path, '/') ? '/' : '';
    return $prefix . implode('/', $parts);
}

/**
 * Create directory if missing (only in apply mode).
 */
function ensure_directory(string $path, bool $modeDryRun): void
{
    if ($modeDryRun) {
        return;
    }
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

/**
 * Produce relative path from one file to another.
 */
function relative_path(string $from, string $to): string
{
    $from = normalise_path($from);
    $to = normalise_path($to);

    $fromParts = $from === '' ? [] : explode('/', $from);
    $toParts = $to === '' ? [] : explode('/', $to);

    while (!empty($fromParts) && !empty($toParts) && $fromParts[0] === $toParts[0]) {
        array_shift($fromParts);
        array_shift($toParts);
    }

    $up = array_fill(0, max(0, count($fromParts)), '..');
    $down = $toParts;
    return implode('/', array_merge($up, $down));
}

/**
 * Determine whether a link should be skipped from rewriting.
 */
function should_skip_link(string $href): bool
{
    if ($href === '' || $href[0] === '#') {
        return true;
    }
    if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $href)) {
        return true;
    }
    if (str_starts_with(strtolower($href), 'mailto:')) {
        return true;
    }
    return false;
}

/**
 * Rewrite Markdown and HTML links inside a given document.
 *
 * @return array{content:string, issues:array<int,string>}
 */
function rewrite_links(
    string $content,
    string $sourcePath,
    string $targetPath,
    array $renameMap,
    string $projectRoot
): array {
    $issues = [];
    $sourceDir = dirname($sourcePath);
    $targetDir = dirname($targetPath);

    $replaceLink = function (string $href) use (
        $sourceDir,
        $targetDir,
        $renameMap,
        $projectRoot,
        &$issues
    ) {
        if (should_skip_link($href)) {
            return $href;
        }

        $parsed = parse_url($href);
        $path = $parsed['path'] ?? '';
        $anchor = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';

        $resolvedAbsolute = $path;
        if ($path === '') {
            $issues[] = "Empty path in link '{$href}'";
            return $href;
        }

        if ($path[0] === '/') {
            $resolvedAbsolute = $projectRoot . '/' . ltrim($path, '/');
        } else {
            $resolvedAbsolute = normalise_path($sourceDir . '/' . $path);
        }

        $resolvedRelativeKey = null;
        foreach ($renameMap as $from => $to) {
            $absoluteFrom = normalise_path($projectRoot . '/' . $from);
            if ($absoluteFrom === $resolvedAbsolute) {
                $resolvedRelativeKey = $from;
                break;
            }
        }

        if ($resolvedRelativeKey === null) {
            if (!file_exists($resolvedAbsolute)) {
                $issues[] = "Unresolved link target '{$href}'";
            }
            return $href;
        }

        $targetResolved = $renameMap[$resolvedRelativeKey];
        $targetAbsolute = normalise_path($projectRoot . '/' . $targetResolved);
        $newHref = relative_path($targetDir, $targetAbsolute);
        if ($newHref === '') {
            $newHref = './';
        }
        $newHref .= $query . $anchor;
        return $newHref;
    };

    $content = preg_replace_callback(
        '~\[(?<text>[^\]]+)\]\((?<url>[^)]+)\)~',
        function ($matches) use ($replaceLink) {
            $newHref = $replaceLink(trim($matches['url']));
            return '[' . $matches['text'] . '](' . $newHref . ')';
        },
        $content
    );

    $content = preg_replace_callback(
        '~href\s*=\s*"([^"]+)"~i',
        function ($matches) use ($replaceLink) {
            $newHref = $replaceLink(htmlspecialchars_decode($matches[1], ENT_QUOTES));
            return 'href="' . htmlspecialchars($newHref, ENT_QUOTES) . '"';
        },
        $content
    );

    return ['content' => $content, 'issues' => $issues];
}

$unresolvedLinks = [];

foreach ($migrationPlan as $item) {
    $sourcePath = $item['source'];
    $targetRelative = $item['target'];
    $targetPath = $projectRoot . '/' . $targetRelative;
    $targetDir = dirname($targetPath);

    $logPrefix = $modeDryRun ? '[dry-run]' : '[apply]';
    fwrite(STDOUT, sprintf(
        "%s %s => %s\n",
        $logPrefix,
        $item['relative'],
        $targetRelative
    ));

    ensure_directory($targetDir, $modeDryRun);

    if ($modeDryRun) {
        continue;
    }

    $originalContent = file_get_contents($sourcePath);
    if ($originalContent === false) {
        $unresolvedLinks[] = "Failed to read {$sourcePath}";
        continue;
    }

    $processedContent = $originalContent;
    $isText = in_array($item['classification'], ['note', 'structure'], true) || preg_match('~\.(md|markdown|mkd|html|htm|txt|json|yaml|yml|xml)$~i', $sourcePath);

    if ($isText) {
        $rewriteResult = rewrite_links(
            $originalContent,
            $sourcePath,
            $targetPath,
            $renameMap,
            $projectRoot
        );
        $processedContent = $rewriteResult['content'];
        $unresolvedLinks = array_merge($unresolvedLinks, $rewriteResult['issues']);
        $processedContent = preg_replace("~\r\n?|\n~", "\n", $processedContent);
    }

    $bakDir = $targetDir . '/.bak';
    if (!is_dir($bakDir)) {
        mkdir($bakDir, 0777, true);
    }
    $bakPath = $bakDir . '/' . basename($targetPath);
    file_put_contents($bakPath, $originalContent);

    file_put_contents($targetPath, $processedContent, LOCK_EX);
}

if ($modeDryRun) {
    fwrite(STDOUT, "Dry run complete. No files were modified.\n");
    exit(0);
}

/**
 * Perform link verification across migrated Markdown and HTML files.
 */
function verify_links(array $migrationPlan, string $projectRoot): array
{
    $issues = [];
    foreach ($migrationPlan as $item) {
        $targetRelative = $item['target'];
        if (!preg_match('~\.(md|markdown|mkd|html|htm)$~i', $targetRelative)) {
            continue;
        }
        $targetPath = $projectRoot . '/' . $targetRelative;
        if (!file_exists($targetPath)) {
            $issues[] = "Missing migrated file {$targetRelative}";
            continue;
        }
        $content = file_get_contents($targetPath);
        if ($content === false) {
            $issues[] = "Unable to read {$targetRelative}";
            continue;
        }
        $dir = dirname($targetPath);

        $checkLink = function (string $href) use ($dir, $projectRoot, &$issues, $targetRelative) {
            if (should_skip_link($href)) {
                return;
            }
            $parsed = parse_url($href);
            $path = $parsed['path'] ?? '';
            $resolved = $path;
            if ($path === '') {
                return;
            }
            if ($path[0] === '/') {
                $resolved = $projectRoot . '/' . ltrim($path, '/');
            } else {
                $resolved = normalise_path($dir . '/' . $path);
            }
            if (!file_exists($resolved)) {
                $issues[] = "Unresolved link '{$href}' in {$targetRelative}";
            }
        };

        if (preg_match_all('~\[[^\]]+\]\(([^)]+)\)~', $content, $matches)) {
            foreach ($matches[1] as $href) {
                $checkLink($href);
            }
        }
        if (preg_match_all('~href\s*=\s*"([^"]+)"~i', $content, $matches)) {
            foreach ($matches[1] as $href) {
                $checkLink(htmlspecialchars_decode($href, ENT_QUOTES));
            }
        }
    }
    return $issues;
}

$linkIssues = verify_links($migrationPlan, $projectRoot);
$reportPath = $mapsDir . '/link-fix-report.md';

$reportLines = [
    '# Link Fix Verification',
    '',
    'Date: ' . date(DATE_ATOM),
    '',
];

if (empty($linkIssues) && empty($unresolvedLinks)) {
    $reportLines[] = 'All links resolved successfully.';
} else {
    $reportLines[] = 'Issues found:';
    $combined = array_merge($unresolvedLinks, $linkIssues);
    foreach ($combined as $issue) {
        $reportLines[] = '- ' . $issue;
    }
}

file_put_contents($reportPath, implode("\n", $reportLines) . "\n");
fwrite(STDOUT, "Verification report written to {$reportPath}\n");

if (!empty($unresolvedLinks) || !empty($linkIssues)) {
    fwrite(STDERR, "Unresolved links detected. See report for details.\n");
    exit(1);
}

fwrite(STDOUT, "Migration completed successfully.\n");
