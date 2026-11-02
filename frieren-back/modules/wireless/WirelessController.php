<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 * More info at: https://github.com/xchwarze/frieren
 */

namespace frieren\modules\wireless;

class WirelessController extends \frieren\core\Controller
{
    public $endpointRoutes = [
        'scanForNetworks' => true,
        'getWirelessOverview' => true,
        'getRadioConfig' => true,
        'getEncryptionOptions' => true,
        'setRadioConfig' => true,
        'getAssociationList' => true,
        'getInterfaceStatus' => true,
        'addInterface' => true,
        'removeInterface' => true,
        'toggleInterface' => true,
        'setUsbDeviceManaged' => true,
        'setAircrackMonitorMode' => true,
        'getInterfaceConfig' => true,
        'setInterfaceConfig' => true,
        'getRawWirelessConfig' => true,
        'setRawWirelessConfig' => true,
        'resetWirelessConfig' => true,
    ];

    public function scanForNetworks()
    {
        return self::setSuccess(
            self::setupModuleHelper()::scanForNetworks($this->request['device'])
        );
    }

    public function getWirelessOverview()
    {
        return self::setSuccess(
            self::setupModuleHelper()::getWirelessOverview()
        );
    }

    public function getRadioConfig()
    {
        return self::setSuccess(
            self::setupModuleHelper()::getRadioConfig($this->request['radio'])
        );
    }

    public function getEncryptionOptions()
    {
        return self::setSuccess(
            self::setupModuleHelper()::getEncryptionOptions(
                $this->request['radio'] ?? null,
                $this->request['mode'] ?? null
            )
        );
    }

    public function setRadioConfig()
    {
        if (self::setupModuleHelper()::setRadioConfig(
            $this->request['radio'],
            $this->request['channel'],
            $this->request['txpower'],
            $this->request['htmode'],
            $this->request['country'],
            $this->request['disabled'],
            $this->request['cell_density'] ?? 0,
            $this->request['distance'] ?? 0
        )) {
            return self::setSuccess();
        }

        self::setError('Error changing radio config.');
    }

    public function getAssociationList()
    {
        return self::setSuccess(
            self::setupModuleHelper()::getAssociationList($this->request['interface'])
        );
    }

    public function getInterfaceStatus()
    {
        return self::setSuccess(
            self::setupModuleHelper()::getInterfaceStatus($this->request['section'])
        );
    }

    public function addInterface()
    {
        $section = self::setupModuleHelper()::addInterface(
            $this->request['radio'],
            $this->request['ssid'],
            $this->request['encryption'],
            $this->request['key'],
            $this->request['mode'],
            $this->request['network'],
            $this->request['hidden'],
            $this->request['disabled'],
            $this->request['isManagement'] ?? false,
            $this->request['isRecon'] ?? false,
            $this->request['ieee80211w'] ?? 0,
            $this->request['bssid'] ?? ''
        );

        if ($section) {
            return self::setSuccess(['section' => $section]);
        }

        self::setError('Error adding interface.');
    }

    public function removeInterface()
    {
        if (self::setupModuleHelper()::removeInterface(
            $this->request['section']
        )) {
            return self::setSuccess();
        }

        self::setError('Error removing interface.');
    }

    public function toggleInterface()
    {
        if (self::setupModuleHelper()::toggleInterface(
            $this->request['section'],
            $this->request['disabled']
        )) {
            return self::setSuccess();
        }

        self::setError('Error toggling interface.');
    }

    public function setUsbDeviceManaged()
    {
        $device = $this->request['device'] ?? null;
        $managed = $this->request['managed'] ?? null;
        if (!is_string($device) || !is_bool($managed)) {
            return self::setError('Invalid wireless device management request.');
        }

        if (\DeviceConfig::systemFamily() !== 'Linux') {
            return self::setError('USB Wi-Fi management is only available on Linux.');
        }

        if (!self::setupModuleHelper()::setUsbDeviceManaged($device, $managed)) {
            return self::setError(
                'Could not update the USB adapter. Disconnect it from Wi-Fi and stop monitor mode before changing this setting.'
            );
        }

        return self::setSuccess(['managed' => $managed]);
    }

    public function setAircrackMonitorMode()
    {
        $device = $this->request['device'] ?? null;
        $enabled = $this->request['enabled'] ?? null;
        if (!is_string($device) || !is_bool($enabled)) {
            return self::setError('Invalid monitor-mode request.');
        }

        if (\DeviceConfig::systemFamily() !== 'Linux') {
            return self::setError('airmon-ng monitor mode is only available on Linux.');
        }

        if (!self::setupModuleHelper()::setAircrackMonitorMode($device, $enabled)) {
            return self::setError(
                'Could not change monitor mode. Disconnect the adapter from Wi-Fi and stop any other monitor mode first.'
            );
        }

        return self::setSuccess([
            'enabled' => $enabled,
        ]);
    }

    public function getInterfaceConfig()
    {
        return self::setSuccess(
            self::setupModuleHelper()::getInterfaceConfig($this->request['section'])
        );
    }

    public function setInterfaceConfig()
    {
        if (self::setupModuleHelper()::setInterfaceConfig(
            $this->request['section'],
            $this->request['ssid'],
            $this->request['encryption'],
            $this->request['key'],
            $this->request['mode'],
            $this->request['network'],
            $this->request['hidden'],
            $this->request['disabled'],
            $this->request['isManagement'] ?? false,
            $this->request['isRecon'] ?? false,
            $this->request['ieee80211w'] ?? 0,
            $this->request['bssid'] ?? ''
        )) {
            return self::setSuccess();
        }

        self::setError('Error updating interface config.');
    }

    public function getRawWirelessConfig()
    {
        return self::setSuccess([
            'content' => self::setupModuleHelper()::getRawWirelessConfig(),
            'editable' => \DeviceConfig::systemFamily() !== 'Linux',
        ]);
    }

    public function setRawWirelessConfig()
    {
        if (self::setupModuleHelper()::setRawWirelessConfig($this->request['content'])) {
            return self::setSuccess();
        }

        self::setError('Error writing wireless config.');
    }

    public function resetWirelessConfig()
    {
        if (self::setupModuleHelper()::resetWirelessConfig()) {
            return self::setSuccess();
        }

        self::setError('Error resetting wireless config.');
    }
}
