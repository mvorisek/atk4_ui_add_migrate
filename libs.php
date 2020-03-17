<?php

namespace Refactor;

// from https://stackoverflow.com/a/53692871/5153116
function getDirContents(string $dir, string $excludeRegex = '~/\.git/~', int $onlyFiles = 0, int $maxDepth = -1): array {
    $results = [];
    $scanAll = scandir($dir);
    sort($scanAll);
    $scanDirs = []; $scanFiles = [];
    foreach($scanAll as $fName){
        if ($fName === '.' || $fName === '..') { continue; }
        $fPath = str_replace(DIRECTORY_SEPARATOR, '/', realpath($dir . '/' . $fName));
        if (strlen($excludeRegex) > 0 && preg_match($excludeRegex, $fPath . (is_dir($fPath) ? '/' : ''))) { continue; }
        if (is_dir($fPath)) {
            $scanDirs[] = $fPath;
        } elseif ($onlyFiles >= 0) {
            $scanFiles[] = $fPath;
        }
    }

    foreach ($scanDirs as $pDir) {
        if ($onlyFiles <= 0) {
            $results[] = $pDir;
        }
        if ($maxDepth !== 0) {
            foreach (getDirContents($pDir, $excludeRegex, $onlyFiles, $maxDepth - 1) as $p) {
                $results[] = $p;
            }
        }
    }
    foreach ($scanFiles as $p) {
        $results[] = $p;
    }

    return $results;
}

function updateKeysWithRelPath(array $paths, string $baseDir, bool $allowBaseDirPath = false): array {
    $results = [];
    $regex = '~^' . preg_quote(str_replace(DIRECTORY_SEPARATOR, '/', realpath($baseDir)), '~') . '(?:/|$)~s';
    $regex = preg_replace('~/~', '/(?:(?!\.\.?/)(?:(?!/).)+/\.\.(?:/|$))?(?:\.(?:/|$))*', $regex); // limited to only one "/xx/../" expr
    if (DIRECTORY_SEPARATOR === '\\') {
        $regex = preg_replace('~/~', '[/\\\\\\\\]', $regex) . 'i';
    }
    foreach ($paths as $p) {
        $rel = preg_replace($regex, '', $p, 1);
        if ($rel === $p) {
            throw new \Exception('Path relativize failed, path "' . $p . '" is not within basedir "' . $baseDir . '".');
        } elseif ($rel === '') {
            if (!$allowBaseDirPath) {
                throw new \Exception('Path relativize failed, basedir path "' . $p . '" not allowed.');
            } else {
                $results[$rel] = './';
            }
        } else {
            $results[$rel] = $p;
        }
    }
    return $results;
}

function getDirContentsWithRelKeys(string $dir, string $excludeRegex = '~/\.git/~', int $onlyFiles = 0, int $maxDepth = -1): array {
    return updateKeysWithRelPath(getDirContents($dir, $excludeRegex, $onlyFiles, $maxDepth), $dir);
}

function discoverClasses(string $fileName): array {
    $reader = new \hanneskod\classtools\Transformer\Reader(file_get_contents($fileName));
    $classNames = [];
    foreach ($reader->getDefinitionNames() as $name) {
        $classNames[] = $name;
    }

    return $classNames;
}