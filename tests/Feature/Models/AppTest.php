<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

describe('App model', function (): void {
    it('persists the canonical app registry fields', function (): void {
        $node = Node::factory()->create([
            'name' => 'app-1',
        ]);

        $app = App::query()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'domain' => 'docs.example.com',
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'repository' => 'git@github.com:orbit/docs.git',
            'php_version' => '8.5',
            'adopted' => false,
        ]);

        $app->refresh();

        expect($app->name)->toBe('docs')
            ->and($app->node)->toBeInstanceOf(Node::class)
            ->and($app->node->is($node))->toBeTrue()
            ->and($app->environment)->toBe('development')
            ->and($app->url())->toBe('https://docs.example.com')
            ->and($app->documentRootPath())->toBe('/home/orbit/apps/docs/public')
            ->and($app->repository)->toBe('git@github.com:orbit/docs.git')
            ->and($app->php_version)->toBe('8.5')
            ->and($app->adopted)->toBeFalse();
    });

    it('defaults optional registry fields for a development app', function (): void {
        $node = Node::factory()->create([
            'name' => 'dev-1',
            'tld' => 'test',
        ]);

        $app = App::query()->create([
            'name' => 'api',
            'node_id' => $node->id,
            'path' => '/srv/api',
        ]);

        $app->refresh();

        expect($app->document_root)->toBe('public')
            ->and($app->repository)->toBeNull()
            ->and($app->php_version)->toBe('8.5')
            ->and($app->adopted)->toBeFalse()
            ->and($app->environment)->toBe('development')
            ->and($app->url())->toBe('https://api.test');
    });

    it('creates the apps table with the registry indexes needed by read commands', function (): void {
        expect(Schema::hasTable('apps'))->toBeTrue()
            ->and(Schema::hasColumns('apps', [
                'id',
                'name',
                'node_id',
                'environment',
                'domain',
                'path',
                'document_root',
                'repository',
                'php_version',
                'adopted',
                'created_at',
                'updated_at',
            ]))->toBeTrue();
    });
});
