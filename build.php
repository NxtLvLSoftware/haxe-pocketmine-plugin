<?php

declare(strict_types=1);

/**
 * Copyright (C) NxtLvL Software Solutions
 *
 * @author Jack Noordhuis
 *
 * This is free and unencumbered software released into the public domain.
 *
 * Anyone is free to copy, modify, publish, use, compile, sell, or
 * distribute this software, either in source code form or as a compiled
 * binary, for any purpose, commercial or non-commercial, and by any means.
 *
 * In jurisdictions that recognize copyright laws, the author or authors
 * of this software dedicate any and all copyright interest in the
 * software to the public domain. We make this dedication for the benefit
 * of the public at large and to the detriment of our heirs and
 * successors. We intend this dedication to be an overt act of
 * relinquishment in perpetuity of all present and future rights to this
 * software under copyright law.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS BE LIABLE FOR ANY CLAIM, DAMAGES OR
 * OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE,
 * ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
 * OTHER DEALINGS IN THE SOFTWARE.
 *
 * For more information, please refer to <http://unlicense.org/>
 *
 */

const PLUGIN_NAME = "haxe-pm-example";
const PLUGIN_VIRIONS = [

];
const PLUGIN_EXTRA_PATHS = [

];
const PLUGIN_STUB = '
<?php
echo "%s PocketMine-MP plugin v%s, developed by %s. Built on %s.
----------------
";
if(extension_loaded("phar")){
	$phar = new \Phar(__FILE__);
	foreach($phar->getMetadata() as $key => $value){
		echo ucfirst($key) . ": " . (is_array($value) ? implode(", ", $value) : $value) . "\n";
	}
}
__HALT_COMPILER();
';

function main(): void
{
    echo sprintf("== %s Build Script ==", PLUGIN_NAME) . PHP_EOL;

    if (ini_get("phar.readonly") == 1) {
        echo "Set phar.readonly to 0 with -dphar.readonly=0" . PHP_EOL;
        exit(1);
    }

    recurse_unlink(__DIR__ . "/out/phar");
    recurse_copy(__DIR__ . "/out/lib", __DIR__ . "/out/phar/src");
    recurse_copy(__DIR__ . "/resources", __DIR__ . "/out/phar/resources");
    copy(__DIR__ . "/plugin.yml", __DIR__ . "/out/phar/plugin.yml");

    $includedPaths = ["out/phar"] + PLUGIN_EXTRA_PATHS;
    array_walk($includedPaths, function (&$path, $key) {
        $realPath = realpath($path);
        if ($realPath === false) {
            echo "[ERROR] required directory `$path` does not exist or permission denied" . PHP_EOL;
            echo "[ERROR] have you installed composer dependencies?" . PHP_EOL;
            exit(1);
        }

        //Convert to absolute path for base path detection
        if (is_dir($realPath)) {
            $path = rtrim($realPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }
    });

    $basePath = rtrim(realpath(__DIR__ . "/out/phar"), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    //Convert included paths back to relative after we decide what the base path is
    $includedPaths = array_filter(array_map(function (string $path) use ($basePath) : string {
        return str_replace($basePath, '', $path);
    }, $includedPaths), function (string $v): bool {
        return $v !== '';
    });

    $pharName = PLUGIN_NAME . ".phar";

    if (!is_dir($basePath)) {
        echo $basePath . " is not a folder" . PHP_EOL;
        return;
    }

    echo PHP_EOL;

    $metadata = generatePluginMetadataFromYml($basePath . "plugin.yml");
    if ($metadata === null) {
        echo "Missing entry point or plugin.yml" . PHP_EOL;
        exit(1);
    }

    $stub = sprintf(PLUGIN_STUB, PLUGIN_NAME, $metadata["version"], $metadata["author"], date("r"));

    foreach (buildPhar(__DIR__ . DIRECTORY_SEPARATOR . $pharName, $basePath, $includedPaths, $metadata, $stub) as $line) {
        echo $line . PHP_EOL;
    }

    foreach (inject_virons() as $line) {
        echo $line . PHP_EOL;
    }
}

/**
 * @param string[] $strings
 * @param string|null $delim
 *
 * @return string[]
 */
function preg_quote_array(array $strings, string $delim = null): array
{
    return array_map(function (string $str) use ($delim) : string {
        return preg_quote($str, $delim);
    }, $strings);
}

/**
 * @param string $src
 * @param string $dst
 */
function recurse_copy(string $src, string $dst)
{
    if(!is_dir($src)) {
        return;
    }

    $dir = opendir($src);
    @mkdir($dst, 0777, true);
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src . '/' . $file)) {
                recurse_copy($src . '/' . $file, $dst . '/' . $file);
            } else {
                copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
}

/**
 * @param string $path
 */
function recurse_unlink(string $path) {
    if(is_dir($path)) {
        /** @var SplFileInfo $file */
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
    } elseif(is_file($path)) {
        unlink($path);
    }
}

/**
 * @param string $pharPath
 * @param string $basePath
 * @param string[] $includedPaths
 * @param array $metadata
 * @param string $stub
 * @param int $signatureAlgo
 * @param int|null $compression
 *
 * @return Generator|string[]
 */
function buildPhar(string $pharPath, string $basePath, array $includedPaths, array $metadata, string $stub, int $signatureAlgo = \Phar::SHA1, ?int $compression = null)
{
    if (file_exists($pharPath)) {
        yield "Phar file already exists, overwriting...";
        try {
            \Phar::unlinkArchive($pharPath);
        } catch (\PharException $e) {
            //unlinkArchive() doesn't like dodgy phars
            unlink($pharPath);
        }
    }

    yield "Adding files...";

    $start = microtime(true);
    $phar = new \Phar($pharPath);
    $phar->setMetadata($metadata);
    $phar->setStub($stub);
    $phar->setSignatureAlgorithm($signatureAlgo);
    $phar->startBuffering();

    //If paths contain any of these, they will be excluded
    $excludedSubstrings = preg_quote_array([
        realpath($pharPath), //don't add the phar to itself
    ], '/');

    $folderPatterns = preg_quote_array([
        DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR,
        DIRECTORY_SEPARATOR . '.' //"Hidden" files, git dirs etc
    ], '/');

    //Only exclude these within the basedir, otherwise the project won't get built if it itself is in a directory that matches these patterns
    $basePattern = preg_quote(rtrim($basePath, DIRECTORY_SEPARATOR), '/');
    foreach ($folderPatterns as $p) {
        $excludedSubstrings[] = $basePattern . '.*' . $p;
    }

    $regex = sprintf('/^(?!.*(%s))^%s(%s).*/i',
        implode('|', $excludedSubstrings), //String may not contain any of these substrings
        preg_quote($basePath, '/'), //String must start with this path...
        implode('|', preg_quote_array($includedPaths, '/')) //... and must be followed by one of these relative paths, if any were specified. If none, this will produce a null capturing group which will allow anything.
    );

    $directory = new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS | \FilesystemIterator::CURRENT_AS_PATHNAME); //can't use fileinfo because of symlinks
    $iterator = new \RecursiveIteratorIterator($directory);
    $regexIterator = new \RegexIterator($iterator, $regex);

    $count = count($phar->buildFromIterator($regexIterator, $basePath));
    yield "Added $count files";

    if ($compression !== null) {
        yield "Checking for compressible files...";
        foreach ($phar as $file => $finfo) {
            /** @var \PharFileInfo $finfo */
            if ($finfo->getSize() > (1024 * 512)) {
                yield "Compressing " . $finfo->getFilename();
                $finfo->compress($compression);
            }
        }
    }
    $phar->stopBuffering();

    yield "Done in " . round(microtime(true) - $start, 3) . "s";
}

function generatePluginMetadataFromYml(string $pluginYmlPath): ?array
{
    if (!file_exists($pluginYmlPath)) {
        return null;
    }

    $pluginYml = yaml_parse_file($pluginYmlPath);
    return [
        "name" => $pluginYml["name"],
        "version" => $pluginYml["version"],
        "main" => $pluginYml["main"],
        "api" => $pluginYml["api"],
        "depend" => $pluginYml["depend"] ?? "",
        "description" => $pluginYml["description"] ?? "",
        "author" => $pluginYml["author"] ?? "",
        "authors" => $pluginYml["authors"] ?? "",
        "website" => $pluginYml["website"] ?? "",
        "creationDate" => time()
    ];
}

/**
 * @return \Generator|string[]
 */
function inject_virons()
{
    @mkdir($libs = __DIR__ . DIRECTORY_SEPARATOR . "virions");
    foreach (PLUGIN_VIRIONS as $url) {
        $filename = end(explode("/", $url));

        if (!is_file($virion = $libs . DIRECTORY_SEPARATOR . $filename)) {
            yield "Downloading '{$url}'...";
            if (!copy($url, $virion)) {
                yield "Failed to download '{$url}', skipping virion.";
                continue;
            } else {
                yield "Downloaded '{url}', injecting virion...";
            }
        }

        passthru("\"" . PHP_BINARY . "\" \"{$virion}\" " . __DIR__ . DIRECTORY_SEPARATOR . PLUGIN_NAME . ".phar");
    }
}

main();