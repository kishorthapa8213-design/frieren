<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 */

namespace frieren\modules\network;

use frieren\helper\LinuxHelper;

class ModuleLinuxHelper extends ModuleOpenWrtHelper
{
    private static function nmcli($arguments, $privileged = false)
    {
        $command = ($privileged ? 'sudo -n ' : '') . '/usr/bin/nmcli';
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg((string) $argument);
        }

        return LinuxHelper::exec($command, false, true);
    }

    private static function terseFields($line)
    {
        return str_getcsv($line, ':', '"', '\\');
    }

    private static function prefixFromNetmask($netmask)
    {
        $long = ip2long($netmask);
        if ($long === false) {
            return false;
        }

        $binary = sprintf('%032b', $long);
        if (strpos($binary, '01') !== false) {
            return false;
        }

        return substr_count($binary, '1');
    }

    private static function deviceInfo()
    {
        $output = LinuxHelper::exec('/usr/sbin/ip -j address show', true, true);
        $devices = json_decode((string) $output, true);
        return is_array($devices) ? $devices : [];
    }

    private static function activeProfiles()
    {
        $lines = self::nmcli(['-t', '-e', 'yes', '-f', 'DEVICE,TYPE,STATE,CONNECTION', 'device', 'status']);
        if (!is_array($lines)) {
            return [];
        }

        $profiles = [];
        foreach ($lines as $line) {
            $fields = self::terseFields($line);
            if (count($fields) >= 4) {
                $profiles[$fields[0]] = [
                    'type' => $fields[1],
                    'state' => $fields[2],
                    'connection' => $fields[3],
                ];
            }
        }

        return $profiles;
    }

    private static function connectionFields($name)
    {
        $fields = self::nmcli([
            '-g',
            'connection.id,connection.interface-name,ipv4.method,ipv4.addresses,ipv4.gateway,ipv4.dns,802-3-ethernet.mtu,802-3-ethernet.cloned-mac-address',
            'connection',
            'show',
            $name,
        ]);

        return is_array($fields) && count($fields) >= 8 ? $fields : [];
    }

    public static function getInterfaces()
    {
        $profiles = self::activeProfiles();
        $devices = self::deviceInfo();
        $byName = [];
        foreach ($devices as $device) {
            if (isset($device['ifname'])) {
                $byName[$device['ifname']] = $device;
            }
        }

        $connections = self::nmcli(['-t', '-e', 'yes', '-f', 'NAME,TYPE,DEVICE', 'connection', 'show']);
        $interfaces = [];
        foreach (is_array($connections) ? $connections : [] as $line) {
            $entry = self::terseFields($line);
            if (count($entry) < 3 || $entry[1] !== '802-3-ethernet') {
                continue;
            }

            $connectionName = $entry[0];
            $deviceName = $entry[2];
            $settings = self::connectionFields($connectionName);
            if (!$settings) {
                continue;
            }

            $deviceName = $deviceName !== '' && $deviceName !== '--' ? $deviceName : ($settings[1] ?? '');
            $active = $profiles[$deviceName] ?? null;
            $deviceName = $deviceName !== '' ? $deviceName : null;
            $device = $deviceName !== null ? ($byName[$deviceName] ?? []) : [];
            $ipv4 = null;
            $netmask = null;
            foreach ($device['addr_info'] ?? [] as $address) {
                if (($address['family'] ?? '') === 'inet') {
                    $ipv4 = $address['local'] ?? null;
                    $netmask = isset($address['prefixlen']) ? self::prefixToNetmask((int) $address['prefixlen']) : null;
                    break;
                }
            }

            $addresses = explode(',', $settings[3] ?? '');
            $configuredAddress = self::normalizeNmValue(trim($addresses[0] ?? ''));
            if ($configuredAddress !== '' && strpos($configuredAddress, '/') !== false) {
                [$configuredAddress, $prefix] = explode('/', $configuredAddress, 2);
                $netmask = self::prefixToNetmask((int) $prefix);
            }

            $interfaces[] = [
                'name' => $connectionName,
                'proto' => ($settings[2] ?? '') === 'manual'
                    ? 'static'
                    : (($settings[2] ?? '') === 'disabled' ? 'dhcpv6' : 'dhcp'),
                'up' => $active !== null && $active['connection'] === $connectionName && $active['state'] === 'connected',
                'ipaddr' => $ipv4 ?: ($configuredAddress ?: null),
                'netmask' => $netmask,
                'gateway' => self::normalizeNmValue($settings[4] ?? ''),
                'dns' => array_filter(explode(',', self::normalizeNmValue($settings[5] ?? '') ?? '')),
                'uptime' => 0,
                'device' => $deviceName,
                'mtu' => self::normalizeNmValue($settings[6] ?? ''),
                'macaddr' => self::normalizeNmValue($settings[7] ?? ''),
                'peerdns' => true,
            ];
        }

        return $interfaces;
    }

    private static function prefixToNetmask($prefix)
    {
        if ($prefix < 0 || $prefix > 32) {
            return null;
        }

        return long2ip(-1 << (32 - $prefix));
    }

    private static function normalizeNmValue($value)
    {
        return $value === '--' || $value === '' ? null : $value;
    }

    public static function interfaceExists($name)
    {
        return self::connectionFields($name) !== [];
    }

    public static function getAvailableDevices()
    {
        $devices = [];
        foreach (self::activeProfiles() as $name => $profile) {
            if ($name !== '' && $name !== 'lo' && $profile['type'] === 'ethernet') {
                $devices[] = $name;
            }
        }

        sort($devices);
        return $devices;
    }

    private static function configureConnection($name, $proto, $ipaddr, $netmask, $gateway, $dns, $mtu, $macaddr, $peerdns)
    {
        $arguments = ['connection', 'modify', $name];
        if ($proto === 'static') {
            $prefix = self::prefixFromNetmask($netmask);
            if ($prefix === false) {
                return false;
            }
            $arguments = array_merge($arguments, [
                'ipv4.method', 'manual',
                'ipv4.addresses', $ipaddr . '/' . $prefix,
                'ipv4.gateway', $gateway,
                'ipv4.dns', implode(',', $dns),
            ]);
        } elseif ($proto === 'dhcpv6') {
            $arguments = array_merge($arguments, [
                'ipv4.method', 'disabled',
                'ipv4.addresses', '',
                'ipv4.gateway', '',
                'ipv4.dns', '',
                'ipv6.method', 'auto',
            ]);
        } else {
            $arguments = array_merge($arguments, [
                'ipv4.method', 'auto',
                'ipv4.addresses', '',
                'ipv4.gateway', '',
                'ipv4.dns', '',
                'ipv6.method', 'auto',
            ]);
        }

        $arguments = array_merge($arguments, [
            'ipv4.ignore-auto-dns', $peerdns ? 'no' : 'yes',
            '802-3-ethernet.mtu', $mtu === '' ? '0' : (string) (int) $mtu,
            '802-3-ethernet.cloned-mac-address', $macaddr === '' ? 'permanent' : $macaddr,
        ]);

        return self::nmcli($arguments, true) !== false;
    }

    public static function addInterface($name, $device, $proto, $ipaddr, $netmask, $gateway, $dns, $mtu = '', $macaddr = '', $peerdns = true)
    {
        if (self::interfaceExists($name) || !in_array($device, self::getAvailableDevices(), true)) {
            return false;
        }

        $type = in_array($proto, ['dhcp', 'dhcpv6'], true) ? 'ethernet' : 'ethernet';
        $added = self::nmcli([
            'connection',
            'add',
            'type',
            $type,
            'ifname',
            $device,
            'con-name',
            $name,
        ], true);
        if ($added === false) {
            return false;
        }

        if (!self::configureConnection($name, $proto, $ipaddr, $netmask, $gateway, $dns, $mtu, $macaddr, $peerdns)
            || self::nmcli(['connection', 'up', 'id', $name], true) === false
        ) {
            self::nmcli(['connection', 'delete', 'id', $name], true);
            return false;
        }

        return true;
    }

    public static function removeInterface($name)
    {
        return self::interfaceExists($name)
            && self::nmcli(['connection', 'delete', 'id', $name], true) !== false;
    }

    public static function setInterface($name, $proto, $ipaddr, $netmask, $gateway, $dns, $mtu = '', $macaddr = '', $peerdns = true)
    {
        return self::interfaceExists($name)
            && self::configureConnection($name, $proto, $ipaddr, $netmask, $gateway, $dns, $mtu, $macaddr, $peerdns)
            && self::nmcli(['connection', 'up', 'id', $name], true) !== false;
    }

    public static function toggleInterface($name, $action)
    {
        if (!self::interfaceExists($name)) {
            return false;
        }

        $arguments = $action === 'down'
            ? ['connection', 'down', 'id', $name]
            : ['connection', 'up', 'id', $name];
        if ($action === 'restart') {
            self::nmcli(['connection', 'down', 'id', $name], true);
        }

        return self::nmcli($arguments, true) !== false;
    }

    public static function getDhcpLeases()
    {
        return [];
    }

    public static function getStaticLeases()
    {
        return [];
    }

    public static function staticLeaseExists($mac)
    {
        return false;
    }

    public static function addStaticLease($name, $mac, $ip)
    {
        return false;
    }

    public static function deleteStaticLease($mac)
    {
        return false;
    }
}
