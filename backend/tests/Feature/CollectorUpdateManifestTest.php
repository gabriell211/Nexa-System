<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class CollectorUpdateManifestTest extends TestCase
{
    private const URL = '/api/v1/collector/updates/';

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function test_no_signed_release_is_safe_and_returns_no_update(): void
    {
        config()->set('nexa.collector_updates.manifest_json', '');
        $this->getJson(self::URL.'windows/x86_64/0.1.6')
            ->assertNoContent()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_valid_release_is_returned_only_to_compatible_older_installations(): void
    {
        $signature = "untrusted comment: signature from private release key\n".str_repeat('A', 100);
        $manifest = [
            'version' => '0.1.7',
            'notes' => 'Atualização do coletor',
            'platforms' => [
                'windows-x86_64' => [
                    'url' => 'https://github.com/gabriell211/Nexa-System/releases/download/v0.1.7/nexa.nsis.zip',
                    'signature' => $signature,
                ],
            ],
        ];
        config()->set('nexa.collector_updates.manifest_json', json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->getJson(self::URL.'windows/x86_64/0.1.6')
            ->assertOk()
            ->assertJsonPath('version', '0.1.7')
            ->assertJsonPath('signature', $signature)
            ->assertJsonMissingPath('platforms');
        $this->getJson(self::URL.'windows/x86_64/0.1.7')->assertNoContent();
        $this->getJson(self::URL.'darwin/aarch64/0.1.6')->assertNoContent();
    }

    public function test_malformed_versions_and_unknown_platforms_are_rejected(): void
    {
        $this->getJson(self::URL.'android/armv7/0.1.6')->assertUnprocessable();
        $this->getJson(self::URL.'windows/x86_64/banana')->assertUnprocessable();
    }

    public function test_unsigned_or_insecure_artifacts_fail_closed(): void
    {
        foreach ([
            ['url'=>'http://example.com/installer.exe', 'signature'=>str_repeat('x', 90)],
            ['url'=>'https://example.com/installer.exe', 'signature'=>'missing'],
        ] as $artifact) {
            config()->set('nexa.collector_updates.manifest_json', json_encode([
                'version' => '0.1.7',
                'platforms' => ['windows-x86_64' => $artifact],
            ], JSON_THROW_ON_ERROR));
            $this->getJson(self::URL.'windows/x86_64/0.1.6')->assertStatus(503);
        }
    }

    public function test_malformed_manifest_fails_closed_without_leaking_content(): void
    {
        config()->set('nexa.collector_updates.manifest_json', '{invalid');
        $this->getJson(self::URL.'windows/x86_64/0.1.6')
            ->assertStatus(503)
            ->assertDontSee('invalid');
    }
}
