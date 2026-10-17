<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 */

namespace frieren\modules\system;

use frieren\helper\LinuxHelper;

class ModuleLinuxHelper extends ModuleOpenWrtHelper
{
    const CRITICAL_SERVICES = [
        'NetworkManager.service',
        'systemd-networkd.service',
        'ssh.service',
        'sshd.service',
        'nginx.service',
        'php-fpm.service',
        'php8.2-fpm.service',
        'php8.3-fpm.service',
        'php8.4-fpm.service',
    ];

    public static function getSystemLogs($searchPattern = null)
    {
        $command = 'journalctl -n ' . self::SYSTEM_LOGS_LIMIT . ' -o json --no-pager';
        if ($searchPattern !== null && $searchPattern !== '') {
            $command .= ' --grep=' . escapeshellarg($searchPattern);
        }

        $output = LinuxHelper::exec($command, false, true);
        if ($output === false) {
            return false;
        }

        $logs = [];
        foreach ($output as $line) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }

            $timestamp = isset($entry['__REALTIME_TIMESTAMP'])
                ? (int) floor((int) $entry['__REALTIME_TIMESTAMP'] / 1000000)
                : null;
            $logs[] = [
                'timestamp' => $timestamp,
                'tag' => $entry['SYSLOG_IDENTIFIER'] ?? $entry['_COMM'] ?? 'system',
                'process' => $entry['_PID'] ?? '',
                'message' => $entry['MESSAGE'] ?? '',
            ];
        }

        return array_reverse($logs);
    }

    public static function serviceExists($name)
    {
        return LinuxHelper::exec(
            'systemctl show --property=LoadState --value ' . escapeshellarg($name . '.service'),
            true,
            true
        ) === 'loaded';
    }

    public static function listServices()
    {
        $output = LinuxHelper::exec('systemctl list-unit-files --type=service --no-legend --no-pager', false, true);
        if (!is_array($output)) {
            return [];
        }

        $runningOutput = LinuxHelper::exec(
            'systemctl list-units --type=service --state=active --no-legend --no-pager',
            false,
            true
        );
        $running = [];
        foreach (is_array($runningOutput) ? $runningOutput : [] as $line) {
            $unit = strtok(trim($line), " \t");
            if ($unit !== false) {
                $running[$unit] = true;
            }
        }

        $services = [];
        foreach ($output as $line) {
            $parts = preg_split('/\s+/', trim($line));
            $unit = $parts[0] ?? '';
            if ($unit === '' || !str_ends_with($unit, '.service')) {
                continue;
            }
            $name = substr($unit, 0, -8);
            $services[] = [
                'name' => $name,
                'enabled' => in_array($parts[1] ?? '', ['enabled', 'enabled-runtime'], true),
                'running' => isset($running[$unit]),
                'critical' => in_array($unit, self::CRITICAL_SERVICES, true),
            ];
        }

        usort($services, function ($left, $right) {
            return strcmp($left['name'], $right['name']);
        });

        return $services;
    }

    public static function serviceState($name)
    {
        $unit = escapeshellarg($name . '.service');
        return [
            'enabled' => LinuxHelper::exec("systemctl is-enabled {$unit}", true, true) === 'enabled',
            'running' => LinuxHelper::exec("systemctl is-active {$unit}", true, true) === 'active',
        ];
    }

    public static function controlService($name, $command)
    {
        return LinuxHelper::exec(
            'sudo -n /usr/bin/systemctl ' . escapeshellarg($command) . ' ' . escapeshellarg($name . '.service'),
            true,
            true
        ) !== false;
    }

    public static function setServiceEnabled($name, $enabled)
    {
        $action = $enabled ? 'enable' : 'disable';
        return LinuxHelper::exec(
            'sudo -n /usr/bin/systemctl ' . $action . ' ' . escapeshellarg($name . '.service'),
            true,
            true
        ) !== false;
    }
}
