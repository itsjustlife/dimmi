#!/usr/bin/env php
<?php
declare(strict_types=1);

$argvCopy = $argv;
array_shift($argvCopy);
$mode = null;
foreach ($argvCopy as $arg) {
    if ($arg === '--check') {
        if ($mode !== null) {
            fwrite(STDERR, "Only one of --check or --apply may be used.\n");
            exit(1);
        }
        $mode = 'check';
        continue;
    }
    if ($arg === '--apply') {
        if ($mode !== null) {
            fwrite(STDERR, "Only one of --check or --apply may be used.\n");
            exit(1);
        }
        $mode = 'apply';
        continue;
    }
    if ($arg === '-h' || $arg === '--help') {
        display_usage(0);
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    display_usage(1);
}

if ($mode === null) {
    display_usage(1);
}

$checkMode = $mode === 'check';

$rootDir = dirname(__DIR__);
$arkhiveDir = $rootDir . DIRECTORY_SEPARATOR . 'ARKHIVE';
if (!is_dir($arkhiveDir)) {
    fwrite(STDERR, "ARKHIVE directory not found at {$arkhiveDir}.\n");
    exit(1);
}

$stats = [
    'files' => 0,
    'links' => 0,
    'rewritten' => 0,
    'filesChanged' => 0,
    'changes' => [],
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $arkhiveDir,
        FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
    )
);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }
    $ext = strtolower($fileInfo->getExtension());
    if (!in_array($ext, ['md', 'markdown', 'html', 'htm'], true)) {
        continue;
    }

    $stats['files']++;
    $absolutePath = $fileInfo->getRealPath();
    if ($absolutePath === false) {
        $absolutePath = $fileInfo->getPathname();
    }
    $relativePath = substr($absolutePath, strlen($arkhiveDir) + 1);
    $relativePath = str_replace('\\', '/', $relativePath);

    $baseDir = str_replace('\\', '/', dirname($relativePath));
    if ($baseDir === '.' || $baseDir === DIRECTORY_SEPARATOR) {
        $baseDir = '';
    }
    $baseSegments = $baseDir === '' ? [] : array_values(array_filter(explode('/', $baseDir), 'strlen'));
    $context = [
        'topic' => determine_topic_segment($baseSegments, $relativePath),
        'baseSegments' => $baseSegments,
        'sourceFile' => $relativePath,
    ];

    $contents = file_get_contents($absolutePath);
    if ($contents === false || $contents === '') {
        continue;
    }

    $fileChanged = false;
    $linkChanges = 0;

    $isMarkdown = in_array($ext, ['md', 'markdown'], true);
    if ($isMarkdown) {
        $pattern = '/(?<!\\!)\[(.*?)\]\(([^)]+)\)/';
        $newContents = preg_replace_callback($pattern, function (array $match) use (&$stats, &$fileChanged, &$linkChanges, $baseSegments, $arkhiveDir, $context) {
            $stats['links']++;

            $label = $match[1];
            $rawDest = $match[2];
            $result = process_markdown_destination($rawDest, $baseSegments, $arkhiveDir, $context);
            if ($result['skipped']) {
                return $match[0];
            }

            if ($result['final'] === $result['original']) {
                return $match[0];
            }

            $fileChanged = true;
            $linkChanges++;
            $stats['rewritten']++;

            return '[' . $label . '](' . $result['render'] . ')';
        }, $contents);
    } else {
        $pattern = '/(<a\b[^>]*?href\s*=\s*)("|\')(.*?)(\2)/i';
        $newContents = preg_replace_callback($pattern, function (array $match) use (&$stats, &$fileChanged, &$linkChanges, $baseSegments, $arkhiveDir, $context) {
            $stats['links']++;

            $prefix = $match[1];
            $quote = $match[2];
            $rawDest = $match[3];
            $result = process_html_destination($rawDest, $baseSegments, $arkhiveDir, $context);
            if ($result['skipped']) {
                return $match[0];
            }

            if ($result['final'] === $result['original']) {
                return $match[0];
            }

            $fileChanged = true;
            $linkChanges++;
            $stats['rewritten']++;

            return $prefix . $quote . $result['final'] . $quote;
        }, $contents);
    }

    if ($fileChanged) {
        $stats['filesChanged']++;
        $stats['changes'][] = ['file' => $relativePath, 'links' => $linkChanges];
        if (!$checkMode) {
            file_put_contents($absolutePath, $newContents);
        }
    }
}

if (!empty($stats['changes'])) {
    foreach ($stats['changes'] as $change) {
        fwrite(STDOUT, sprintf("%s: %d link(s) normalized\n", $change['file'], $change['links']));
    }
}

fwrite(STDOUT, sprintf("Scanned %d content files.\n", $stats['files']));
if ($stats['links'] > 0) {
    fwrite(STDOUT, sprintf("Checked %d links; %d adjusted.\n", $stats['links'], $stats['rewritten']));
} else {
    fwrite(STDOUT, "No links found.\n");
}

if ($checkMode) {
    if ($stats['filesChanged'] > 0) {
        fwrite(STDOUT, sprintf("%d file(s) would change.\n", $stats['filesChanged']));
        exit(1);
    }
    fwrite(STDOUT, "All links are normalized.\n");
    exit(0);
}

if ($stats['filesChanged'] > 0) {
    fwrite(STDOUT, sprintf("Updated %d file(s).\n", $stats['filesChanged']));
} else {
    fwrite(STDOUT, "No changes were necessary.\n");
}

function process_markdown_destination(string $rawDest, array $baseSegments, string $arkhiveDir, array $context): array
{
    $trimmedDest = trim($rawDest);
    if ($trimmedDest === '') {
        return ['skipped' => true, 'original' => $trimmedDest, 'final' => $trimmedDest, 'render' => $trimmedDest];
    }

    $hasAngles = false;
    $titlePart = '';
    $destCore = $trimmedDest;

    if ($destCore !== '' && $destCore[0] === '<' && str_ends_with_custom($destCore, '>')) {
        $hasAngles = true;
        $destCore = substr($destCore, 1, -1);
    }

    if (!$hasAngles && preg_match('/^(.*?)(\s+\"[^\"]*\"|\s+\'[^\']*\'|\s+\([^)]*\))$/', $destCore, $titleMatch)) {
        $destCore = $titleMatch[1];
        $titlePart = $titleMatch[2];
    }

    $destCore = trim($destCore);
    if ($destCore === '') {
        return ['skipped' => true, 'original' => $trimmedDest, 'final' => $trimmedDest, 'render' => $trimmedDest];
    }

    if ($destCore[0] === '#') {
        return ['skipped' => true, 'original' => $trimmedDest, 'final' => $trimmedDest, 'render' => $trimmedDest];
    }

    if (preg_match('/^[a-z][a-z0-9+\-.]*:/i', $destCore)) {
        return ['skipped' => true, 'original' => $trimmedDest, 'final' => $trimmedDest, 'render' => $trimmedDest];
    }

    $query = '';
    $anchor = '';
    $pathPart = $destCore;

    if (strpos($pathPart, '#') !== false) {
        [$pathPart, $anchorPart] = explode('#', $pathPart, 2);
        $anchor = '#' . $anchorPart;
    }

    if (strpos($pathPart, '?') !== false) {
        [$pathPart, $queryPart] = explode('?', $pathPart, 2);
        $query = '?' . $queryPart;
    }

    $pathPart = trim($pathPart);
    if ($pathPart === '') {
        return ['skipped' => true, 'original' => $trimmedDest, 'final' => $trimmedDest, 'render' => $trimmedDest];
    }

    $hadTrailingSlash = str_ends_with_custom($pathPart, '/');
    $normalizeResult = normalize_local_path($pathPart, $baseSegments, $arkhiveDir, $context, $hadTrailingSlash);
    if ($normalizeResult['skipped']) {
        return ['skipped' => true, 'original' => $trimmedDest, 'final' => $trimmedDest, 'render' => $trimmedDest];
    }

    $normalizedPath = $normalizeResult['path'];
    $finalPath = $normalizedPath . $query . $anchor;
    $originalComparable = $pathPart . $query . $anchor;

    $renderPath = $finalPath;
    if ($hasAngles) {
        $renderPath = '<' . $renderPath . '>';
    }
    $renderPath .= $titlePart;

    return [
        'skipped' => false,
        'original' => $originalComparable,
        'final' => $finalPath,
        'render' => $renderPath,
    ];
}

function process_html_destination(string $rawDest, array $baseSegments, string $arkhiveDir, array $context): array
{
    $trimmedDest = trim($rawDest);
    if ($trimmedDest === '') {
        return ['skipped' => true, 'original' => $trimmedDest, 'final' => $trimmedDest];
    }

    if ($trimmedDest[0] === '#') {
        return ['skipped' => true, 'original' => $trimmedDest, 'final' => $trimmedDest];
    }

    if (preg_match('/^[a-z][a-z0-9+\-.]*:/i', $trimmedDest)) {
        return ['skipped' => true, 'original' => $trimmedDest, 'final' => $trimmedDest];
    }

    $query = '';
    $anchor = '';
    $pathPart = $trimmedDest;

    if (strpos($pathPart, '#') !== false) {
        [$pathPart, $anchorPart] = explode('#', $pathPart, 2);
        $anchor = '#' . $anchorPart;
    }

    if (strpos($pathPart, '?') !== false) {
        [$pathPart, $queryPart] = explode('?', $pathPart, 2);
        $query = '?' . $queryPart;
    }

    $pathPart = trim($pathPart);
    if ($pathPart === '') {
        return ['skipped' => true, 'original' => $trimmedDest, 'final' => $trimmedDest];
    }

    $hadTrailingSlash = str_ends_with_custom($pathPart, '/');
    $normalizeResult = normalize_local_path($pathPart, $baseSegments, $arkhiveDir, $context, $hadTrailingSlash);
    if ($normalizeResult['skipped']) {
        return ['skipped' => true, 'original' => $trimmedDest, 'final' => $trimmedDest];
    }

    $normalizedPath = $normalizeResult['path'];
    $finalPath = $normalizedPath . $query . $anchor;
    $originalComparable = $pathPart . $query . $anchor;

    return [
        'skipped' => false,
        'original' => $originalComparable,
        'final' => $finalPath,
    ];
}
function normalize_local_path(string $targetPath, array $baseSegments, string $arkhiveDir, array $context, bool $hadTrailingSlash): array
{
    $result = [
        'path' => $targetPath,
        'skipped' => false,
    ];

    $normalized = str_replace('\\', '/', $targetPath);
    $normalized = trim($normalized);
    $originalHadSlash = $hadTrailingSlash;
    if ($normalized === '') {
        return $result;
    }

    if ($originalHadSlash) {
        $normalized = rtrim($normalized, '/');
    }

    $normalized = preg_replace('#/{2,}#', '/', $normalized);

    $rootRelative = false;
    if (preg_match('/^arkhive\//i', $normalized)) {
        $normalized = substr($normalized, strpos($normalized, '/') + 1);
        $rootRelative = true;
    }
    if ($normalized !== '' && $normalized[0] === '/') {
        $normalized = ltrim($normalized, '/');
        $rootRelative = true;
    }

    $segments = $normalized === '' ? [] : explode('/', $normalized);
    $stack = $rootRelative ? [] : $baseSegments;
    $outside = false;

    foreach ($segments as $segment) {
        $segment = trim($segment);
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if (!empty($stack)) {
                array_pop($stack);
            } else {
                $outside = true;
            }
            continue;
        }
        $stack[] = $segment;
    }

    if ($outside) {
        $result['skipped'] = true;
        return $result;
    }

    if (!empty($stack)) {
        $firstLower = strtolower($stack[0]);
        if (in_array($firstLower, ['assets', 'asset', 'docs', 'doc', 'documents'], true)) {
            $remaining = array_slice($stack, 1);
            $topicSegment = sanitize_topic_segment($context['topic'] ?? '');
            if ($topicSegment === '') {
                $topicSegment = 'topic';
            }
            $assetSegments = empty($baseSegments) ? ['assets'] : ['..', 'assets'];
            $assetSegments[] = $topicSegment;
            foreach ($remaining as $segment) {
                $assetSegments[] = sanitize_asset_segment($segment);
            }
            $result['path'] = segments_to_path($assetSegments);
            return $result;
        }
    }

    $targetSegments = $stack;
    $targetPathNormalized = segments_to_path($targetSegments);
    $fullPath = $targetPathNormalized === '' ? $arkhiveDir : $arkhiveDir . '/' . $targetPathNormalized;

    $isDirectory = false;
    if ($targetPathNormalized === '') {
        $isDirectory = true;
    } elseif (is_dir($fullPath)) {
        $isDirectory = true;
    }
    if ($isDirectory) {
        $targetSegments = map_directory_to_default_file($targetSegments, $arkhiveDir, $context);
    } else {
        $targetSegments = ensure_extension_for_target($targetSegments, $arkhiveDir, $originalHadSlash);
    }

    $relativeSegments = relative_segments($baseSegments, $targetSegments);
    $relativePath = segments_to_path($relativeSegments);
    if ($relativePath === '') {
        $relativePath = './';
    }

    $result['path'] = $relativePath;
    return $result;
}

function map_directory_to_default_file(array $segments, string $arkhiveDir, array $context): array
{
    $directorySegments = $segments;
    $fullDir = empty($directorySegments) ? $arkhiveDir : $arkhiveDir . '/' . segments_to_path($directorySegments);
    $folderName = '';
    if (!empty($directorySegments)) {
        $folderName = $directorySegments[count($directorySegments) - 1];
    } else {
        $folderName = $context['topic'] ?? 'Arkhive';
    }

    $defaultFile = resolve_directory_default_file($fullDir, $folderName);
    $segments[] = $defaultFile;
    return $segments;
}

function ensure_extension_for_target(array $targetSegments, string $arkhiveDir, bool $hadSlash): array
{
    if (empty($targetSegments)) {
        return $targetSegments;
    }

    $lastIndex = count($targetSegments) - 1;
    $lastSegment = $targetSegments[$lastIndex];
    $extension = pathinfo($lastSegment, PATHINFO_EXTENSION);
    if ($extension !== '') {
        return $targetSegments;
    }

    $baseSegments = array_slice($targetSegments, 0, $lastIndex);
    $basePath = empty($baseSegments) ? $arkhiveDir : $arkhiveDir . '/' . segments_to_path($baseSegments);

    $candidates = [$lastSegment . '.md', $lastSegment . '.markdown'];
    $sanitized = sanitize_folder_file_name($lastSegment);
    if ($sanitized !== '') {
        $candidates[] = $sanitized . '.md';
    }

    foreach ($candidates as $candidate) {
        $fullCandidate = rtrim($basePath, '/') . '/' . $candidate;
        if (is_file($fullCandidate)) {
            $targetSegments[$lastIndex] = $candidate;
            return $targetSegments;
        }
    }

    $fallback = $sanitized !== '' ? $sanitized . '.md' : 'index.md';
    $targetSegments[$lastIndex] = $fallback;
    return $targetSegments;
}

function resolve_directory_default_file(string $dirPath, string $folderName): string
{
    $normalizedTarget = normalize_name_for_compare($folderName);
    $preferred = null;
    if (is_dir($dirPath)) {
        foreach (new FilesystemIterator($dirPath, FilesystemIterator::SKIP_DOTS) as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $extension = strtolower($item->getExtension());
            if (!in_array($extension, ['md', 'markdown'], true)) {
                continue;
            }
            $baseName = $item->getBasename('.' . $item->getExtension());
            $normalizedCandidate = normalize_name_for_compare($baseName);
            if ($normalizedCandidate === $normalizedTarget) {
                if ($extension === 'md') {
                    return $item->getFilename();
                }
                if ($preferred === null) {
                    $preferred = $item->getFilename();
                }
            }
        }
    }

    if ($preferred !== null) {
        return $preferred;
    }

    $safeName = sanitize_folder_file_name($folderName);
    if ($safeName === '') {
        $safeName = 'index';
    }
    return $safeName . '.md';
}

function sanitize_topic_segment(string $topic): string
{
    $clean = sanitize_folder_file_name($topic);
    if ($clean === '') {
        return 'topic';
    }
    return $clean;
}

function sanitize_asset_segment(string $segment): string
{
    return trim($segment);
}

function sanitize_folder_file_name(string $name): string
{
    $trimmed = trim($name);
    if ($trimmed === '') {
        return '';
    }
    $singleSpaced = preg_replace('/\s+/', ' ', $trimmed);
    $hyphenated = str_replace(' ', '-', $singleSpaced);
    $cleaned = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $hyphenated);
    return trim($cleaned, '-');
}

function determine_topic_segment(array $baseSegments, string $relativePath): string
{
    if (!empty($baseSegments)) {
        $last = $baseSegments[count($baseSegments) - 1];
        if ($last !== '') {
            return $last;
        }
    }

    $fileName = pathinfo($relativePath, PATHINFO_FILENAME);
    if ($fileName !== '') {
        return $fileName;
    }

    return 'Arkhive';
}

function display_usage(int $exitCode): void
{
    $stream = $exitCode === 0 ? STDOUT : STDERR;
    fwrite($stream, "Usage: php scripts/normalize_links.php (--check | --apply)\n");
    fwrite($stream, "  --check  Run without writing changes and exit with 1 if fixes are needed.\n");
    fwrite($stream, "  --apply  Rewrite files in place.\n");
    exit($exitCode);
}
function normalize_name_for_compare(string $name): string
{
    $lower = strtolower($name);
    return preg_replace('/[^a-z0-9]+/', '', $lower);
}
function relative_segments(array $from, array $to): array
{
    $max = min(count($from), count($to));
    $i = 0;
    while ($i < $max && $from[$i] === $to[$i]) {
        $i++;
    }
    $up = array_fill(0, count($from) - $i, '..');
    $down = array_slice($to, $i);
    return array_merge($up, $down);
}

function segments_to_path(array $segments): string
{
    if (empty($segments)) {
        return '';
    }
    return implode('/', $segments);
}

function str_ends_with_custom(string $haystack, string $needle): bool
{
    $needleLength = strlen($needle);
    if ($needleLength === 0) {
        return true;
    }
    if ($needleLength > strlen($haystack)) {
        return false;
    }
    return substr($haystack, -$needleLength) === $needle;
}
