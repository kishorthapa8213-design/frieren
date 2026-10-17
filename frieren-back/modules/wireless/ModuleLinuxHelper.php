<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 */

namespace frieren\modules\wireless;

use frieren\helper\LinuxHelper;

class ModuleLinuxHelper extends ModuleOpenWrtHelper
{
    private static function nmcli($arguments, $privileged = false)
    {
        $command = '/usr/bin/nmcli';
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg((string) $argument);
        }

        if ($privileged) {
            $sudoPermission = LinuxHelper::exec(
                'sudo -n -l -- /usr/bin/nmcli device status',
                true,
                true
            );
            if ($sudoPermission !== false) {
                $command = 'sudo -n ' . $command;
            }
        }

        return LinuxHelper::exec($command, false, true);
    }

    private static function monitorProfiles()
    {
        $profiles = LinuxHelper::uciGetJson('frieren.wireless_monitors', false);
        foreach ($profiles as $section => $profile) {
            if (!is_string($section)
                || !is_array($profile)
                || ($profile['mode'] ?? '') !== 'monitor'
                || !is_string($profile['radio'] ?? null)
                || !preg_match('/^[a-zA-Z0-9_.-]+$/', $profile['radio'])
            ) {
                throw new \RuntimeException('Invalid saved Linux monitor-mode profile');
            }
        }

        return $profiles;
    }

    private static function saveMonitorProfiles($profiles)
    {
        LinuxHelper::uciSetJson('frieren.wireless_monitors', $profiles);
    }

    private static function supportedWirelessMode($radio, $mode)
    {
        if (!is_string($radio) || !preg_match('/^[a-zA-Z0-9_.-]+$/', $radio)) {
            return false;
        }

        $deviceInfo = LinuxHelper::exec(
            '/usr/sbin/iw dev ' . escapeshellarg($radio) . ' info',
            true,
            true
        );
        if (!is_string($deviceInfo) || !preg_match('/\bwiphy\s+(\d+)/', $deviceInfo, $matches)) {
            return false;
        }

        $phyInfo = LinuxHelper::exec('/usr/sbin/iw phy phy' . (int) $matches[1] . ' info', true, true);
        if (!is_string($phyInfo)
            || !preg_match('/Supported interface modes:\s*(.*?)(?:Band \d+:|valid interface combinations:)/s', $phyInfo, $modes)
        ) {
            return false;
        }

        return preg_match('/^\s*\*\s+' . preg_quote($mode, '/') . '\s*$/m', $modes[1]) === 1;
    }

    private static function monitorSupported($radio)
    {
        return self::supportedWirelessMode($radio, 'monitor');
    }

    private static function monitorHelperAvailable()
    {
        if (!is_executable('/usr/local/sbin/frieren-wireless-monitor')) {
            return false;
        }

        $permissions = LinuxHelper::exec(
            'sudo -n -l /usr/local/sbin/frieren-wireless-monitor',
            true,
            true
        );

        return is_string($permissions)
            && strpos($permissions, '/usr/local/sbin/frieren-wireless-monitor') !== false;
    }

    private static function monitorIsUp($radio)
    {
        $deviceInfo = LinuxHelper::exec(
            '/usr/sbin/iw dev ' . escapeshellarg($radio) . ' info',
            true,
            true
        );
        if (!is_string($deviceInfo) || !preg_match('/^\s*type monitor\s*$/m', $deviceInfo)) {
            return false;
        }

        $linkInfo = LinuxHelper::exec(
            '/usr/sbin/ip -o link show dev ' . escapeshellarg($radio),
            true,
            true
        );

        return is_string($linkInfo) && preg_match('/\bstate UP\b/', $linkInfo) === 1;
    }

    private static function monitorCommand($action, $radio)
    {
        if (!is_string($radio) || !preg_match('/^[a-zA-Z0-9_.-]+$/', $radio)) {
            return false;
        }

        return LinuxHelper::exec(
            'sudo -n /usr/local/sbin/frieren-wireless-monitor '
                . escapeshellarg($action) . ' ' . escapeshellarg($radio),
            true,
            true
        ) !== false;
    }

    private static function activeMonitorOnRadio($radio)
    {
        foreach (self::monitorProfiles() as $profile) {
            if (($profile['radio'] ?? '') === $radio && empty($profile['disabled'])) {
                return true;
            }
        }

        return false;
    }

    private static function canStartMonitor($radio)
    {
        $device = self::wifiDevices()[$radio] ?? null;
        return $device !== null
            && $device['state'] !== 'connected'
            && $device['connection'] === ''
            && self::monitorSupported($radio);
    }

    private static function canStartAccessPoint($radio, $section = '')
    {
        $device = self::wifiDevices()[$radio] ?? null;
        return $device !== null
            && ($device['connection'] === '' || $device['connection'] === $section)
            && ($device['state'] !== 'connected' || $device['connection'] === $section)
            && !self::activeMonitorOnRadio($radio)
            && self::supportedWirelessMode($radio, 'AP');
    }

    private static function monitorProfileOnRadio($radio)
    {
        foreach (self::monitorProfiles() as $profile) {
            if (($profile['radio'] ?? '') === $radio) {
                return true;
            }
        }

        return false;
    }

    private static function updateReconRole(&$profiles, $section, $isRecon)
    {
        if ($isRecon) {
            foreach ($profiles as &$profile) {
                $profile['isRecon'] = false;
            }
            unset($profile);
        }

        if (isset($profiles[$section])) {
            $profiles[$section]['isRecon'] = (bool) $isRecon;
        }
    }

    private static function fields($name)
    {
        $output = self::nmcli([
            '-g',
            'connection.id,connection.interface-name,802-11-wireless.ssid,802-11-wireless.mode,802-11-wireless-security.key-mgmt,802-11-wireless-security.psk,802-11-wireless.hidden,802-11-wireless.bssid,connection.autoconnect',
            'connection',
            'show',
            $name,
        ]);

        return is_array($output) && count($output) >= 9 ? $output : [];
    }

    private static function wifiDevices()
    {
        $output = self::nmcli(['-t', '-e', 'yes', '-f', 'DEVICE,TYPE,STATE,CONNECTION', 'device', 'status']);
        if (!is_array($output)) {
            return [];
        }

        $devices = [];
        foreach ($output as $line) {
            $parts = str_getcsv($line, ':', '"', '\\');
            if (count($parts) >= 4 && $parts[1] === 'wifi') {
                $devices[$parts[0]] = [
                    'state' => $parts[2],
                    'connection' => $parts[3],
                ];
            }
        }

        return $devices;
    }

    public static function scanForNetworks($device)
    {
        if (!is_string($device) || !preg_match('/^[a-zA-Z0-9_.-]+$/', $device)) {
            return [];
        }

        $output = self::nmcli([
            '-t',
            '-e',
            'yes',
            '-f',
            'BSSID,SSID,CHAN,SIGNAL,SECURITY',
            'device',
            'wifi',
            'list',
            'ifname',
            $device,
            '--rescan',
            'yes',
        ]);
        if (!is_array($output)) {
            return [];
        }

        $networks = [];
        foreach ($output as $line) {
            $parts = str_getcsv($line, ':', '"', '\\');
            if (count($parts) < 5 || trim($parts[1]) === '') {
                continue;
            }

            $networks[] = [
                'bssid' => $parts[0],
                'ssid' => $parts[1],
                'channel' => (int) $parts[2],
                'signal' => (int) $parts[3],
                'quality' => (int) $parts[3],
                'security' => $parts[4] === '--' ? 'Open' : $parts[4],
            ];
        }

        return $networks;
    }

    public static function getWirelessOverview()
    {
        $overview = [];
        $devices = self::wifiDevices();
        $profiles = self::nmcli(['-t', '-e', 'yes', '-f', 'NAME,TYPE,DEVICE', 'connection', 'show']);
        $monitorProfiles = self::monitorProfiles();
        foreach ($devices as $device => $state) {
            $interfaces = [];
            foreach (is_array($profiles) ? $profiles : [] as $line) {
                $parts = str_getcsv($line, ':', '"', '\\');
                if (count($parts) < 3 || $parts[1] !== '802-11-wireless') {
                    continue;
                }

                $config = self::fields($parts[0]);
                if (!$config || ($config[1] !== $device && $config[1] !== '')) {
                    continue;
                }

                $interfaces[] = [
                    'radio' => $device,
                    'ifname' => $device,
                    'section' => $config[0],
                    'mode' => ($config[3] ?? '') === 'ap' ? 'ap' : 'sta',
                    'ssid' => $config[2],
                    'bssid' => $config[7],
                    'encryption' => ($config[4] ?? '') === 'sae'
                        ? 'WPA3-SAE'
                        : (($config[4] ?? '') === '' || ($config[4] ?? '') === '--' ? 'none' : 'WPA-PSK'),
                    'network' => '',
                    'hidden' => ($config[6] ?? '') === 'yes',
                    'up' => $state['state'] === 'connected' && $state['connection'] === $config[0],
                    'disabled' => ($config[8] ?? '') === 'no',
                ];
            }

            $monitorIsUp = false;
            foreach ($monitorProfiles as $section => $profile) {
                if (($profile['radio'] ?? '') !== $device) {
                    continue;
                }

                $monitorIsUp = empty($profile['disabled']) && self::monitorIsUp($device);
                $interfaces[] = [
                    'radio' => $device,
                    'ifname' => $device,
                    'section' => $section,
                    'mode' => 'monitor',
                    'ssid' => '',
                    'bssid' => '',
                    'encryption' => '',
                    'network' => '',
                    'hidden' => false,
                    'up' => $monitorIsUp,
                    'disabled' => (bool) ($profile['disabled'] ?? false),
                ];
            }

            $overview[$device] = [
                'channel' => null,
                'txpower' => null,
                'frequency' => null,
                'band' => '2.4 GHz / 5 GHz',
                'supportedBands' => ['2.4 GHz', '5 GHz'],
                'htmode' => 'NetworkManager managed',
                'up' => $monitorIsUp || $state['state'] === 'connected',
                'disabled' => false,
                'phy' => $device,
                'country' => null,
                'hardware' => null,
                'isUsb' => false,
                'hwmodes' => 'Managed by NetworkManager',
                'htmodes' => [],
                'interfaces' => $interfaces,
                'linuxWireless' => true,
                'wirelessModes' => self::availableWirelessModes($device, $state),
            ];
        }

        return $overview;
    }

    private static function availableWirelessModes($device, $state)
    {
        $modes = ['sta'];
        if ($state['connection'] !== '' || $state['state'] === 'connected'
            || self::monitorProfileOnRadio($device)
        ) {
            return $modes;
        }

        if (self::supportedWirelessMode($device, 'AP')) {
            $modes[] = 'ap';
        }
        if (self::monitorSupported($device) && self::monitorHelperAvailable()) {
            $modes[] = 'monitor';
        }

        return $modes;
    }

    public static function getRadioConfig($radio)
    {
        if (!isset(self::wifiDevices()[$radio])) {
            return ['current' => [], 'available' => []];
        }

        return [
            'current' => [
                'channel' => '',
                'txpower' => '',
                'htmode' => 'NetworkManager managed',
                'country' => '',
                'disabled' => '0',
            ],
            'available' => [
                'channels' => [],
                'txpowers' => [],
                'htmodes' => [],
                'countries' => [],
            ],
        ];
    }

    public static function getEncryptionOptions($radio, $mode)
    {
        return [
            'options' => [
                ['value' => 'none', 'label' => 'Open'],
                ['value' => 'psk2+ccmp', 'label' => 'WPA2 Personal'],
                ['value' => 'sae', 'label' => 'WPA3 Personal'],
            ],
        ];
    }

    public static function setRadioConfig($radio, $channel, $txpower, $htmode, $country, $disabled, $cellDensity = 0, $distance = 0)
    {
        return false;
    }

    private static function createProfileName($ssid)
    {
        return 'Frieren ' . substr(hash('sha256', $ssid), 0, 12) . '-' . bin2hex(random_bytes(2));
    }

    private static function saveWifiProfile($section, $radio, $ssid, $encryption, $key, $mode, $hidden, $bssid, $disabled = false)
    {
        if (!in_array($radio, array_keys(self::wifiDevices()), true)
            || $ssid === ''
            || !in_array($mode, ['ap', 'sta'], true)
        ) {
            return false;
        }

        $isAccessPoint = $mode === 'ap';
        $arguments = [
            'connection',
            'add',
            'type',
            'wifi',
            'ifname',
            $radio,
            'con-name',
            $section,
            'ssid',
            $ssid,
            '802-11-wireless.mode',
            $isAccessPoint ? 'ap' : 'infrastructure',
            'ipv4.method',
            $isAccessPoint ? 'shared' : 'auto',
            'ipv6.method',
            $isAccessPoint ? 'ignore' : 'auto',
        ];
        if ($hidden) {
            $arguments[] = '802-11-wireless.hidden';
            $arguments[] = 'yes';
        }
        $arguments[] = 'connection.autoconnect';
        $arguments[] = $disabled ? 'no' : 'yes';
        if ($bssid !== '') {
            $arguments[] = '802-11-wireless.bssid';
            $arguments[] = $bssid;
        }
        if ($encryption !== 'none') {
            $arguments[] = '802-11-wireless-security.key-mgmt';
            $arguments[] = $encryption === 'sae' ? 'sae' : 'wpa-psk';
            $arguments[] = '802-11-wireless-security.psk';
            $arguments[] = $key;
        }

        return self::nmcli($arguments, true) !== false;
    }

    private static function modifyWifiProfile($section, $ssid, $encryption, $key, $mode, $hidden, $disabled, $bssid)
    {
        $isAccessPoint = $mode === 'ap';
        $arguments = [
            'connection',
            'modify',
            $section,
            '802-11-wireless.ssid',
            $ssid,
            '802-11-wireless.mode',
            $isAccessPoint ? 'ap' : 'infrastructure',
            'ipv4.method',
            $isAccessPoint ? 'shared' : 'auto',
            'ipv6.method',
            $isAccessPoint ? 'ignore' : 'auto',
            '802-11-wireless.hidden',
            $hidden ? 'yes' : 'no',
            'connection.autoconnect',
            $disabled ? 'no' : 'yes',
            '802-11-wireless.bssid',
            $bssid,
        ];
        if ($encryption === 'none') {
            $arguments = array_merge($arguments, [
                '802-11-wireless-security.key-mgmt',
                '',
                '802-11-wireless-security.psk',
                '',
            ]);
        } else {
            $arguments = array_merge($arguments, [
                '802-11-wireless-security.key-mgmt',
                $encryption === 'sae' ? 'sae' : 'wpa-psk',
            ]);
            if ($key !== '') {
                $arguments = array_merge($arguments, [
                    '802-11-wireless-security.psk',
                    $key,
                ]);
            }
        }

        return self::nmcli($arguments, true) !== false;
    }

    private static function isProfileActive($section)
    {
        foreach (self::wifiDevices() as $state) {
            if ($state['connection'] === $section) {
                return true;
            }
        }

        return false;
    }

    public static function addInterface($radio, $ssid, $encryption, $key, $mode, $network, $hidden, $disabled, $isManagement = false, $isRecon = false, $ieee80211w = 0, $bssid = '')
    {
        if ($mode === 'monitor') {
            if (!self::canStartMonitor($radio)
                || self::monitorProfileOnRadio($radio)
            ) {
                return false;
            }

            $section = self::createProfileName('monitor-' . $radio);
            $monitorProfiles = self::monitorProfiles();
            $monitorProfiles[$section] = [
                'radio' => $radio,
                'mode' => 'monitor',
                'disabled' => (bool) $disabled,
                'isRecon' => (bool) $isRecon,
            ];
            self::updateReconRole($monitorProfiles, $section, $isRecon);
            self::saveMonitorProfiles($monitorProfiles);

            if (!$disabled && !self::monitorCommand('start', $radio)) {
                unset($monitorProfiles[$section]);
                self::saveMonitorProfiles($monitorProfiles);
                return false;
            }

            return $section;
        }

        if (!in_array($mode, ['ap', 'sta'], true)) {
            throw new \RuntimeException('Linux NetworkManager supports Wi-Fi client, access point, and monitor modes only.');
        }
        if (self::activeMonitorOnRadio($radio)) {
            return false;
        }
        if ($mode === 'ap') {
            if (!self::canStartAccessPoint($radio)) {
                return false;
            }
        }

        $section = self::createProfileName($ssid);
        if (!self::saveWifiProfile($section, $radio, $ssid, $encryption, $key, $mode, $hidden, $bssid, $disabled)) {
            return false;
        }

        if (!$disabled && self::nmcli(['connection', 'up', 'id', $section], true) === false) {
            self::nmcli(['connection', 'delete', 'id', $section], true);
            return false;
        }

        return $section;
    }

    public static function getInterfaceStatus($section)
    {
        $monitorProfiles = self::monitorProfiles();
        if (isset($monitorProfiles[$section])) {
            $profile = $monitorProfiles[$section];
            $up = empty($profile['disabled']) && self::monitorIsUp($profile['radio']);
            return [
                'state' => $up ? 'UP' : 'DOWN',
                'mode' => 'monitor',
                'ifname' => $profile['radio'],
            ];
        }

        $config = self::fields($section);
        if (!$config) {
            return ['state' => 'UNKNOWN', 'mode' => 'sta'];
        }

        foreach (self::wifiDevices() as $device => $state) {
            if ($state['connection'] === $section) {
                $mode = ($config[3] ?? '') === 'ap' ? 'ap' : 'sta';
                return [
                    'state' => $state['state'] === 'connected'
                        ? ($mode === 'ap' ? 'UP' : 'COMPLETED')
                        : 'DISCONNECTED',
                    'mode' => $mode,
                    'ssid' => $config[2],
                    'device' => $device,
                ];
            }
        }

        return [
            'state' => 'DISCONNECTED',
            'mode' => ($config[3] ?? '') === 'ap' ? 'ap' : 'sta',
            'ssid' => $config[2],
        ];
    }

    public static function removeInterface($section)
    {
        $monitorProfiles = self::monitorProfiles();
        if (isset($monitorProfiles[$section])) {
            $radio = $monitorProfiles[$section]['radio'];
            if (isset(self::wifiDevices()[$radio]) && !self::monitorCommand('stop', $radio)) {
                return false;
            }
            unset($monitorProfiles[$section]);
            self::saveMonitorProfiles($monitorProfiles);
            return true;
        }

        return self::fields($section) !== []
            && self::nmcli(['connection', 'delete', 'id', $section], true) !== false;
    }

    public static function toggleInterface($section, $disabled)
    {
        $monitorProfiles = self::monitorProfiles();
        if (isset($monitorProfiles[$section])) {
            $profile = $monitorProfiles[$section];
            $disabled = (bool) $disabled;
            if ($disabled !== (bool) $profile['disabled']) {
                if (!$disabled && !self::canStartMonitor($profile['radio'])) {
                    return false;
                }
                $action = $disabled ? 'disable' : 'start';
                if (!self::monitorCommand($action, $profile['radio'])) {
                    return false;
                }
            }

            $monitorProfiles[$section]['disabled'] = $disabled;
            self::saveMonitorProfiles($monitorProfiles);
            return true;
        }

        $config = self::fields($section);
        if ($config === []) {
            return false;
        }
        if (self::activeMonitorOnRadio($config[1] ?? '')) {
            return false;
        }
        if (!$disabled && ($config[3] ?? '') === 'ap'
            && !self::canStartAccessPoint($config[1] ?? '', $section)
        ) {
            return false;
        }

        if (self::nmcli(
            ['connection', 'modify', $section, 'connection.autoconnect', $disabled ? 'no' : 'yes'],
            true
        ) === false) {
            return false;
        }

        if ($disabled) {
            return !self::isProfileActive($section)
                || self::nmcli(['connection', 'down', 'id', $section], true) !== false;
        }

        return self::nmcli(['connection', 'up', 'id', $section], true) !== false;
    }

    public static function getInterfaceConfig($section)
    {
        $monitorProfiles = self::monitorProfiles();
        if (isset($monitorProfiles[$section])) {
            $profile = $monitorProfiles[$section];
            return [
                'device' => $profile['radio'],
                'network' => '',
                'mode' => 'monitor',
                'ssid' => '',
                'encryption' => 'none',
                'key' => '',
                'disabled' => !empty($profile['disabled']) ? '1' : '0',
                'hidden' => '0',
                'bssid' => '',
                'ieee80211w' => '',
                'isManagement' => '0',
                'isRecon' => !empty($profile['isRecon']) ? '1' : '0',
            ];
        }

        $config = self::fields($section);
        if (!$config) {
            return [];
        }

        return [
            'device' => $config[1],
            'network' => '',
            'mode' => ($config[3] ?? '') === 'ap' ? 'ap' : 'sta',
            'ssid' => $config[2],
            'encryption' => ($config[4] ?? '') === 'sae'
                ? 'sae'
                : (($config[4] ?? '') === '' || ($config[4] ?? '') === '--' ? 'none' : 'psk2'),
            'key' => ($config[5] ?? '') === '--' ? '' : ($config[5] ?? ''),
            'disabled' => ($config[8] ?? '') === 'no' ? '1' : '0',
            'hidden' => ($config[6] ?? '') === 'yes' ? '1' : '0',
            'bssid' => $config[7] ?? '',
            'ieee80211w' => '',
            'isManagement' => '0',
            'isRecon' => '0',
        ];
    }

    public static function setInterfaceConfig($section, $ssid, $encryption, $key, $mode, $network, $hidden, $disabled, $isManagement = false, $isRecon = false, $ieee80211w = 0, $bssid = '')
    {
        $monitorProfiles = self::monitorProfiles();
        if (isset($monitorProfiles[$section])) {
            if ($mode !== 'monitor') {
                return false;
            }

            $profile = $monitorProfiles[$section];
            $disabled = (bool) $disabled;
            if ($disabled !== (bool) $profile['disabled']) {
                if (!$disabled && !self::canStartMonitor($profile['radio'])) {
                    return false;
                }
                $action = $disabled ? 'disable' : 'start';
                if (!self::monitorCommand($action, $profile['radio'])) {
                    return false;
                }
            }

            $monitorProfiles[$section]['disabled'] = $disabled;
            self::updateReconRole($monitorProfiles, $section, $isRecon);
            self::saveMonitorProfiles($monitorProfiles);
            return true;
        }

        $config = self::fields($section);
        if (!$config) {
            return false;
        }
        if (!in_array($mode, ['ap', 'sta'], true)
            || self::activeMonitorOnRadio($config[1] ?? '')
            || (($config[3] ?? '') === 'ap') !== ($mode === 'ap')
        ) {
            throw new \RuntimeException('Change the Linux Wi-Fi mode by removing the interface and adding a new one.');
        }
        if (!$disabled && $mode === 'ap'
            && !self::canStartAccessPoint($config[1] ?? '', $section)
        ) {
            return false;
        }

        if (!self::modifyWifiProfile($section, $ssid, $encryption, $key, $mode, $hidden, $disabled, $bssid)) {
            return false;
        }

        if ($disabled) {
            return !self::isProfileActive($section)
                || self::nmcli(['connection', 'down', 'id', $section], true) !== false;
        }

        return self::nmcli(['connection', 'up', 'id', $section], true) !== false;
    }

    public static function getAssociationList($interface)
    {
        return [];
    }

    public static function getRawWirelessConfig()
    {
        return "Linux uses NetworkManager profiles. OpenWrt UCI wireless configuration is not available on this platform.";
    }

    public static function setRawWirelessConfig($content)
    {
        return false;
    }

    public static function resetWirelessConfig()
    {
        return false;
    }
}
