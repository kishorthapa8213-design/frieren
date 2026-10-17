<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 * More info at: https://github.com/xchwarze/frieren
 */

class DeviceConfig
{
    // Retained for compatibility with existing OpenWrt deployments.
    const GUESS_TYPE = 'OpenWrt';


    // Module behaviour
    const MODULE_ROOT_FOLDER = '/frieren/modules';
    const MODULE_SD_ROOT_FOLDER = '/sd/modules';
    const MODULE_USE_INTERNAL_STORAGE = true;
    const MODULE_USE_USB_STORAGE = true;
    const MODULE_HIDE_SYSTEM_MODULES = true;


    // Remote content
    const MODULE_SERVER_URL = 'https://raw.githubusercontent.com/xchwarze/frieren-modules-release/master';
    const MODULE_JSON_PATH = '%s/json/modules.json';
    const MODULE_PACKAGE_PATH = '%s/modules/%s';
    const NEWS_JSON_PATH = '%s/json/news.json';

    public static function systemFamily()
    {
        $configured = getenv('FRIEREN_SYSTEM_FAMILY');
        if ($configured !== false && in_array($configured, ['OpenWrt', 'Linux'], true)) {
            return $configured;
        }

        if (is_file('/etc/openwrt_release')) {
            return 'OpenWrt';
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $release = is_readable('/etc/os-release') ? file_get_contents('/etc/os-release') : '';
            preg_match('/^ID="?([^"\n]+)"?$/m', $release, $idMatch);
            preg_match('/^ID_LIKE="?([^"\n]*)"?$/m', $release, $likeMatch);
            $distribution = strtolower($idMatch[1] ?? '');
            $like = strtolower($likeMatch[1] ?? '');
            if (in_array($distribution, ['debian', 'ubuntu', 'kali'], true)
                || preg_match('/(^|\s)(debian|ubuntu)(\s|$)/', $like)) {
                return 'Linux';
            }

            throw new \RuntimeException('Frieren supports Linux only on Debian, Ubuntu, and Kali.');
        }

        return 'OpenWrt';
    }

    public static function moduleRootFolder()
    {
        $configured = getenv('FRIEREN_MODULE_ROOT');
        if ($configured !== false && $configured !== '') {
            return rtrim($configured, '/');
        }

        return self::systemFamily() === 'Linux'
            ? dirname(__DIR__, 2) . '/modules'
            : self::MODULE_ROOT_FOLDER;
    }

    public static function moduleSdRootFolder()
    {
        $configured = getenv('FRIEREN_MODULE_SD_ROOT');
        if ($configured !== false && $configured !== '') {
            return rtrim($configured, '/');
        }

        return self::systemFamily() === 'Linux' ? '/media/frieren/modules' : self::MODULE_SD_ROOT_FOLDER;
    }
}
