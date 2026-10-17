#!/bin/sh
set -eu

NMCLI=/usr/bin/nmcli
IP=/usr/sbin/ip
IW=/usr/sbin/iw
SETTINGS=/var/lib/frieren/settings.json

valid_device() {
    case "$1" in
        ""|*[!a-zA-Z0-9_.-]*) return 1 ;;
        *) return 0 ;;
    esac
}

is_monitor() {
    "$IW" dev "$1" info 2>/dev/null | grep -q 'type monitor'
}

require_wireless_device() {
    "$IW" dev "$1" info >/dev/null 2>&1 || {
        echo "$1 is not an available wireless interface" >&2
        return 1
    }
}

start_monitor() {
    device=$1
    valid_device "$device" || {
        echo "Invalid wireless device name" >&2
        return 2
    }

    require_wireless_device "$device" || return 1
    connection=$("$NMCLI" -g GENERAL.CONNECTION device show "$device") || return 1
    case "$connection" in
        ""|"--") ;;
        *)
            if ! is_monitor "$device"; then
                echo "$device has an active NetworkManager connection; refusing to disconnect it" >&2
                return 1
            fi
        ;;
    esac

    "$NMCLI" device set "$device" managed no || return 1
    "$IP" link set dev "$device" down || {
        "$NMCLI" device set "$device" managed yes || echo "Unable to restore NetworkManager management for $device" >&2
        return 1
    }
    if ! is_monitor "$device"; then
        "$IW" dev "$device" set type monitor || {
            "$IP" link set dev "$device" up || echo "Unable to bring $device back up" >&2
            "$NMCLI" device set "$device" managed yes || echo "Unable to restore NetworkManager management for $device" >&2
            return 1
        }
    fi
    "$IP" link set dev "$device" up || {
        "$IP" link set dev "$device" down || echo "Unable to take $device down during rollback" >&2
        "$IW" dev "$device" set type managed || echo "Unable to restore managed mode for $device" >&2
        "$IP" link set dev "$device" up || echo "Unable to bring $device back up" >&2
        "$NMCLI" device set "$device" managed yes || echo "Unable to restore NetworkManager management for $device" >&2
        return 1
    }
}

stop_monitor() {
    device=$1
    valid_device "$device" || {
        echo "Invalid wireless device name" >&2
        return 2
    }
    require_wireless_device "$device" || return 1
    is_monitor "$device" || {
        echo "$device is not in monitor mode" >&2
        return 1
    }

    "$NMCLI" device set "$device" managed no || return 1
    "$IP" link set dev "$device" down || return 1
    if is_monitor "$device"; then
        "$IW" dev "$device" set type managed || return 1
    fi
    "$IP" link set dev "$device" up || return 1
    "$NMCLI" device set "$device" managed yes
}

disable_monitor() {
    device=$1
    valid_device "$device" || {
        echo "Invalid wireless device name" >&2
        return 2
    }
    stop_monitor "$device"
}

restore_saved_monitors() {
    devices=$(/usr/bin/php -r '
        $contents = file_get_contents($argv[1]);
        $settings = $contents === false ? null : json_decode($contents, true);
        if (!is_array($settings)) {
            fwrite(STDERR, "Frieren wireless settings are invalid\n");
            exit(1);
        }
        $profiles = $settings["wireless_monitors"] ?? [];
        if (!is_array($profiles)) {
            fwrite(STDERR, "Frieren monitor profiles are invalid\n");
            exit(1);
        }
        foreach ($profiles as $profile) {
            if (!is_array($profile)
                || !isset($profile["radio"])
                || !is_string($profile["radio"])
                || !preg_match("/^[a-zA-Z0-9_.-]+$/", $profile["radio"])
            ) {
                fwrite(STDERR, "A saved Frieren monitor profile is invalid\n");
                exit(1);
            }
            if (empty($profile["disabled"])) {
                echo $profile["radio"], "\n";
            }
        }
    ' "$SETTINGS") || return 1

    while IFS= read -r device; do
        [ -n "$device" ] || continue
        start_monitor "$device" || {
            echo "Unable to restore monitor mode for $device" >&2
            return 1
        }
    done <<EOF
$devices
EOF
}

case "${1:-}" in
    start)
        [ "$#" -eq 2 ] || exit 2
        start_monitor "$2"
    ;;
    stop)
        [ "$#" -eq 2 ] || exit 2
        stop_monitor "$2"
    ;;
    disable)
        [ "$#" -eq 2 ] || exit 2
        disable_monitor "$2"
    ;;
    restore)
        [ "$#" -eq 1 ] || exit 2
        restore_saved_monitors
    ;;
    *)
        echo "Usage: $0 {start|stop|disable} <device> | restore" >&2
        exit 2
    ;;
esac
