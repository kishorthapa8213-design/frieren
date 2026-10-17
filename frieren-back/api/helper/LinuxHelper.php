<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 * More info at: https://github.com/xchwarze/frieren
 */

namespace frieren\helper;

class LinuxHelper extends OpenWrtHelper
{
    const SETTINGS_FILE = '/var/lib/frieren/settings.json';
    const AUTH_FILE = '/var/lib/frieren/auth.json';

    public static function commandExists($commandName)
    {
        if (!is_string($commandName) || !preg_match('/^[a-zA-Z0-9_.+-]+$/', $commandName)) {
            return false;
        }

        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
            if (is_executable($directory . '/' . $commandName)) {
                return true;
            }
        }

        return false;
    }

    public static function checkDependency($dependencies)
    {
        if (!is_array($dependencies)) {
            return 'Invalid dependency list';
        }

        $missing = [];
        foreach ($dependencies as $dependency) {
            if (!is_string($dependency) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9+.-]*(:[a-zA-Z0-9]+)?$/', $dependency)) {
                $missing[] = (string) $dependency;
                continue;
            }

            $result = self::exec(
                'dpkg-query -W -f=' . escapeshellarg('${db:Status-Status}') . ' -- ' . escapeshellarg($dependency),
                true,
                true
            );
            if ($result !== 'installed') {
                $missing[] = $dependency;
            }
        }

        return $missing
            ? 'Missing dependencies: ' . implode(', ', $missing)
            : true;
    }

    public static function verifyPassword($username, $password)
    {
        if ($username !== 'admin' || !is_string($password)) {
            return false;
        }

        $auth = self::readJsonFile(self::authFile());
        return isset($auth['passwordHash'])
            && is_string($auth['passwordHash'])
            && password_verify($password, $auth['passwordHash']);
    }

    public static function downloadFile($url, $savePath, $flagPath)
    {
        $command = '/usr/bin/curl --fail --location --silent --show-error --max-time 60 --output '
            . escapeshellarg($savePath) . ' ' . escapeshellarg($url)
            . ' && /usr/bin/touch ' . escapeshellarg($flagPath);
        self::execBackground($command);
    }

    public static function fileGetContentsSSL($url)
    {
        if (extension_loaded('openssl')) {
            $context = stream_context_create(['http' => ['timeout' => 15]]);
            return file_get_contents($url, false, $context);
        }

        return self::exec(
            '/usr/bin/curl --fail --location --silent --show-error --max-time 15 ' . escapeshellarg($url),
            true,
            true
        );
    }

    public static function uciGetJson($uciString, $throwOnError = true)
    {
        $key = basename(str_replace('.', '/', $uciString));
        $settings = self::readJsonFile(self::settingsFile());

        if (!array_key_exists($key, $settings)) {
            if ($throwOnError) {
                throw new \Exception("Setting '{$key}' does not exist");
            }

            return [];
        }

        return is_array($settings[$key]) ? $settings[$key] : [];
    }

    public static function uciSetJson($settingString, $value, $autoCommit = true)
    {
        $key = basename(str_replace('.', '/', $settingString));
        $settings = self::readJsonFile(self::settingsFile());
        $settings[$key] = $value;

        self::writeJsonFile(self::settingsFile(), $settings);
    }

    public static function settings()
    {
        return self::readJsonFile(self::settingsFile());
    }

    public static function saveSettings($settings)
    {
        self::writeJsonFile(self::settingsFile(), $settings);
    }

    public static function savePassword($password)
    {
        $auth = self::readJsonFile(self::authFile());
        $auth['passwordHash'] = password_hash($password, PASSWORD_DEFAULT);
        self::writeJsonFile(self::authFile(), $auth);
    }

    private static function authFile()
    {
        $configured = getenv('FRIEREN_AUTH_FILE');
        return $configured !== false && $configured !== '' ? $configured : self::AUTH_FILE;
    }

    private static function settingsFile()
    {
        $configured = getenv('FRIEREN_SETTINGS_FILE');
        return $configured !== false && $configured !== '' ? $configured : self::SETTINGS_FILE;
    }

    private static function readJsonFile($path)
    {
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Unable to read {$path}");
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid JSON in {$path}");
        }

        return $decoded;
    }

    private static function writeJsonFile($path, $data)
    {
        $contents = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($contents === false) {
            throw new \RuntimeException('Unable to encode Linux settings');
        }

        $temporaryPath = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporaryPath, $contents . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write {$temporaryPath}");
        }

        if (!chmod($temporaryPath, 0600)) {
            @unlink($temporaryPath);
            throw new \RuntimeException("Unable to secure {$temporaryPath}");
        }

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException("Unable to replace {$path}");
        }
    }
}
