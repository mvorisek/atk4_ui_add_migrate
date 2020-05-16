<?php

function getDirContents(string $dir, int $onlyFiles = 0, string $excludeRegex = '~/\.git/~', int $maxDepth = -1): array {
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
            foreach (getDirContents($pDir, $onlyFiles, $excludeRegex, $maxDepth - 1) as $p) {
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

function getDirContentsWithRelKeys(string $dir, int $onlyFiles = 0, string $excludeRegex = '~/\.git/~', int $maxDepth = -1): array {
    return updateKeysWithRelPath(getDirContents($dir, $onlyFiles, $excludeRegex, $maxDepth), $dir);
}

$usesByCl = [];
foreach (['mahalux\atk4-ui-cust', 'atk4\core', 'atk4\data', 'atk4\schema', 'atk4\dsql'] as $p) {
    $files = getDirContentsWithRelKeys('C:\sync\wlocal\kelly-atk\mvorisek-php-atk\vendor/' . $p . '/src', 1);
    foreach ($files as $k => $v) {
        $k = str_replace('/', '\\', $k);
        [$ns, $cl] = preg_split('~^(\w+)(\\\\\w++)*\K\\\\~s', preg_replace('~^atk4-ui-cust\\\\~', 'ui\\', preg_replace('~\.php$~', '', basename($p) . '\\' . $k)), 2);

        if (strpos($ns, '\\') !== false) { // import root/parent level only
            $cl = basename($ns);
            $ns = dirname($ns);
        }

        $ns = 'atk4\\' . $ns;

        $usesByCl[$cl][] = $ns . '\\' . $cl;
        $usesByCl[$cl] = array_unique($usesByCl[$cl]);
    }
}

// fix conflicts
$usesByCl['Exception'] = array_slice($usesByCl['Exception'], 0, 1, true); // use Exception from ui
$usesByCl['Persistence'] = array_slice($usesByCl['Persistence'], 1, 1, true); // use Persistence from data
unset($usesByCl['Locale']);
foreach ($usesByCl as $k => $v) {
    if (count($v) > 1) {
        var_dump($v);exit;
    }
}

// fix files
foreach (getDirContentsWithRelKeys('C:\sync\wlocal\kelly-atk\mvorisek-php-atk\vendor\mahalux\atk4-ui-cust\demos', 1) as $f) {
    $dat = file_get_contents($f);

    $dat = preg_replace_callback('~^<\?php\s*+(?:declare\([^()]+\);)?\s*+\K(?!namespace)~s', function($matches) use ($usesByCl) {
        return "\n\n" . 'namespace atk4\ui\demo;' . "\n\n"/* . implode("\n", array_map(function($v) {
            return 'use ' . reset($v) . ';';
        }, $usesByCl)) . "\n\n"*/;
    }, $dat);

    $dat = preg_replace('~(?<!\\\\|namespace )(?=atk4\\\\)~', '\\\\', $dat);

    file_put_contents($f, $dat);
}

// and run CS fixer then!
