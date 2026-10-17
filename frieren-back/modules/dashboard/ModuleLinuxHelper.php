<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 */

namespace frieren\modules\dashboard;

class ModuleLinuxHelper extends ModuleOpenWrtHelper
{
    public static function getUbusSystemBoard()
    {
        $release = [];
        if (is_readable('/etc/os-release')) {
            foreach (file('/etc/os-release', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $matches)) {
                    $release[$matches[1]] = trim($matches[2], "\"'");
                }
            }
        }

        $prettyName = $release['PRETTY_NAME'] ?? 'Linux';
        $version = $release['VERSION_ID'] ?? '';
        return [
            'model' => $release['PRETTY_NAME'] ?? 'Linux',
            'hostname' => gethostname() ?: 'Linux host',
            'system' => php_uname('m') . ' GNU/Linux',
            'kernel' => php_uname('r'),
            'release' => [
                'distribution' => $release['ID'] ?? 'Linux',
                'version' => $version,
                'revision' => '',
                'target' => 'x86_64',
                'description' => $prettyName,
            ],
            'board_name' => $release['ID'] ?? 'linux',
        ];
    }

    public static function getUbusSystemInfo()
    {
        $memory = [];
        foreach (file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s+kB$/', $line, $matches)) {
                $memory[$matches[1]] = (int) $matches[2] * 1024;
            }
        }

        $uptime = (int) (explode(' ', file_get_contents('/proc/uptime'))[0] ?? 0);
        $load = sys_getloadavg();
        $cores = max(1, count(glob('/sys/devices/system/cpu/cpu[0-9]*') ?: []));
        $totalMemory = $memory['MemTotal'] ?? 0;
        $availableMemory = $memory['MemAvailable'] ?? 0;
        $swapTotal = $memory['SwapTotal'] ?? 0;
        $swapFree = $memory['SwapFree'] ?? 0;

        return [
            'cpu_cores' => $cores,
            'cpu_usage' => min(100, round((($load[0] ?? 0) / $cores) * 100, 1)) . '%',
            'memory_used' => ($totalMemory > 0
                ? round((($totalMemory - $availableMemory) / $totalMemory) * 100, 2)
                : 0) . '%',
            'swap_used' => ($swapTotal > 0
                ? round((($swapTotal - $swapFree) / $swapTotal) * 100, 2)
                : 0) . '%',
            'uptime' => self::secondsToUptime($uptime),
            'localtime' => date('Y-m-d H:i:s'),
        ];
    }
}
