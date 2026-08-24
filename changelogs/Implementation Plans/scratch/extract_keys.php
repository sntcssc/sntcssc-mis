<?php

$dirs = [
    'c:/Users/nilan/Downloads/sntcssc-mis/resources/views',
    'c:/Users/nilan/Downloads/sntcssc-mis/app',
];

$keys = [];

foreach ($dirs as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iterator as $file) {
        if ($file->isFile() && in_array($file->getExtension(), ['php'])) {
            $content = file_get_contents($file->getPathname());
            if (preg_match_all("/__\(\s*'([^']+)'\s*[\),]/", $content, $m)) {
                foreach ($m[1] as $k) {
                    $keys[$k] = true;
                }
            }
            if (preg_match_all('/__\(\s*"([^"]+)"\s*[\),]/', $content, $m)) {
                foreach ($m[1] as $k) {
                    $keys[$k] = true;
                }
            }
        }
    }
}

ksort($keys);
echo 'Total translatable keys: '.count($keys)."\n";
file_put_contents(__DIR__.'/extracted_keys.json', json_encode(array_keys($keys), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
