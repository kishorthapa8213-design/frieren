#!/bin/sh
set -eu

NMCLI=/usr/bin/nmcli
IP=/usr/sbin/ip
IW=/usr/sbin/iw
AIRMON=/usr/sbin/airmon-ng
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

wireless_phy() {
    "$IW" dev "$1" info 2>/dev/null | awk '$1 == "wiphy" { print $2; exit }'
}

monitor_interface_for_phy() {
    "$IW" dev | awk -v target="$1" '
        /^phy#[0-9]+/ {
            phy = $0
            sub(/^phy#/, "", phy)
            next
        }
        /^[[:space:]]+Interface / {
            interface = $2
            next
        }
        /^[[:space:]]+type monitor$/ && phy == target {
            print interface
            exit
        }
    '
}

require_wireless_device() {
    "$IW" dev "$1" info >/dev/null 2>&1 || {
        echo "$1 is not an available wireless interface" >&2
        return 1
    }
}

is_usb_wireless_device() {
    device_path=$(/usr/bin/readlink -f "/sys/class/net/$1/device") || return 1
    case "$device_path" in
        */usb[0-9]*/*) return 0 ;;
        *) return 1 ;;
    esac
}

is_saved_unmanaged() {
    device=$1
    address=$(/usr/sbin/ip -o link show dev "$device") || return 2
    address=$(printf '%s\n' "$address" | sed -n 's/.*[[:space:]]permaddr[[:space:]]\([[:xdigit:]:]*\).*/\1/p')
    if [ -z "$address" ]; then
        address=$(cat "/sys/class/net/$device/address") || return 2
    fi
    [ -n "$address" ] && [ "$address" != "--" ] || return 2

    /usr/bin/php -r '
        $contents = file_get_contents($argv[1]);
        $settings = $contents === false ? null : json_decode($contents, true);
        $addresses = is_array($settings) ? ($settings["wireless_unmanaged_wifi_macs"] ?? []) : null;
        if (!is_array($addresses)) {
            fwrite(STDERR, "Frieren USB Wi-Fi settings are invalid\n");
            exit(2);
        }
        foreach ($addresses as $saved) {
            if (!is_string($saved) || !preg_match("/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/", $saved)) {
                fwrite(STDERR, "A saved USB Wi-Fi MAC address is invalid\n");
                exit(2);
            }
            if (strtolower($saved) === strtolower($argv[2])) {
                exit(0);
            }
        }
        exit(1);
    ' "$SETTINGS" "$address"
}

restore_networkmanager_management() {
    device=$1
    if is_saved_unmanaged "$device"; then
        "$NMCLI" device set "$device" managed no
    else
        status=$?
        [ "$status" -eq 1 ] || return "$status"
        "$NMCLI" device set "$device" managed yes
    fi
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
        restore_networkmanager_management "$device" || echo "Unable to restore NetworkManager management for $device" >&2
        return 1
    }
    if ! is_monitor "$device"; then
        "$IW" dev "$device" set type monitor || {
            "$IP" link set dev "$device" up || echo "Unable to bring $device back up" >&2
            restore_networkmanager_management "$device" || echo "Unable to restore NetworkManager management for $device" >&2
            return 1
        }
    fi
    "$IP" link set dev "$device" up || {
        "$IP" link set dev "$device" down || echo "Unable to take $device down during rollback" >&2
        "$IW" dev "$device" set type managed || echo "Unable to restore managed mode for $device" >&2
        "$IP" link set dev "$device" up || echo "Unable to bring $device back up" >&2
        restore_networkmanager_management "$device" || echo "Unable to restore NetworkManager management for $device" >&2
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
    restore_networkmanager_management "$device"
}

set_device_managed() {
    device=$1
    managed=$2
    valid_device "$device" || {
        echo "Invalid wireless device name" >&2
        return 2
    }
    case "$managed" in
        yes|no) ;;
        *)
            echo "Expected managed state 'yes' or 'no'" >&2
            return 2
        ;;
    esac

    require_wireless_device "$device" || return 1
    is_usb_wireless_device "$device" || {
        echo "$device is not a USB wireless device" >&2
        return 1
    }
    if [ "$managed" = no ]; then
        connection=$("$NMCLI" -g GENERAL.CONNECTION device show "$device") || return 1
        case "$connection" in
            ""|"--") ;;
            *)
                echo "$device has an active NetworkManager connection; refusing to disconnect it" >&2
                return 1
            ;;
        esac
    elif is_monitor "$device"; then
        echo "$device is in monitor mode; stop monitor mode first" >&2
        return 1
    fi

    "$NMCLI" device set "$device" managed "$managed"
}

start_aircrack_monitor() {
    device=$1
    valid_device "$device" || {
        echo "Invalid wireless device name" >&2
        return 2
    }
    require_wireless_device "$device" || return 1
    [ -x "$AIRMON" ] || {
        echo "airmon-ng is not installed" >&2
        return 1
    }

    connection=$("$NMCLI" -g GENERAL.CONNECTION device show "$device") || return 1
    case "$connection" in
        ""|"--") ;;
        *)
            echo "$device has an active NetworkManager connection; disconnect it first" >&2
            return 1
        ;;
    esac

    phy=$(wireless_phy "$device")
    [ -n "$phy" ] || {
        echo "Unable to determine the wireless PHY for $device" >&2
        return 1
    }
    [ -z "$(monitor_interface_for_phy "$phy")" ] || {
        echo "A monitor interface already exists for $device" >&2
        return 1
    }

    "$NMCLI" device set "$device" managed no || return 1
    if ! "$AIRMON" start "$device"; then
        restore_networkmanager_management "$device" || echo "Unable to restore NetworkManager management for $device" >&2
        return 1
    fi

    monitor=$(monitor_interface_for_phy "$phy")
    if [ -z "$monitor" ]; then
        echo "airmon-ng did not create a monitor interface for $device" >&2
        restore_networkmanager_management "$device" || echo "Unable to restore NetworkManager management for $device" >&2
        return 1
    fi
}

stop_aircrack_monitor() {
    device=$1
    valid_device "$device" || {
        echo "Invalid wireless device name" >&2
        return 2
    }
    require_wireless_device "$device" || return 1
    [ -x "$AIRMON" ] || {
        echo "airmon-ng is not installed" >&2
        return 1
    }

    phy=$(wireless_phy "$device")
    [ -n "$phy" ] || {
        echo "Unable to determine the wireless PHY for $device" >&2
        return 1
    }
    monitor=$(monitor_interface_for_phy "$phy")
    [ -n "$monitor" ] || {
        echo "No airmon-ng monitor interface exists for $device" >&2
        return 1
    }

    "$AIRMON" stop "$monitor" || return 1
    restore_networkmanager_management "$device"
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
    unmanaged_macs=$(/usr/bin/php -r '
        $contents = file_get_contents($argv[1]);
        $settings = $contents === false ? null : json_decode($contents, true);
        if (!is_array($settings)) {
            fwrite(STDERR, "Frieren wireless settings are invalid\n");
            exit(1);
        }
        $addresses = $settings["wireless_unmanaged_wifi_macs"] ?? [];
        if (!is_array($addresses)) {
            fwrite(STDERR, "Frieren USB Wi-Fi settings are invalid\n");
            exit(1);
        }
        foreach ($addresses as $address) {
            if (!is_string($address) || !preg_match("/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/", $address)) {
                fwrite(STDERR, "A saved USB Wi-Fi MAC address is invalid\n");
                exit(1);
            }
            echo strtolower($address), "\n";
        }
    ' "$SETTINGS") || return 1

    devices=$("$NMCLI" -t -e yes -f DEVICE,TYPE device status) || return 1
    while IFS=: read -r device type; do
        [ "$type" = wifi ] || continue
        valid_device "$device" || continue
        is_usb_wireless_device "$device" || continue
        address=$(/usr/sbin/ip -o link show dev "$device") || return 1
        address=$(printf '%s\n' "$address" | sed -n 's/.*[[:space:]]permaddr[[:space:]]\([[:xdigit:]:]*\).*/\1/p')
        if [ -z "$address" ]; then
            address=$(cat "/sys/class/net/$device/address") || return 1
        fi
        printf '%s\n' "$unmanaged_macs" | grep -Fxiq -- "$address" || continue
        "$NMCLI" device set "$device" managed no || {
            echo "Unable to restore unmanaged NetworkManager state for $device" >&2
            return 1
        }
    done <<EOF
$devices
EOF

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
    set-managed)
        [ "$#" -eq 3 ] || exit 2
        set_device_managed "$2" "$3"
    ;;
    airmon-start)
        [ "$#" -eq 2 ] || exit 2
        start_aircrack_monitor "$2"
    ;;
    airmon-stop)
        [ "$#" -eq 2 ] || exit 2
        stop_aircrack_monitor "$2"
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
        echo "Usage: $0 {start|stop|disable|airmon-start|airmon-stop} <device> | set-managed <device> {yes|no} | restore" >&2
        exit 2
    ;;
esac
