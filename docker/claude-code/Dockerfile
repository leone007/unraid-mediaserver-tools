# Claude Code for the Unraid server: a persistent tmux session, no root, no Docker socket.
# Build on the server:  docker build -t claude-code-unraid:local /mnt/user/data/DEV/unraid-mediaserver-tools/docker/claude-code
FROM node:22-bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive LANG=C.UTF-8 TERM=xterm-256color

# Tools the plugin work needs: php (php -l, CLI tests), python3 (build scripts), node (node --check),
# zip, rsync, jq, ripgrep, procps/psmisc (ps, fuser), iproute2 and curl (read-only diagnostics).
RUN apt-get update && apt-get install -y --no-install-recommends \
        tmux git openssh-client ca-certificates curl jq ripgrep zip unzip rsync less nano \
        procps psmisc iproute2 iputils-ping \
        php-cli php-curl php-xml php-mbstring python3 \
    && rm -rf /var/lib/apt/lists/*

# Diagnostics and media tools (owner's list, 2026-10-25): PDF text (poppler, pypdf), images (PIL, ImageMagick, pngquant),
# media details (mediainfo, ffprobe from ffmpeg, mkvtoolnix), network (dig, mtr, iperf3, netcat), the UPS (upsc),
# plugin checks (shellcheck, xmllint), sqlite3 databases, pandoc, 7-Zip, strings, the GitHub CLI.
RUN apt-get update && apt-get install -y --no-install-recommends \
        poppler-utils python3-pil python3-pip \
        imagemagick pngquant mediainfo ffmpeg mkvtoolnix \
        dnsutils mtr-tiny iperf3 netcat-openbsd nut-client \
        shellcheck libxml2-utils sqlite3 pandoc p7zip-full binutils gh \
    && pip3 install --no-cache-dir --break-system-packages pypdf \
    && rm -rf /var/lib/apt/lists/*

# PowerShell 7 (owner, 2026-11-13): to test the Praxisradar helper (Windows PowerShell scripts) here before it goes to
# the clinic machine. Microsoft's tarball, pinned; pwsh in /opt/microsoft/powershell/7, linked to /usr/local/bin/pwsh.
ENV POWERSHELL_TELEMETRY_OPTOUT=1
RUN apt-get update && apt-get install -y --no-install-recommends libicu72 libssl3 \
    && mkdir -p /opt/microsoft/powershell/7 \
    && curl -fsSL https://github.com/PowerShell/PowerShell/releases/download/v7.4.6/powershell-7.4.6-linux-x64.tar.gz \
       | tar xz -C /opt/microsoft/powershell/7 \
    && chmod 755 /opt/microsoft/powershell/7/pwsh && ln -s /opt/microsoft/powershell/7/pwsh /usr/local/bin/pwsh \
    && rm -rf /var/lib/apt/lists/*
# PSScriptAnalyzer: lint, and with its compatibility rules a check against Windows PowerShell 5.1 (what Windows runs);
# Pester: unit tests for the scripts' functions. Pinned, for all users (/usr/local/share/powershell/Modules).
RUN pwsh -NoProfile -NonInteractive -Command "Set-PSRepository PSGallery -InstallationPolicy Trusted; \
        Install-Module PSScriptAnalyzer -RequiredVersion 1.23.0 -Scope AllUsers; \
        Install-Module Pester -RequiredVersion 5.6.1 -Scope AllUsers -SkipPublisherCheck" \
    && chmod -R a+rX /usr/local/share/powershell

# C toolchain for Rust (owner, 2026-11-23): the Praxisradar server's crates (ring, sqlite) build C code. build-essential for
# the Linux tests, mingw-w64 to compile the Windows praxisradar-server.exe here (target x86_64-pc-windows-gnu) before CI.
# Rust itself (rustup) lives in the home and survives a re-create.
RUN apt-get update && apt-get install -y --no-install-recommends build-essential mingw-w64 pkg-config \
    && rm -rf /var/lib/apt/lists/*

# Development kit (owner, 2026-11-23: "everything that seems useful, no need to be modest"). Grouped by purpose.
# - Tauri desktop app (Praxisradar, desktop/src-tauri): its Linux build libraries, so `cargo check/test` of the app runs here
#   and not only in CI; xvfb + webkit2gtk-driver for headless runs and tauri-driver e2e.
# - Windows builds from Linux: clang/lld/llvm for cargo-xwin (the MSVC target CI uses; cargo-xwin itself goes to the
#   home with `cargo install`), nasm/cmake for crates with C/asm, NSIS for the Tauri setup.exe, osslsigncode for
#   signing later, wine64 to start the Windows exes for a smoke test (not a real Windows: no CryptoAPI roots etc.).
# - Office files: LibreOffice headless (MedMax xlsx, docx/pdf conversion), Python openpyxl/pandas for fake test exports.
# - PDF and scans: tesseract with Hungarian, ocrmypdf, qpdf, ghostscript (NEAK letters, law texts, scanned documents).
# - Debugging and network: strace, lsof, nmap (own LAN only), traceroute, whois, file, tree, dos2unix, bc.
# - PHP extras for plugin tests: sqlite3, zip, gd.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libwebkit2gtk-4.1-dev libgtk-3-dev libayatana-appindicator3-dev librsvg2-dev libssl-dev libsoup-3.0-dev \
        webkit2gtk-driver xvfb xauth \
        clang lld llvm nasm cmake nsis osslsigncode wine64 \
        libreoffice-calc-nogui libreoffice-writer-nogui \
        python3-openpyxl python3-pandas python3-numpy python3-matplotlib python3-requests python3-yaml python3-lxml python3-bs4 \
        tesseract-ocr tesseract-ocr-hun ocrmypdf qpdf ghostscript \
        strace lsof nmap traceroute whois file tree dos2unix bc \
        php-sqlite3 php-zip php-gd \
    && rm -rf /var/lib/apt/lists/*

# Claude Code itself
RUN npm install -g @anthropic-ai/claude-code && npm cache clean --force

# Playwright with Chromium (and the system libraries it needs) for render tests of the plugin pages: screenshots
# against mock data. The browser goes to /ms-playwright (in the image, readable by user 99), so it survives a re-create.
# Use it with: NODE_PATH=$(npm root -g) node render.js
# WebKit too (owner, 2026-11-23): the family iPhones run Safari, so pages can be checked in a Safari-like engine.
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
RUN npm install -g playwright@1.56.0 \
    && npx -y playwright@1.56.0 install --with-deps chromium webkit \
    && chmod -R a+rX /ms-playwright \
    && npm cache clean --force && rm -rf /var/lib/apt/lists/*

# Same ids as Unraid's nobody:users, so files written to /work belong to the share owner.
RUN useradd -u 99 -g 100 -m -d /home/claude -s /bin/bash claude \
    && mkdir -p /work /home/claude/.claude && chown -R 99:100 /work /home/claude

COPY tmux.conf /etc/tmux.conf
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod 755 /usr/local/bin/entrypoint.sh

USER 99:100
ENV HOME=/home/claude
WORKDIR /work
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
