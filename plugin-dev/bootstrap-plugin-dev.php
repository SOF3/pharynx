#!/usr/bin/env php
<?php

$pluginPath = getenv("PLUGIN_PATH");
$pluginPath = realpath($pluginPath);
if($pluginPath === false) {
    echo "Cannot canonicalize $pluginPath\n";
    exit(1);
}

$pharPath = $argv[1];

$path = $pluginPath;
while(true) {
    $yamlPath = $path . "/.poggit.yml";
    if(is_file($yamlPath)) {
        break;
    }
    if(file_exists($path . "/.git")) {
        echo "Refusing to cross git repo boundary, exiting as success as no .poggit.yml detected\n";
        exit(0);
    }
    $newPath = dirname($path);
    if($newPath === $path) {
        echo "No .poggit.yml detected\n";
        exit(0);
    }
    $path = $newPath;
}

$yaml = yaml_parse_file($yamlPath);
$ok = false;
foreacH($yaml["projects"] ?? [] as $projectName => $project) {
    $subpath = $project["path"] ?? ".";
    $projectPath = realpath($path . "/" . $subpath);
    if($projectPath === $pluginPath) {
        $ok = true;
        break;
    }
}

if(!$ok) {
    echo ".poggit.yml does not contain project pointing to this plugin, exiting as success\n";
    exit(0);
}

foreach($project["libs"] ?? [] as $lib) {
    $src = $lib["src"];
    $split = explode("/", $src);
    if(count($split) === 2) {
        $src .= "/" . $split[1];
    }

    $version = $lib["version"];

    $cacheFile = ".cache/virion_cache_" . hash("fnv164", $src . "/" . $version) . ".phar";
    if(!file_exists($cacheFile)) {
        if(!is_dir(dirname($cacheFile))) {
            mkdir(dirname($cacheFile), 0750, true);
        }

        $cacheFh = fopen($cacheFile, "x");
        $curl = curl_init("https://poggit.pmmp.io/v.dl/$src/$version");
        curl_setopt($curl, CURLOPT_AUTOREFERER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_FILE, $cacheFh);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT_MS, 10000);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["User-Agent: pharynx-bootstrap/0.2"]);
        $ret = curl_exec($curl);
        if(!$ret) {
            exit(1);
        }
    }

    echo "Shading $cacheFile into $pharPath\n";
    passthru(implode(" ", array_map("escapeshellarg", [
        PHP_BINARY,
        "-dphar.readonly=0",
        $cacheFile,
        $pharPath,
    ])));
}
