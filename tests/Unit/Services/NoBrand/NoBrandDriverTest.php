<?php

namespace Tests\Unit\Services\NoBrand;

use App\Services\NoBrand\NoBrandDriver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class NoBrandDriverTest extends TestCase
{
    public function test_driver_pins_exact_upstream_release_and_checksum(): void
    {
        $this->assertSame('v3.2.2', NoBrandDriver::PINNED_VERSION);
        $this->assertSame(
            '37ba6fb4f35c7e032d05021782a090040af09337c5e95a42cbc0f8f0cf7d66c0',
            NoBrandDriver::INSTALLER_SHA256
        );

        $url = NoBrandDriver::installerUrl();

        $this->assertStringContainsString('/releases/download/v3.2.2/', $url);
        $this->assertStringNotContainsString('/releases/latest/', $url);
    }

    public function test_bootstrap_verifies_checksum_before_manager_install(): void
    {
        $command = NoBrandDriver::managerBootstrapCommand();

        $this->assertStringContainsString(NoBrandDriver::INSTALLER_SHA256, $command);
        $this->assertStringContainsString('sha256sum -c -', $command);
        $this->assertStringContainsString('manager install', $command);

        $checksumAt = strpos($command, 'sha256sum -c -');
        $installAt = strpos($command, 'manager install');

        $this->assertNotFalse($checksumAt);
        $this->assertNotFalse($installAt);
        $this->assertLessThan($installAt, $checksumAt);
    }

    public function test_only_structured_actions_are_allowed(): void
    {
        NoBrandDriver::assertActionAllowed('mieru.install');
        NoBrandDriver::assertActionAllowed('manager.status');
        $this->addToAssertionCount(1);

        $this->expectException(InvalidArgumentException::class);
        NoBrandDriver::assertActionAllowed('shell.exec');
    }

    public function test_companion_bootstrap_uses_structured_installer_arguments(): void
    {
        $command = NoBrandDriver::companionBootstrapCommand(
            'https://panel.example.com/',
            7,
            'machine-token'
        );

        $this->assertStringContainsString(NoBrandDriver::COMPANION_INSTALLER_URL, $command);
        $this->assertStringContainsString('--machine-id 7', $command);
        $this->assertStringContainsString('--panel', $command);
        $this->assertStringContainsString('--token', $command);
    }

    public function test_capabilities_are_explicit_about_phase_three_boundary(): void
    {
        $capabilities = NoBrandDriver::capabilities();

        $this->assertFalse($capabilities['vendored']);
        $this->assertTrue($capabilities['companion_required']);
        $this->assertTrue($capabilities['companion_implemented']);
        $this->assertSame('0.3.0', $capabilities['companion_version']);
        $this->assertSame(3, $capabilities['phase']);

        $this->assertContains('snell', $capabilities['upstream_protocols']);
        $this->assertNotContains('snell', $capabilities['panel_runtime_types']);
        $this->assertSame(['mieru'], $capabilities['implemented_runtime_protocols']);
    }
}
