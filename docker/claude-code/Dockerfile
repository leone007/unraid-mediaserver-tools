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

# Claude Code itself
RUN npm install -g @anthropic-ai/claude-code && npm cache clean --force

# Playwright with Chromium (and the system libraries it needs) for render tests of the plugin pages: screenshots
# against mock data. The browser goes to /ms-playwright (in the image, readable by user 99), so it survives a re-create.
# Use it with: NODE_PATH=$(npm root -g) node render.js
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
RUN npm install -g playwright@1.56.0 \
    && npx -y playwright@1.56.0 install --with-deps chromium \
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
