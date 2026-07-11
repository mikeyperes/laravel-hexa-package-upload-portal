<?php

namespace HexaPackageSmokeTests\LaravelHexaPackageUploadPortal;

use hexa_core\Support\PackageAssetRegistry;
use Tests\TestCase;

class FrontendArchitectureTest extends TestCase
{
    public function test_frontend_workflows_are_static_and_allowlisted(): void
    {
        $root = dirname(__DIR__, 2);
        $assets = app(PackageAssetRegistry::class)->assetsFor("upload-portal");

        foreach (["upload-portal.js"] as $asset) {
            $this->assertArrayHasKey($asset, $assets);
            $this->assertFileExists($assets[$asset]);
            $content = (string) file_get_contents($assets[$asset]);
            $this->assertDoesNotMatchRegularExpression('/@json|\{\{|\}\}|@(?:if|foreach|php|route)\b/', $content);
        }
    }

    public function test_view_references_external_workflow(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string) file_get_contents($root . "/resources/views/components/upload-portal.blade.php");

        $this->assertStringContainsString("upload-portal.js", $view);
        $this->assertStringNotContainsString("function uploadPortalModal", $view);
    }
}
