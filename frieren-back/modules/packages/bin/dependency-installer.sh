#!/bin/sh

# Installs module dependencies, streaming the package-manager output straight to
# stdout (no buffering) so BackgroundTaskHelper captures it line by line and the
# UI can show progress in real time. Unlike package-manager-call.sh this does NOT
# wrap the result in JSON — it favours a live log over a structured result.
#
# Usage: dependency-installer.sh [--dest <target>] <dep1> [dep2 ...]

DEST=""

if command -v apt-get >/dev/null 2>&1; then
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --dest)
                shift 2
            ;;
            *)
                break
            ;;
        esac
    done

    if [ "$#" -eq 0 ]; then
        echo "No dependencies specified." >&2
        exit 1
    fi

    for dependency in "$@"; do
        case "$dependency" in
            ""|-*|*[!a-zA-Z0-9.+:~-]*)
                echo "Invalid package name: $dependency" >&2
                exit 2
            ;;
        esac
    done

    if [ "$(id -u)" -eq 0 ]; then
        /usr/local/sbin/frieren-package-manager update || exit $?
        exec /usr/local/sbin/frieren-package-manager install "$@"
    fi

    sudo -n /usr/local/sbin/frieren-package-manager update || exit $?
    exec sudo -n /usr/local/sbin/frieren-package-manager install "$@"
fi

if [ -f /usr/bin/apk ]; then
    PM="apk"
    INSTALL="add"
else
    PM="opkg"
    INSTALL="install"
fi

while [ "$#" -gt 0 ]; do
    case "$1" in
        --dest)
            # apk has no per-destination install; only opkg honours --dest
            [ "$PM" = "opkg" ] && DEST="--dest $2"
            shift 2
        ;;
        *)
            break
        ;;
    esac
done

DEPS="$@"

if [ -z "$DEPS" ]; then
    echo "No dependencies specified."
    exit 1
fi

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

install_legacy_dependencies() {
    flock -x 200 || return 1

    log "Updating package lists..."
    $PM update 2>&1
    code=$?
    if [ "$code" -ne 0 ]; then
        log "Package list update failed with exit code $code."
        return "$code"
    fi

    log "Installing: $DEPS"
    $PM $DEST $INSTALL $DEPS 2>&1
    code=$?

    log "Finished with exit code $code."
    return "$code"
}

install_legacy_dependencies 200>/tmp/ipkg.lock
