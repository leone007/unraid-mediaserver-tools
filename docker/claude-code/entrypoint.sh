#!/bin/sh
# Keeps one tmux server with a session called "main" alive. Attach with:
#   docker exec -it claude tmux attach -t main   (or the Claude button in the Unraid header)
# The first time, start Claude Code in it ("claude") and sign in with /login.
export HOME=/home/claude
mkdir -p "$HOME/.claude"
# start in the repo folder: Claude Code keeps conversations (--continue) and folder trust per folder
cd /work/unraid-mediaserver-tools 2>/dev/null || cd /work 2>/dev/null || cd "$HOME"

stop() { tmux kill-server 2>/dev/null; exit 0; }
trap stop TERM INT

while true; do
    tmux has-session -t main 2>/dev/null || tmux new-session -d -s main -c "$(pwd)" bash
    sleep 20 &
    wait $!
done
