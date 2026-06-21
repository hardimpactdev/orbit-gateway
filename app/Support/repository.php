<?php

declare(strict_types=1);

if (! function_exists('repo_path')) {
    function repo_path(string $path = ''): string
    {
        $basePath = base_path();
        $root = basename($basePath) === 'gateway' && basename(dirname($basePath)) === 'apps'
            ? dirname($basePath, 2)
            : $basePath;

        if ($path === '') {
            return $root;
        }

        return $root.'/'.ltrim($path, '/');
    }
}
