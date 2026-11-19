<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Yousef\LaraOrcest\Tests\TestCase;
use Yousef\LaraOrcest\Tools\ArtisanRunnerTool;
use Yousef\LaraOrcest\Tools\FileReaderTool;
use Yousef\LaraOrcest\Tools\GitCommandTool;
use Yousef\LaraOrcest\Tools\PatchWriterTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Tools\TestRunnerTool;

class ToolsContainerBindingTest extends TestCase
{
    public function test_tools_are_registered_as_singletons(): void
    {
        $this->assertTrue($this->app->bound(PathGuard::class));
        $this->assertTrue($this->app->bound(FileReaderTool::class));
        $this->assertTrue($this->app->bound(PatchWriterTool::class));
        $this->assertTrue($this->app->bound(ArtisanRunnerTool::class));
        $this->assertTrue($this->app->bound(TestRunnerTool::class));
        $this->assertTrue($this->app->bound(GitCommandTool::class));

        // Verify singleton behavior
        $pathGuard1 = $this->app->make(PathGuard::class);
        $pathGuard2 = $this->app->make(PathGuard::class);
        $this->assertSame($pathGuard1, $pathGuard2);

        $fileReader1 = $this->app->make(FileReaderTool::class);
        $fileReader2 = $this->app->make(FileReaderTool::class);
        $this->assertSame($fileReader1, $fileReader2);

        $patchWriter1 = $this->app->make(PatchWriterTool::class);
        $patchWriter2 = $this->app->make(PatchWriterTool::class);
        $this->assertSame($patchWriter1, $patchWriter2);

        $artisanRunner1 = $this->app->make(ArtisanRunnerTool::class);
        $artisanRunner2 = $this->app->make(ArtisanRunnerTool::class);
        $this->assertSame($artisanRunner1, $artisanRunner2);

        $testRunner1 = $this->app->make(TestRunnerTool::class);
        $testRunner2 = $this->app->make(TestRunnerTool::class);
        $this->assertSame($testRunner1, $testRunner2);

        $gitCommand1 = $this->app->make(GitCommandTool::class);
        $gitCommand2 = $this->app->make(GitCommandTool::class);
        $this->assertSame($gitCommand1, $gitCommand2);
    }

    public function test_tools_honor_configured_settings(): void
    {
        config()->set('lara-orcest.tools.allowed_artisan_commands', ['make:model', 'route:list']);
        config()->set('lara-orcest.tools.process_timeout', 45);

        // Re-resolve or instantiate
        $artisanTool = new ArtisanRunnerTool();
        $this->assertSame(['make:model', 'route:list'], $artisanTool->getAllowedCommands());

        $fileReaderTool = $this->app->make(FileReaderTool::class);
        $this->assertSame('file_reader', $fileReaderTool->getName());

        $patchWriterTool = $this->app->make(PatchWriterTool::class);
        $this->assertSame('patch_writer', $patchWriterTool->getName());
    }
}
