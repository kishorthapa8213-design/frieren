#!/bin/sh

action=$1
shift

if command -v apt-get >/dev/null 2>&1; then
	run_root() {
		"$@"
	}

	perform_apt_action() {
		flock -x 200 || return 1
		case "$action" in
			update)
				run_root apt-get -y update
			;;
			upgrade)
				run_root apt-get -y upgrade
			;;
			install)
				[ -n "$packages" ] || { echo "No package specified." >&2; return 2; }
				set -f
				set --
				for package in $packages; do
					set -- "$@" "$package"
				done
				run_root apt-get -y install "$@"
			;;
			remove)
				[ -n "$packages" ] || { echo "No package specified." >&2; return 2; }
				set -f
				set --
				for package in $packages; do
					set -- "$@" "$package"
				done
				run_root apt-get -y purge "$@"
				if [ "$autoremove" -eq 1 ]; then
					run_root apt-get -y autoremove
				fi
			;;
		esac
	}

	case "$action" in
		update|upgrade|install|remove)
			if [ "$(id -u)" -ne 0 ]; then
				exec sudo -n /usr/local/sbin/frieren-package-manager "$action" "$@"
			fi
		;;
	esac

	case "$action" in
		list-installed)
			dpkg-query -W -f='Package: ${binary:Package}\nVersion: ${Version}\nDescription: ${binary:Synopsis}\n\n'
			exit $?
		;;
		list-available)
			apt-cache dumpavail
			exit $?
		;;
		update|upgrade|install|remove)
			autoremove=0
			packages=""
			for argument in "$@"; do
				case "$argument" in
					--autoremove|--force-removal-of-dependent-packages)
						autoremove=1
					;;
					--force-overwrite)
						;;
					--*)
						echo "Unsupported package-manager option: $argument" >&2
						exit 2
					;;
					*)
						case "$argument" in
							""|-*|*[!a-zA-Z0-9.+:~-]*)
								echo "Invalid package name: $argument" >&2
								exit 2
							;;
						esac
						packages="$packages
$argument"
					;;
				esac
			done

			perform_apt_action 200>/tmp/frieren-package-manager.lock
			exit $?
		;;
	esac
fi

. /usr/share/libubox/jshn.sh

if [ -f /usr/bin/apk ]; then
	ipkg_bin="apk"
else
	ipkg_bin="opkg"
fi

perform_ipkg_action() {
	cmd="$ipkg_bin"

	# APK have command renamed
	if [ "$ipkg_bin" = "apk" ]; then
		case "$action" in
			install)
				action="add"
			;;
			update)
				action="update"
			;;
			upgrade)
				action="upgrade"
			;;
			remove)
				action="del"
			;;
		esac
	fi

	# APK have --autoremove enabled by default and
	# --force-removal-of-dependent-packages as -r option
	if [ "$ipkg_bin" = "apk" ]; then
		while [ -n "$1" ]; do
			case "$1" in
				--force-removal-of-dependent-packages)
					cmd="$cmd -r"
					shift
				;;
				--force-overwrite)
					cmd="$cmd $1"
					shift
				;;
				--dest)
					# apk has no per-destination install, ignore target
					shift 2
				;;
				-*)
					shift
				;;
				*)
					break
				;;
			esac
		done
	else
		while [ -n "$1" ]; do
			case "$1" in
				--autoremove|--force-overwrite|--force-removal-of-dependent-packages)
					cmd="$cmd $1"
					shift
				;;
				--dest)
					cmd="$cmd --dest $2"
					shift 2
				;;
				-*)
					shift
				;;
				*)
					break
				;;
			esac
		done
	fi

	if flock -x 200; then
		pkmcmd="$cmd $action $@"
		$cmd $action "$@" </dev/null >/tmp/ipkg.out 2>/tmp/ipkg.err
		code=$?
		stdout=$(cat /tmp/ipkg.out)
		stderr=$(cat /tmp/ipkg.err)
	else
		code=255
		stderr="Failed to acquire lock"
	fi

	json_init
	json_add_int code "$code"
	[ -n "$pkmcmd" ] && json_add_string pkmcmd "$pkmcmd"
	[ -n "$stdout" ] && json_add_string stdout "$stdout"
	[ -n "$stderr" ] && json_add_string stderr "$stderr"
	json_dump
}

case "$action" in
	list-installed)
		if [ $ipkg_bin = "apk" ]; then
			$ipkg_bin list -I --full 2>/dev/null
		else
			cat /usr/lib/opkg/status
		fi
	;;
	list-available)
		if [ $ipkg_bin = "apk" ]; then
			$ipkg_bin list --full 2>/dev/null
		else
			lists_dir=$(sed -rne 's#^lists_dir \S+ (\S+)#\1#p' /etc/opkg.conf /etc/opkg/*.conf 2>/dev/null | tail -n 1)
			find "${lists_dir:-/usr/lib/opkg/lists}" -type f '!' -name '*.sig' | xargs -r gzip -cd
		fi
	;;
	install|update|upgrade|remove)
		perform_ipkg_action 200>/tmp/ipkg.lock

		rm -f /tmp/ipkg.lock /tmp/ipkg.err /tmp/ipkg.out
	;;
	*)
		echo "Usage: $0 {list-installed|list-available|update}" >&2
		echo "       $0 {install|upgrade|remove} pkg[ pkg...]" >&2
		exit 1
	;;
esac
