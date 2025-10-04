#!/usr/bin/env php
<?php
declare(strict_types=1);

$argvCopy = $argv;
array_shift($argvCopy);
$checkMode = false;
foreach ($argvCopy as $arg) {
    if ($arg === '--check') {
        $checkMode = true;
        continue;
    }
    if ($arg === '-h' || $arg === '--help') {
        fwrite(STDOUT, "Usage: php scripts/normalize_links.php [--check]\n");
        fwrite(STDOUT, "       --check  Run without writing changes and exit with 1 if fixes are needed.\n");
        exit(0);
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    exit(1);
}

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
    if ($ext !== 'md' && $ext !== 'markdown') {
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

    $contents = file_get_contents($absolutePath);
    if ($contents === false || $contents === '') {
        continue;
    }

    $fileChanged = false;
    $linkChanges = 0;

    $pattern = '/(?<!\\!)\[(.*?)\]\(([^)]+)\)/';
    $newContents = preg_replace_callback($pattern, function (array $match) use (&$stats, &$fileChanged, &$linkChanges, $baseSegments, $arkhiveDir) {
        $stats['links']++;

        $label = $match[1];
        $rawDest = $match[2];
        $trimmedDest = trim($rawDest);
        if ($trimmedDest === '') {
            return $match[0];
        }

        $hasAngles = false;
        $titlePart = '';
        $destCore = $trimmedDest;

        if ($destCore[0] === '<' && str_ends_with_custom($destCore, '>')) {
            $hasAngles = true;
            $destCore = substr($destCore, 1, -1);
        }

        if (!$hasAngles && preg_match('/^(.*?)(\s+"[^"]*"|\s+\'[^\']*\'|\s+\([^)]*\))$/', $destCore, $titleMatch)) {
            $destCore = $titleMatch[1];
            $titlePart = $titleMatch[2];
        }

        $destCore = trim($destCore);
        if ($destCore === '') {
            return $match[0];
        }

        if ($destCore[0] === '#') {
            return $match[0];
        }

        if (preg_match('/^[a-z][a-z0-9+\-.]*:/i', $destCore)) {
            return $match[0];
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
            return $match[0];
        }

        $normalizeResult = normalize_local_path($pathPart, $baseSegments, $arkhiveDir);
        if ($normalizeResult['skipped']) {
            return $match[0];
        }

        $normalizedPath = $normalizeResult['path'];
        $finalPath = $normalizedPath . $query . $anchor;

        $originalComparable = $pathPart . $query . $anchor;
        if ($finalPath === $originalComparable) {
            return $match[0];
        }

        $fileChanged = true;
        $linkChanges++;
        $stats['rewritten']++;

        $renderPath = $finalPath;
        if ($hasAngles) {
            $renderPath = '<' . $renderPath . '>';
        }
        $renderPath .= $titlePart;

        return '[' . $label . '](' . $renderPath . ')';
    }, $contents);

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

fwrite(STDOUT, sprintf("Scanned %d Markdown files.\n", $stats['files']));
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

function normalize_local_path(string $targetPath, array $baseSegments, string $arkhiveDir): array
{
    $result = [
        'path' => $targetPath,
        'skipped' => false,
    ];

    $normalized = str_replace('\\', '/', $targetPath);
    $normalized = preg_replace('#/{2,}#', '/', $normalized);
    $normalized = trim($normalized);

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

    $targetSegments = $stack;
    $targetIsDir = false;

    $targetPathNormalized = implode('/', $targetSegments);
    $fullPath = $targetPathNormalized === '' ? $arkhiveDir : $arkhiveDir . '/' . $targetPathNormalized;

    if ($targetPathNormalized === '') {
        $targetIsDir = true;
    } elseif (is_dir($fullPath)) {
        $targetIsDir = true;
    } else {
        $lastSegment = end($targetSegments);
        if ($lastSegment !== false) {
            $basename = strtolower($lastSegment);
            if (in_array($basename, ['readme.md', 'index.md', 'overview.md'], true)) {
                array_pop($targetSegments);
                $targetPathNormalized = implode('/', $targetSegments);
                $fullPath = $targetPathNormalized === '' ? $arkhiveDir : $arkhiveDir . '/' . $targetPathNormalized;
                if (is_dir($fullPath) || $targetPathNormalized === '') {
                    $targetIsDir = true;
                } else {
                    $targetSegments[] = $lastSegment; // revert if no directory exists
                }
            }
        }
    }

    $relativeSegments = relative_segments($baseSegments, $targetSegments);
    $relativePath = segments_to_path($relativeSegments);

    if ($targetIsDir) {
        if ($relativePath === '' || $relativePath === '.') {
            $relativePath = './';
        } elseif (!str_ends_with_custom($relativePath, '/')) {
            $relativePath .= '/';
        }
    } else {
        if ($relativePath === '.') {
            $relativePath = './';
        }
    }

    $result['path'] = $relativePath;
    return $result;
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
