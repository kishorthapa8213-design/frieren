<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 */

use frieren\helper\HelperFactory;
use frieren\helper\LinuxHelper;
use frieren\modules\packages\ModuleLinuxHelper;
use PHPUnit\Framework\TestCase;

class LinuxHelperTest extends TestCase
{
    public function testFactoryCreatesLinuxCoreAndModuleHelpers(): void
    {
        $this->assertInstanceOf(LinuxHelper::class, HelperFactory::create('Linux'));
        $this->assertInstanceOf(
            \frieren\modules\network\ModuleLinuxHelper::class,
            HelperFactory::createModuleHelper('network', 'Linux')
        );
        $this->assertInstanceOf(
            \frieren\modules\system\ModuleLinuxHelper::class,
            HelperFactory::createModuleHelper('system', 'Linux')
        );
    }

    public function testPackageParserReadsDebianPackageControlOutput(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'frieren-packages-');
        self::assertNotFalse($file);

        try {
            file_put_contents(
                $file,
                "Package: network-manager\nVersion: 1.46.0-1\nDescription: Network management framework\n\n"
            );

            self::assertSame(
                [[
                    'name' => 'network-manager',
                    'version' => '1.46.0-1',
                    'description' => 'Network management framework',
                ]],
                ModuleLinuxHelper::parsePackageFile($file)
            );
        } finally {
            unlink($file);
        }
    }

    public function testLinuxAuthAndSettingsPathsCanBeOverriddenForDevelopment(): void
    {
        $directory = sys_get_temp_dir() . '/frieren-linux-helper-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $previousAuthFile = getenv('FRIEREN_AUTH_FILE');
        $previousSettingsFile = getenv('FRIEREN_SETTINGS_FILE');

        try {
            putenv("FRIEREN_AUTH_FILE={$directory}/auth.json");
            putenv("FRIEREN_SETTINGS_FILE={$directory}/settings.json");

            LinuxHelper::savePassword('local-dev-password');
            self::assertTrue(LinuxHelper::verifyPassword('admin', 'local-dev-password'));
            self::assertFalse(LinuxHelper::verifyPassword('admin', 'incorrect-password'));

            LinuxHelper::saveSettings(['wireless_monitors' => []]);
            self::assertSame(['wireless_monitors' => []], LinuxHelper::settings());
        } finally {
            $previousAuthFile === false
                ? putenv('FRIEREN_AUTH_FILE')
                : putenv("FRIEREN_AUTH_FILE={$previousAuthFile}");
            $previousSettingsFile === false
                ? putenv('FRIEREN_SETTINGS_FILE')
                : putenv("FRIEREN_SETTINGS_FILE={$previousSettingsFile}");
            @unlink("{$directory}/auth.json");
            @unlink("{$directory}/settings.json");
            rmdir($directory);
        }
    }
}
