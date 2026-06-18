<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

describe('install-orbit prebuilt CLI binary download contract', function (): void {
    beforeEach(function (): void {
        $this->installer = File::get(repo_path('bin/install-orbit'));
    });

    it('does NOT install host PHP packages — the CLI binary embeds its own PHP', function (): void {
        expect($this->installer)
            ->not->toContain('ppa:ondrej/php')
            ->not->toContain('php8.5-cli')
            ->not->toContain('php8.5-mbstring')
            ->not->toContain('php8.5-curl')
            ->not->toContain('php8.5-sqlite3')
            ->not->toContain('update-alternatives --set php');
    });

    it('does NOT install apps/cli Composer dependencies on the host — the binary is self-contained', function (): void {
        expect($this->installer)
            ->not->toContain('--workdir /opt/orbit/apps/cli')
            ->not->toContain('apps/cli composer install')
            ->not->toContain('apps/cli/vendor');
    });

    it('detects the host OS and architecture to select the correct prebuilt binary asset', function (): void {
        expect($this->installer)
            ->toContain('uname -s')
            ->toContain('uname -m')
            ->toContain('orbit-macos-arm64')
            ->toContain('orbit-linux-x64');
    });

    it('downloads the prebuilt CLI binary via authenticated gh release download and makes it executable', function (): void {
        expect($this->installer)
            ->toContain('download_cli_binary')
            ->toContain('gh release download')
            ->toContain('hardimpactdev/orbit')
            ->toContain('orbit-binary')
            ->toContain('chmod 0755');
    });

    it('defaults the host orbit link to the current user local bin directory', function (): void {
        expect($this->installer)
            ->toContain('LINK_PATH="${ORBIT_BIN_PATH:-$HOME/.local/bin/orbit}"')
            ->toContain('Defaults to "$HOME/.local/bin/orbit".');
    });

    it('honors ORBIT_BINARY_URL to skip gh and fetch via curl instead (local file:// artifacts or mirrors)', function (): void {
        expect($this->installer)
            ->toContain('ORBIT_BINARY_URL')
            ->toContain('curl -fsSL');
    });

    it('links the downloaded binary at the host orbit path and confirms it runs via --version', function (): void {
        expect($this->installer)
            ->toContain('run mkdir -p "$link_dir"')
            ->toContain('run ln -sf "$TARGET_DIR/bin/orbit-binary" "$LINK_PATH"')
            ->toContain('ln -sf "$TARGET_DIR/bin/orbit-binary" "$LINK_PATH"')
            ->toContain('"$LINK_PATH" --version');
    });

    it('keeps Python and the standalone SQLite CLI out of Orbit helper prerequisites', function (): void {
        expect($this->installer)
            ->not->toContain('python3');

        expect(preg_match_all('/^\s+sqlite3\s+\\\\$/m', $this->installer))->toBe(0);
    });
});
