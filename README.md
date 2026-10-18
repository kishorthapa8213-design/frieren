# Frieren: The micro-framework for security gadgets

![Panel](assets/panel.png)

## Description

Frieren is a framework for running security tools on OpenWrt routers and Single Board Computers (SBCs). It provides a web panel with WiFi management, installable modules, an integrated terminal, and a package manager. The stack is a PHP backend API with a React frontend, designed to be lightweight enough for embedded devices while remaining extensible through third-party modules.

## Features

- **WiFi Management** — Create, edit, and remove wireless interfaces. Scan for networks, configure radios, edit raw UCI config.
- **Network** — Manage network interfaces, view DHCP leases, and run connectivity diagnostics.
- **Module System** — Install, remove, and pin third-party modules from a remote repository. Modules load dynamically as UMD bundles.
- **Package Manager** — Install and remove opkg packages directly from the web panel.
- **Integrated Terminal** — Web-based terminal (ttyd) accessible from the panel.
- **System Tools** — Dashboard with system stats, USB device listing, filesystem usage, syslog viewer, diagnostics, and init.d service control.
- **Settings** — Configure hostname, timezone, user password, and panel theme.
- **Extensible** — Scaffold new modules with `frieren-module-template`. PHP controller + React frontend per module.

## Components

- **[frieren-back](frieren-back/)** — PHP micro-framework. JSON POST API with session auth, CSRF, UCI config integration, and a mini ORM.
- **[frieren-front](frieren-front/)** — React SPA (Vite, Wouter, Jotai, React Query). Supports dynamic UMD module loading.
- **[frieren-module-template](frieren-module-template/)** — Scaffolding tool for developing third-party modules with shared dependencies via window globals.
- **[frieren-terminal](frieren-terminal/)** — `@frieren/terminal-core`, a TypeScript/xterm.js library bundled into the frontend. Provides the live ttyd terminal client, a read-only log viewer, and the shared terminal themes.
- **[tools](tools/)** — Development helpers: dev API proxy, device deploy script, API test client, and a PHP minifier.

## Related Repositories

- **[Frieren Modules](https://github.com/xchwarze/frieren-modules)**: Contains community-developed modules based on the `frieren-module-template`. This repository is a resource for users looking to extend the functionality of their Frieren installation with additional features.
- **[Frieren Release](https://github.com/xchwarze/frieren-release)**: Hosts the installers for deploying precompiled versions of Frieren. This repository is ideal for users who wish to install Frieren quickly and easily without going through the build process.
- **[Frieren Modules Release](https://github.com/xchwarze/frieren-modules-release)**: Build pipeline for the module catalog — generates the `modules.json` index and the per-module release tarballs that the panel installs from.

## Installation

Frieren runs on OpenWrt (official builds, not forks). It can be installed via an automated script or compiled manually.

### Quick Installation

For a quick and easy installation, execute the following command in your terminal. This script will handle all necessary configurations and setup steps:

```bash
wget -qO- https://raw.githubusercontent.com/xchwarze/frieren-release/master/install/install-openwrt.sh | sh
```

### Linux x86_64 (Debian, Ubuntu, or Kali)

The Linux installer builds the terminal library and frontend, configures Nginx
and PHP-FPM, and enables the NetworkManager/systemd integrations. From a cloned
Frieren repository:

```bash
sudo ./install/install-linux.sh
```

After installation, open the dashboard at `http://<server-IP>/#/dashboard`.
The Linux installer uses HTTP port 80 by default, so no port number is needed;
on the server itself, use `http://localhost/#/dashboard`.

It requires x86_64, Node.js 22+, and a Debian-family distribution. The panel
creates an `admin` login password during installation. Linux Wi-Fi client and
access-point profiles use NetworkManager; access points use NetworkManager's
shared IPv4 mode (DHCP/NAT) and require adapter/driver support. Monitor mode uses
`iw` and requires the installed privileged helper plus adapter/driver support.
The installer downloads ttyd 1.7.7 from its upstream release and verifies the
SHA-256 checksum, since ttyd is not available in all supported distribution
repositories.
Enabling a monitor profile temporarily hands that adapter from NetworkManager to
Frieren, so do not use the host's active Wi-Fi uplink for monitor mode. Saved
monitor profiles are restored at boot. Static DHCP leases and firmware updates
remain OpenWrt-only. The installer does not activate
NetworkManager automatically because changing the host's network manager can
disconnect it. Use HTTPS before exposing the panel outside a trusted local
network. Linux terminal sessions require a host system account; terminal
autologin is intentionally disabled. The Linux terminal proxy allows WebSocket
connections to remain idle for up to one year before timing out.

Third-party module dependencies use Debian package names where a manifest
provides `linuxDependencies`. Linux provisions the required iptables access,
and its package manager assigns network-capture capabilities to tcpdump and
hcxdumptool after installation. Nmap uses the installer-managed sudoers
allowlist for raw network scans. Capture and scan history is stored in each
module's `data/` directory rather than `/root`.

Re-running the installer updates Frieren while preserving third-party modules.
If it disables the stock Nginx default-site symlink, it saves a link to the
original target at `/var/lib/frieren/nginx-default-enabled-target`.

For local Linux development, the backend accepts `FRIEREN_AUTH_FILE` and
`FRIEREN_SETTINGS_FILE` to keep development credentials and settings outside the
system-owned `/var/lib/frieren` directory.

> **New to this? Start here.** If you're not sure what OpenWrt is, how to flash it, or
> how to open the panel afterwards, follow the step-by-step, beginner-friendly
> **[Installation FAQ](FAQ.md)** — it walks you through everything from a clean device to
> logging in.

### Manual Compilation

Each component within the Frieren project (`frieren-back`, `frieren-front` and `frieren-module-template`) has its own detailed `README.md` file with specific build and installation instructions. Please refer to these files in their respective directories for more detailed guidance.

### Module Development

Use `frieren-module-template` to scaffold new modules. Run `yarn wizard` to set up a new module, develop your feature, then `yarn build` to compile a UMD bundle. See the [module template README](frieren-module-template/README.md) for details.

## License

This project is licensed under the PolyForm Noncommercial License 1.0.0.

Non-commercial use is permitted. Commercial use is not permitted under this license.
Commercial use requires a separate commercial license from the author.

For commercial licensing, contact: xchwarze@gmail.com

## Contributing

Contributions welcome — new features, bug fixes, or modules. See each component's README for build instructions.

## Authors

- **Lead Developer**: DSR! - xchwarze@gmail.com

## Support the Development

If you find Frieren useful, consider supporting development. Donations fund testing hardware and ongoing work. Send via Binance or join [Patreon](https://www.patreon.com/xchwarze) for exclusive updates and builds.

[![patreon](assets/patreon.png)](https://www.patreon.com/xchwarze)
![binance-qr](assets/binance-qr.png)
