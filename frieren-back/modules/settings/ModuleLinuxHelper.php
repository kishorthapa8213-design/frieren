<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 */

namespace frieren\modules\settings;

use frieren\helper\LinuxHelper;

class ModuleLinuxHelper
{
    const MIN_SYNC_EPOCH = 1577836800;

    private static function settings()
    {
        return LinuxHelper::settings();
    }

    private static function saveSetting($key, $value)
    {
        $settings = self::settings();
        $settings[$key] = $value;
        LinuxHelper::saveSettings($settings);
    }

    private static function timezoneName()
    {
        if (is_readable('/etc/timezone')) {
            $timezone = trim(file_get_contents('/etc/timezone'));
            if ($timezone !== '') {
                return $timezone;
            }
        }

        $target = realpath('/etc/localtime');
        if ($target && strpos($target, '/zoneinfo/') !== false) {
            return substr($target, strpos($target, '/zoneinfo/') + 10);
        }

        return 'UTC';
    }

    public static function getSystemTimeZone()
    {
        $zone = self::timezoneName();
        $offset = (int) round((new \DateTimeZone($zone))->getOffset(new \DateTimeImmutable()) / 3600);

        return $offset === 0 ? 'GMT0' : 'GMT' . ($offset > 0 ? '+' : '') . $offset;
    }

    private static function timezoneFromBrowserValue($timezone)
    {
        if (!preg_match('/^GMT[+-]?(0|[1-9]|1[0-2])$/', $timezone)) {
            return false;
        }

        preg_match('/^GMT([+-]?)(\d+)$/', $timezone, $matches);
        $hours = (int) $matches[2];
        $hours *= ($matches[1] === '-' ? -1 : 1);

        return $hours === 0 ? 'Etc/UTC' : 'Etc/GMT' . ($hours > 0 ? '-' : '+') . abs($hours);
    }

    public static function changeSystemTimeZone($timezone)
    {
        $zone = self::timezoneFromBrowserValue($timezone);
        if ($zone === false) {
            return false;
        }

        if (self::timezoneName() === $zone) {
            return true;
        }

        return LinuxHelper::exec(
            'sudo -n /usr/bin/timedatectl set-timezone ' . escapeshellarg($zone),
            true,
            true
        ) !== false;
    }

    public static function syncDatetimeFromBrowser($datetime)
    {
        $epoch = filter_var($datetime, FILTER_VALIDATE_INT);
        if ($epoch === false || $epoch < self::MIN_SYNC_EPOCH) {
            return false;
        }

        return LinuxHelper::exec(
            'sudo -n /usr/bin/date -s ' . escapeshellarg('@' . $epoch),
            true,
            true
        ) !== false;
    }

    public static function applyBrowserDatetime($datetime, $timezone)
    {
        $timezoneChanged = self::changeSystemTimeZone($timezone);
        $timeChanged = self::syncDatetimeFromBrowser($datetime);
        return $timezoneChanged || $timeChanged;
    }

    public static function setSystemHostname($hostname)
    {
        return LinuxHelper::exec(
            'sudo -n /usr/bin/hostnamectl set-hostname ' . escapeshellarg($hostname),
            true,
            true
        ) !== false;
    }

    public static function changeUserPassword($current, $new)
    {
        if (!LinuxHelper::verifyPassword('admin', $current) || !is_string($new) || $new === '') {
            return false;
        }

        LinuxHelper::savePassword($new);
        return true;
    }

    public static function setPanelTheme($theme)
    {
        self::saveSetting('theme', $theme);
        return (self::settings()['theme'] ?? null) === $theme;
    }

    public static function saveTerminalSettings($theme, $fontSize, $cursorStyle, $cursorBlink, $autologin, $terminalEnabled)
    {
        if (!in_array($cursorStyle, ['block', 'underline', 'bar'], true) || $autologin) {
            return false;
        }

        $settings = self::settings();
        $settings['terminal_theme'] = $theme;
        $settings['terminal_font_size'] = max(8, min(32, (int) $fontSize));
        $settings['terminal_cursor_style'] = $cursorStyle;
        $settings['terminal_cursor_blink'] = (bool) $cursorBlink;
        $settings['terminal_autologin'] = (bool) $autologin;
        $settings['terminal_enabled'] = (bool) $terminalEnabled;
        LinuxHelper::saveSettings($settings);

        return true;
    }

    public static function getTerminalAutologin()
    {
        return (bool) (self::settings()['terminal_autologin'] ?? false);
    }

    public static function getTerminalTheme()
    {
        return self::settings()['terminal_theme'] ?? 'default';
    }

    public static function getTerminalFontSize()
    {
        return (int) (self::settings()['terminal_font_size'] ?? 13);
    }

    public static function getTerminalCursorStyle()
    {
        return self::settings()['terminal_cursor_style'] ?? 'block';
    }

    public static function getTerminalCursorBlink()
    {
        return (bool) (self::settings()['terminal_cursor_blink'] ?? false);
    }

    public static function isTerminalEnabled()
    {
        return (bool) (self::settings()['terminal_enabled'] ?? true);
    }

    public static function getSectionData()
    {
        $settings = self::settings();
        return [
            'hostname' => gethostname(),
            'timezone' => self::getSystemTimeZone(),
            'theme' => $settings['theme'] ?? 'auto',
            'terminalAutologin' => self::getTerminalAutologin(),
            'terminalTheme' => self::getTerminalTheme(),
            'fontSize' => self::getTerminalFontSize(),
            'cursorStyle' => self::getTerminalCursorStyle(),
            'cursorBlink' => self::getTerminalCursorBlink(),
            'terminalEnabled' => self::isTerminalEnabled(),
        ];
    }
}
