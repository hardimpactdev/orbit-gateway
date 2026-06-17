<?php

declare(strict_types=1);

namespace App\Support;

final class GitRepositoryReference
{
    public static function canonicalize(?string $repository): string|false|null
    {
        if ($repository === null) {
            return null;
        }

        if (self::isGithubShorthand($repository)) {
            return "git@github.com:{$repository}.git";
        }

        if (preg_match('/^(git@|https:\/\/|ssh:\/\/).+/', $repository)) {
            return $repository;
        }

        return false;
    }

    public static function cloneCommand(string $repository, string $path): string
    {
        $githubSlug = self::githubSlug($repository);
        $cloneCommand = $githubSlug !== null
            ? sprintf('gh repo clone %s %s', escapeshellarg($githubSlug), escapeshellarg($path))
            : sprintf('git clone %s %s', escapeshellarg($repository), escapeshellarg($path));

        return self::existingCheckoutGuard($repository, $path).PHP_EOL.$cloneCommand;
    }

    public static function transport(string $repository): string
    {
        if (self::githubSlug($repository) !== null) {
            return 'github';
        }

        return str_starts_with($repository, 'https://') ? 'https' : 'ssh';
    }

    public static function githubSlug(string $repository): ?string
    {
        if (self::isGithubShorthand($repository)) {
            return $repository;
        }

        $patterns = [
            '/^git@github\.com:(?<owner>[a-zA-Z0-9._-]+)\/(?<repo>[a-zA-Z0-9._-]+)$/',
            '/^https:\/\/github\.com\/(?<owner>[a-zA-Z0-9._-]+)\/(?<repo>[a-zA-Z0-9._-]+)\/?$/',
            '/^ssh:\/\/git@github\.com\/(?<owner>[a-zA-Z0-9._-]+)\/(?<repo>[a-zA-Z0-9._-]+)\/?$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $repository, $matches)) {
                $repositoryName = preg_replace('/\.git$/', '', $matches['repo']);

                if (! is_string($repositoryName)) {
                    return null;
                }

                return $matches['owner'].'/'.$repositoryName;
            }
        }

        return null;
    }

    private static function isGithubShorthand(string $repository): bool
    {
        return preg_match('/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/', $repository) === 1;
    }

    private static function existingCheckoutGuard(string $repository, string $path): string
    {
        $quotedPath = escapeshellarg($path);
        $expectedOrigins = implode('|', array_map(
            escapeshellarg(...),
            self::expectedOrigins($repository),
        ));

        return implode(PHP_EOL, [
            "if test -e {$quotedPath} && ! test -d {$quotedPath}; then",
            "  printf '%s\n' 'App path {$path} already exists and is not a directory.' >&2",
            '  exit 1',
            'fi',
            "if test -d {$quotedPath} && find {$quotedPath} -mindepth 1 -maxdepth 1 -print -quit | grep -q .; then",
            "  if ! test -d {$quotedPath}/.git; then",
            "    printf '%s\n' 'App path {$path} already exists and is not a git checkout.' >&2",
            '    exit 1',
            '  fi',
            "  origin=\"$(git -C {$quotedPath} remote get-url origin 2>/dev/null || true)\"",
            '  case "$origin" in',
            "    {$expectedOrigins}) exit 0 ;;",
            '    *)',
            "      printf '%s\n' \"App path {$path} already exists with origin '\$origin'.\" >&2",
            '      exit 1',
            '      ;;',
            '  esac',
            'fi',
        ]);
    }

    /**
     * @return list<string>
     */
    private static function expectedOrigins(string $repository): array
    {
        $githubSlug = self::githubSlug($repository);

        if ($githubSlug === null) {
            return [$repository];
        }

        return [
            "git@github.com:{$githubSlug}.git",
            "https://github.com/{$githubSlug}.git",
            "ssh://git@github.com/{$githubSlug}.git",
        ];
    }
}
