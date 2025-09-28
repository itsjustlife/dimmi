#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$arkhiveDir = $root . '/ARKHIVE';
if (!is_dir($arkhiveDir)) {
    fwrite(STDERR, "ARKHIVE directory not found at $arkhiveDir\n");
    exit(1);
}

$dataDir = $root . '/DATA/door';
$contentDir = $dataDir . '/content';

ensure_dir($dataDir);
clear_dir($contentDir);
ensure_dir($contentDir);

$state = [
    'nodes' => [],
    'pathToId' => [],
    'linksCount' => 0,
];

$expectedBranches = ['Who','What','Where','When','Why','How'];

process_dir($arkhiveDir, '', $state);

foreach ($expectedBranches as $branch) {
    ensure_branch($branch, $state, $arkhiveDir);
}

resolve_links($state, $arkhiveDir);

$propromptPointer = <<<TXT
/// === PROPROMPT:BEGIN ===
see: MIND/ProPrompts/Library/DOOR-Seeds.md
/// === PROPROMPT:END ===
TXT;

generate_content_files($state, $contentDir, $propromptPointer);

$nodesOut = [];
foreach ($state['nodes'] as $id => $node) {
    $nodesOut[] = [
        'id' => $node['id'],
        'title' => $node['title'],
        'branch' => $node['branch'],
        'kind' => $node['kind'],
        'children' => $node['children'],
        'links' => $node['links'],
        'contentPath' => 'DATA/door/content/' . $node['id'] . '.md',
        'sourcePath' => $node['sourcePath'],
        'missing' => $node['missing'],
    ];
}

usort($nodesOut, function (array $a, array $b) {
    return strcmp($a['id'], $b['id']);
});

$indexOut = [
    'schemaVersion' => '1.0.0',
    'generatedAt' => gmdate('c'),
    'root' => 'mind-atlas',
    'branches' => array_map(function ($branch) use ($state) {
        $id = slugify($branch);
        return [
            'id' => $id,
            'title' => titleize($branch),
            'exists' => isset($state['nodes'][$id]) && !$state['nodes'][$id]['missing'],
        ];
    }, $expectedBranches),
    'counts' => [
        'nodes' => count($nodesOut),
        'links' => $state['linksCount'],
    ],
    'contentBase' => 'DATA/door/content',
];

file_put_contents($dataDir . '/nodes.json', json_encode([
    'schemaVersion' => '1.0.0',
    'generatedAt' => gmdate('c'),
    'nodes' => $nodesOut,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

file_put_contents($dataDir . '/index.json', json_encode($indexOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Generated " . count($nodesOut) . " nodes with " . $state['linksCount'] . " links.\n";

function ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Failed to create directory: $dir");
    }
}

function clear_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            clear_dir($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}

function process_dir(string $arkhiveDir, string $relPath, array &$state): string
{
    $id = $relPath === '' ? 'mind-atlas' : slugify($relPath);
    if (isset($state['nodes'][$id])) {
        return $id;
    }

    $fullPath = $relPath === '' ? $arkhiveDir : $arkhiveDir . '/' . $relPath;
    $exists = is_dir($fullPath);

    $children = [];
    if ($exists) {
        $entries = scandir($fullPath) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if ($entry[0] === '.') {
                continue;
            }
            $childRel = ltrim(($relPath === '' ? '' : $relPath . '/') . $entry, '/');
            $childFull = $fullPath . '/' . $entry;
            if (is_dir($childFull)) {
                $childId = process_dir($arkhiveDir, $childRel, $state);
                $children[] = $childId;
            } elseif (is_text_file($entry) && should_index_file($entry)) {
                $childId = process_file($arkhiveDir, $childRel, $state);
                $children[] = $childId;
            }
        }
    }

    $overview = get_overview($arkhiveDir, $relPath, $exists);

    $state['nodes'][$id] = [
        'id' => $id,
        'title' => $relPath === '' ? 'Mind Atlas' : titleize(basename($relPath)),
        'branch' => branch_for($relPath),
        'kind' => 'dir',
        'children' => sort_children($children, $state),
        'rawLinks' => $overview['links'],
        'baseDir' => $overview['baseDir'],
        'body' => $overview['markdown'],
        'sourcePath' => $overview['source'],
        'missing' => !$exists,
        'links' => [],
    ];

    $state['pathToId']['dir:' . strtolower(trim($relPath))] = $id;

    return $id;
}

function process_file(string $arkhiveDir, string $relPath, array &$state): string
{
    $normalized = normalize_relative($relPath);
    $id = slugify($normalized);
    if (isset($state['nodes'][$id])) {
        return $id;
    }

    $fullPath = $arkhiveDir . '/' . $normalized;
    $content = is_file($fullPath) ? trim(file_get_contents($fullPath) ?: '') : '';
    $baseDir = trim(dirname($normalized), '.');
    $links = extract_links($content);

    $state['nodes'][$id] = [
        'id' => $id,
        'title' => titleize(pathinfo(basename($normalized), PATHINFO_FILENAME)),
        'branch' => branch_for($normalized),
        'kind' => 'file',
        'children' => [],
        'rawLinks' => $links,
        'baseDir' => $baseDir,
        'body' => $content,
        'sourcePath' => 'ARKHIVE/' . $normalized,
        'missing' => !is_file($fullPath),
        'links' => [],
    ];

    $withoutExt = strtolower(pathinfo($normalized, PATHINFO_FILENAME));
    $state['pathToId']['file:' . strtolower($normalized)] = $id;
    $state['pathToId']['file-noext:' . strtolower(trim($baseDir === '' ? $withoutExt : $baseDir . '/' . $withoutExt))] = $id;
    if (!isset($state['pathToId']['file-noext:' . $withoutExt])) {
        $state['pathToId']['file-noext:' . $withoutExt] = $id;
    }

    return $id;
}

function ensure_branch(string $branch, array &$state, string $arkhiveDir): void
{
    $id = slugify($branch);
    if (!isset($state['nodes'][$id])) {
        $state['nodes'][$id] = [
            'id' => $id,
            'title' => titleize($branch),
            'branch' => titleize($branch),
            'kind' => 'dir',
            'children' => [],
            'rawLinks' => [],
            'baseDir' => $branch,
            'body' => stub_text($branch),
            'sourcePath' => null,
            'missing' => true,
            'links' => [],
        ];
    }
    if (!in_array($id, $state['nodes']['mind-atlas']['children'], true)) {
        $state['nodes']['mind-atlas']['children'][] = $id;
        $state['nodes']['mind-atlas']['children'] = sort_children($state['nodes']['mind-atlas']['children'], $state);
    }
}

function sort_children(array $children, array $state): array
{
    $children = array_values(array_unique($children));
    usort($children, function (string $a, string $b) use ($state): int {
        $ta = $state['nodes'][$a]['title'] ?? $a;
        $tb = $state['nodes'][$b]['title'] ?? $b;
        $cmp = strcasecmp($ta, $tb);
        if ($cmp === 0) {
            return strcmp($a, $b);
        }
        return $cmp;
    });
    return $children;
}

function get_overview(string $arkhiveDir, string $relPath, bool $exists): array
{
    $candidates = [];
    if ($relPath === '') {
        $candidates = ['Arkhive.md', 'Arkhive.txt', 'start.md'];
    } else {
        $base = basename($relPath);
        $candidates = ['README.md', 'Readme.md', 'readme.md', 'index.md', 'overview.md', $base . '.md', strtolower($base) . '.md', $base . '.txt'];
        if (strpos($relPath, '/') === false) {
            $candidates[] = strtolower($base) . '.txt';
        }
    }

    $baseDir = trim($relPath);
    $source = null;
    $markdown = '';
    foreach ($candidates as $candidate) {
        if ($relPath === '') {
            $candidatePath = $arkhiveDir . '/' . $candidate;
        } else {
            $candidatePath = $arkhiveDir . '/' . trim($relPath, '/') . '/' . $candidate;
        }
        if (is_file($candidatePath)) {
            $markdown = trim(file_get_contents($candidatePath) ?: '');
            $source = 'ARKHIVE/' . trim($relPath === '' ? $candidate : $relPath . '/' . $candidate, '/');
            $baseDir = trim($relPath === '' ? '' : $relPath, '/');
            break;
        }
    }

    if ($markdown === '' && $relPath !== '' && strpos($relPath, '/') === false) {
        $alt = $arkhiveDir . '/' . strtolower($relPath) . '.md';
        if (is_file($alt)) {
            $markdown = trim(file_get_contents($alt) ?: '');
            $source = 'ARKHIVE/' . strtolower($relPath) . '.md';
            $baseDir = '';
        }
    }

    if ($markdown === '') {
        $markdown = stub_text($relPath === '' ? 'Mind Atlas' : basename($relPath));
    }

    return [
        'markdown' => $markdown,
        'links' => extract_links($markdown),
        'baseDir' => trim($baseDir, '/'),
        'source' => $source,
    ];
}

function stub_text(string $name): string
{
    $title = titleize($name);
    return "This room doesn't have notes yet. Add details under ARKHIVE/" . trim(str_replace(' ', '_', $name), '/') . " to grow the Mind Atlas.";
}

function branch_for(string $relPath): string
{
    if ($relPath === '' || $relPath === '/') {
        return 'ROOT';
    }
    $parts = explode('/', trim($relPath, '/'));
    return titleize($parts[0] ?? 'ROOT');
}

function slugify(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return 'mind-atlas';
    }
    $parts = explode('/', str_replace('\\', '/', $path));
    $out = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        $part = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $part));
        $part = trim($part, '-');
        if ($part !== '') {
            $out[] = $part;
        }
    }
    return $out ? implode('__', $out) : 'mind-atlas';
}

function titleize(string $value): string
{
    $value = str_replace(['_', '-'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    $value = trim($value);
    return $value === '' ? 'Untitled' : mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
}

function is_text_file(string $filename): bool
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['md', 'markdown', 'txt'], true);
}

function should_index_file(string $filename): bool
{
    $lower = strtolower($filename);
    return !in_array($lower, ['readme.md', 'index.md', 'overview.md'], true);
}

function extract_links(string $markdown): array
{
    if ($markdown === '') {
        return [];
    }
    $links = [];
    if (preg_match_all('/(?<!\\!)\[(.*?)\]\(([^)]+)\)/', $markdown, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $text = trim($match[1]);
            $href = trim($match[2]);
            if ($href === '') {
                continue;
            }
            $links[] = ['text' => $text === '' ? $href : $text, 'href' => $href];
        }
    }
    return $links;
}

function resolve_links(array &$state, string $arkhiveDir): void
{
    foreach ($state['nodes'] as &$node) {
        $resolved = [];
        $baseDir = $node['baseDir'];
        foreach ($node['rawLinks'] as $raw) {
            $href = $raw['href'];
            $title = $raw['text'];
            if (preg_match('/^[a-z]+:\/\//i', $href) || str_starts_with(strtolower($href), 'mailto:')) {
                $resolved[] = ['title' => $title, 'type' => 'url', 'target' => $href];
                continue;
            }
            $anchor = null;
            if (str_contains($href, '#')) {
                [$href, $anchor] = explode('#', $href, 2);
            }
            $href = trim($href);
            if ($href === '') {
                continue;
            }
            $normalized = normalize_target($baseDir, $href);
            $keyDir = 'dir:' . strtolower($normalized);
            $keyFile = 'file:' . strtolower($normalized);
            $keyFileNoExt = 'file-noext:' . strtolower($normalized);

            if (isset($state['pathToId'][$keyDir])) {
                $resolved[] = [
                    'title' => $title,
                    'type' => 'node',
                    'target' => $state['pathToId'][$keyDir],
                    'path' => $normalized,
                    'nodeKind' => 'dir',
                ];
                continue;
            }
            if (isset($state['pathToId'][$keyFile])) {
                $resolved[] = [
                    'title' => $title,
                    'type' => 'node',
                    'target' => $state['pathToId'][$keyFile],
                    'path' => $normalized,
                    'nodeKind' => 'file',
                ];
                continue;
            }
            if (isset($state['pathToId'][$keyFileNoExt])) {
                $resolved[] = [
                    'title' => $title,
                    'type' => 'node',
                    'target' => $state['pathToId'][$keyFileNoExt],
                    'path' => $normalized,
                    'nodeKind' => 'file',
                ];
                continue;
            }

            $resolved[] = ['title' => $title, 'type' => 'path', 'target' => $normalized];
        }
        $node['links'] = $resolved;
        $state['linksCount'] += count($resolved);
        unset($node['rawLinks']);
        unset($node['baseDir']);
    }
}

function generate_content_files(array &$state, string $contentDir, string $propromptPointer): void
{
    foreach ($state['nodes'] as $node) {
        $lines = [];
        $body = trim($node['body']);
        $titleLine = '# ' . $node['title'];
        $firstLine = $body === '' ? '' : trim(strtok($body, "\n"));
        $includeTitle = $firstLine === '' || strcasecmp($firstLine, $titleLine) !== 0;

        if ($includeTitle) {
            $lines[] = $titleLine;
            $lines[] = '';
        }

        if ($body !== '') {
            $lines[] = $body;
            $lines[] = '';
        } else {
            $lines[] = stub_text($node['title']);
            $lines[] = '';
        }
        if (!empty($node['children'])) {
            $lines[] = '## Child Rooms';
            foreach ($node['children'] as $childId) {
                $childTitle = $state['nodes'][$childId]['title'] ?? $childId;
                $lines[] = '- **' . $childTitle . '** (`' . $childId . '`)';
            }
            $lines[] = '';
        }
        if (!empty($node['links'])) {
            $lines[] = '## Teleports';
            foreach ($node['links'] as $link) {
                if ($link['type'] === 'node') {
                    $targetTitle = $state['nodes'][$link['target']]['title'] ?? $link['target'];
                    $lines[] = '- ' . $link['title'] . ' → `' . $targetTitle . '` (' . $link['target'] . ')';
                } elseif ($link['type'] === 'url') {
                    $lines[] = '- [' . $link['title'] . '](' . $link['target'] . ')';
                } else {
                    $lines[] = '- ' . $link['title'] . ' → ' . $link['target'];
                }
            }
            $lines[] = '';
        }
        $lines[] = '---';
        if ($node['sourcePath']) {
            $lines[] = 'Source: ' . $node['sourcePath'];
        } else {
            $lines[] = 'Source: (generated stub)';
        }
        $lines[] = '';
        $lines[] = trim($propromptPointer);
        $lines[] = '';

        $contentPath = $contentDir . '/' . $node['id'] . '.md';
        ensure_dir(dirname($contentPath));
        file_put_contents($contentPath, implode("\n", $lines));
    }
}

function normalize_target(string $baseDir, string $target): string
{
    if ($target === '') {
        return trim($baseDir, '/');
    }
    if ($target[0] === '/') {
        return trim($target, '/');
    }
    $baseParts = $baseDir === '' ? [] : explode('/', trim($baseDir, '/'));
    $targetParts = explode('/', str_replace('\\', '/', $target));
    $parts = $baseParts;
    foreach ($targetParts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    return trim(implode('/', $parts), '/');
}

function normalize_relative(string $path): string
{
    return trim(str_replace('\\', '/', $path), '/');
}
