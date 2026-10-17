<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 */

namespace frieren\modules\header;

use frieren\helper\LinuxHelper;

class ModuleLinuxHelper
{
    public static function shutDownHardware()
    {
        LinuxHelper::execBackground('sudo -n /usr/bin/systemctl poweroff');
    }

    public static function resetHardware()
    {
        LinuxHelper::execBackground('sudo -n /usr/bin/systemctl reboot');
    }
}
