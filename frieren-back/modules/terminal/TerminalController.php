<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 * More info at: https://github.com/xchwarze/frieren
 */

namespace frieren\modules\terminal;

use frieren\helper\OpenWrtHelper;
use frieren\modules\network\ModuleOpenWrtHelper as NetworkHelper;
use frieren\modules\settings\ModuleOpenWrtHelper as SettingsHelper;

class TerminalController extends \frieren\core\Controller
{
    const TTYD_PATH = "/usr/bin/ttyd";
    const TTYD_SD_PATH = "/sd/usr/bin/ttyd";
    const LAN_INTERFACE = 'lan';
    const FALLBACK_LAN_DEVICE = 'br-lan';

    public $endpointRoutes = [
        'startTerminal' => true,
        'stopTerminal' => true,
        'getStatus' => true,
    ];

    // ttyd is launched via nohup (execBackground) and forks asynchronously, so an
    // immediate pgrep can miss it and report a spurious failure — this is why the
    // very first open after install fails while later ones succeed. Poll briefly to
    // let the process spawn and bind port 5001 before reporting status.
    const START_POLL_ATTEMPTS = 10;
    const START_POLL_INTERVAL_US = 150000; // 150ms -> up to ~1.5s total

    private function getTerminalPath()
    {
        if (\DeviceConfig::systemFamily() === 'Linux') {
            return '/usr/bin/ttyd';
        }

        if (OpenWrtHelper::isSDAvailable() && file_exists(self::TTYD_SD_PATH)) {
            return self::TTYD_SD_PATH;
        }

        return self::TTYD_PATH;
    }

    /**
     * Resolves the device backing the `lan` interface, which ttyd binds to.
     * Not every board bridges its LAN, so the `br-lan` literal this used to
     * hardcode simply does not exist on some of them (ttyd then fails to bind
     * with no visible error); the fallback keeps the common case unchanged
     * when netifd reports nothing.
     *
     * @return string Device name (e.g. br-lan, eth0).
     */
    private function getLanDevice()
    {
        foreach (NetworkHelper::getInterfaces() as $interface) {
            if ($interface['name'] === self::LAN_INTERFACE && !empty($interface['device'])) {
                return $interface['device'];
            }
        }

        return self::FALLBACK_LAN_DEVICE;
    }

    private function waitForRunning($terminal)
    {
        // Sleep before each check: ttyd never appears in under one interval, so the
        // happy path costs a single pgrep instead of two or three.
        for ($attempt = 0; $attempt < self::START_POLL_ATTEMPTS; $attempt++) {
            usleep(self::START_POLL_INTERVAL_US);
            if (OpenWrtHelper::checkRunning($terminal)) {
                return true;
            }
        }

        return false;
    }

    public function startTerminal()
    {
        $settingsHelper = \frieren\helper\HelperFactory::createModuleHelper('settings', \DeviceConfig::systemFamily());
        if (!$settingsHelper::isTerminalEnabled()) {
            return self::setError('Terminal is disabled');
        }

        if (\DeviceConfig::systemFamily() === 'Linux') {
            $terminal = $this->getTerminalPath();
            if (!is_executable($terminal)) {
                return self::setError('ttyd is not installed');
            }
            if ($settingsHelper::getTerminalAutologin()) {
            return self::setError('Automatic terminal login is not supported on Linux; sign in with a system account.');
            }

            $status = OpenWrtHelper::checkRunning('ttyd');
            if (!$status) {
                OpenWrtHelper::execBackground("sudo -n {$terminal} -W -p 5001 -i lo /bin/login");
                $status = $this->waitForRunning('ttyd');
            }

            if (!$status) {
                return self::setError('Unable to start ttyd');
            }

            return self::setSuccess([
                'success' => true,
                'terminalTheme' => $settingsHelper::getTerminalTheme(),
                'fontSize' => $settingsHelper::getTerminalFontSize(),
                'cursorStyle' => $settingsHelper::getTerminalCursorStyle(),
                'cursorBlink' => $settingsHelper::getTerminalCursorBlink(),
            ]);
        }

        // disable ttyd instance
        exec("/etc/init.d/ttyd stop");
        OpenWrtHelper::execBackground("/etc/init.d/ttyd disable");

        // terminal implementation
        $terminal = $this->getTerminalPath();
        $status = OpenWrtHelper::checkRunning($terminal);
        if (!$status) {
            $shell = SettingsHelper::getTerminalAutologin() ? '/bin/ash' : '/bin/login';
            // Launch from /root so the (autologin) shell opens there instead of
            // inheriting the PHP process cwd (/usr/share/frieren/api). The cd is
            // confined to this subshell; the paths are fixed and the bind device
            // comes from netifd, so no user input reaches this command.
            $command = "sh -c 'cd /root && {$terminal} -p 5001 -i {$this->getLanDevice()} {$shell}'";
            OpenWrtHelper::execBackground($command);
            $status = $this->waitForRunning($terminal);
            if (!$status) {
                $this->logger("Terminal could not be run! command exec: {$command}");
            }
        }

        $response = ["success" => $status];
        if ($status) {
            $response['terminalTheme'] = $settingsHelper::getTerminalTheme();
            $response['fontSize'] = $settingsHelper::getTerminalFontSize();
            $response['cursorStyle'] = $settingsHelper::getTerminalCursorStyle();
            $response['cursorBlink'] = $settingsHelper::getTerminalCursorBlink();
        }

        self::setSuccess($response);
    }

    public function stopTerminal()
    {
        $settingsHelper = \frieren\helper\HelperFactory::createModuleHelper('settings', \DeviceConfig::systemFamily());
        if (!$settingsHelper::isTerminalEnabled()) {
            return self::setError('Terminal is disabled');
        }

        if (\DeviceConfig::systemFamily() === 'Linux') {
            OpenWrtHelper::exec('sudo -n /usr/bin/pkill -x ttyd');
            return self::setSuccess(['success' => !OpenWrtHelper::checkRunning('ttyd')]);
        }

        OpenWrtHelper::exec("/usr/bin/killall ttyd");
        $status = OpenWrtHelper::checkRunning($this->getTerminalPath());
        if ($status) {
            $this->logger("Terminal could not be stop! command exec: /usr/bin/killall ttyd");
        }

        self::setSuccess(["success" => !$status]);
    }

    public function getStatus()
    {
        $settingsHelper = \frieren\helper\HelperFactory::createModuleHelper('settings', \DeviceConfig::systemFamily());
        if (!$settingsHelper::isTerminalEnabled()) {
            return self::setSuccess(["status" => false]);
        }

        $process = \DeviceConfig::systemFamily() === 'Linux' ? 'ttyd' : $this->getTerminalPath();
        self::setSuccess(["status" => OpenWrtHelper::checkRunning($process)]);
    }
}
