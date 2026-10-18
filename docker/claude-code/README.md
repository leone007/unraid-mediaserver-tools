# Claude Code on the Unraid server

A small Docker image that runs **Claude Code** in a persistent `tmux` session, so the same live session can be attached from any device (SSH or the container console) and keeps running when you disconnect. It is built **locally from this Dockerfile** (no third-party image) and is deliberately limited:

| | |
|---|---|
| User | `99:100` (`nobody:users`), not root |
| Network | `bridge` (reaches the services by the server's LAN address, e.g. `http://192.168.50.30:<port>`); no host networking |
| Docker socket / privileged | **no** |
| Writable | `/work` (= `/mnt/user/data/DEV`) and the home folder (`/mnt/cache/docker/claude-code`, on the ZFS cache next to the other containers' data) |
| Read only | `/srv/data` (media, downloads), `/srv/plugins` (`/boot/config/plugins`, contains API keys), `/srv/emhttp-plugins` (installed plugin code), `/srv/emhttp-state` (`disks.ini`, `var.ini`) |
| Limits | 4 GB RAM, 2 CPUs |

The image also has **Playwright with Chromium** (in `/ms-playwright`) for render tests of the plugin pages: `NODE_PATH=$(npm root -g) node render.js`.

Read-only mounts are the real protection. `.claude/settings.json` in the repo also denies `rm`, `docker`, `curl -X POST/PUT/DELETE` and similar, and `CLAUDE.md` tells the session to use GET only on the real services and never to print keys. Remove a read-only mount from the template if you do not want Claude to see it (for example `/srv/plugins` hides the API keys, but then it cannot compare the installed `.plg` or read `tools.json`).

## Install

1. Put the repo on the server, e.g. unzip `unraid-mediaserver-tools.zip` into `/mnt/user/data/DEV/`, so you have `/mnt/user/data/DEV/unraid-mediaserver-tools/`.
2. Build the image (needs internet for `apt` and `npm`, a few minutes):
   ```
   docker build -t claude-code-unraid:local /mnt/user/data/DEV/unraid-mediaserver-tools/docker/claude-code
   ```
3. Create the home folder and add the template:
   ```
   mkdir -p /mnt/cache/docker/claude-code && chown 99:100 /mnt/cache/docker/claude-code
   cp /mnt/user/data/DEV/unraid-mediaserver-tools/docker/claude-code/claude-code.xml /boot/config/plugins/dockerMan/templates-user/my-claude.xml
   ```
4. Docker tab, **Add Container**, pick the template **claude** from the *User templates* list, check the paths, **Apply**. The container is called `claude` (the Sysbox Claude button and the commands below use this name).

## First run

```
docker exec -it claude tmux attach -t main
cd /work/unraid-mediaserver-tools
claude
```
Sign in with `/login` (a browser link and a code; copy the link from the terminal carefully, a wrapped link breaks). The login is stored in `/mnt/cache/docker/claude-code` and survives updates. Detach from tmux with `Ctrl-b d`; the session keeps running.

Claude Code starts in the repo folder, reads `CLAUDE.md` by itself and knows the rules.

## Reaching it from other devices

- **SSH to the server, then** `docker exec -it claude tmux attach -t main`. Over Tailscale this works from the phone too (a terminal app such as Termius).
- **Remote Control** (claude.ai/code in a browser or the Claude app, a chat instead of the terminal): in a running session type `/remote-control`, or start Claude with `claude --continue --remote-control JELLYNAS`.
- **The Claude button in the Unraid header** (Sysbox 2026.10.15 or later, next to the terminal icon) does this with one click: it starts the `claude` container if it is stopped, starts Claude Code with Remote Control in the tmux session `main` when only the shell runs there (the entrypoint keeps an empty `main` session alive), continues the last conversation, and opens the session on claude.ai. A running Claude is left as it is.

## Update

```
docker build --no-cache -t claude-code-unraid:local /mnt/user/data/DEV/unraid-mediaserver-tools/docker/claude-code
```
then in the Docker tab restart the container (Force update). The login and settings stay.

## Handing work over from a chat session

The repo and `CLAUDE.md` are the shared memory. Chat sessions and the server session do not see each other's messages, so anything worth keeping goes into `NOTES.md` or the repo. After a build the session writes the `.plg` to `/work/...`, and you install it with the command in `CLAUDE.md`.
