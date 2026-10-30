#!/usr/bin/env bash
# Abyss spotlight for old browsers (JELLYNAS item 129, 2026-10-30). A custom-cont-init.d script for linuxserver/jellyfin;
# its name sorts after abyss-spotlight.sh, so it runs on every container start right after the Abyss files are downloaded.
#
# The spotlight's own script uses optional chaining (?.), which Chromium 79 (LG webOS 6, e.g. the 2021 C1) cannot read,
# so the whole spotlight stays empty there; its CSS uses inset (87), flex gap (84) and :focus-visible (86).
# This script leaves the Abyss files as they are and writes an old-browser copy next to them:
#   spotlight-legacy.html  the inline script compiled by esbuild for Chromium 68, linking spotlight-legacy.css
#   spotlight-legacy.css   inset in its long form (esbuild), :focus-visible as :focus, flex gap as margins
# and changes one thing in spotlight-loader.js: a browser whose user agent says Chrome below 80 loads the legacy page,
# every other browser (Chrome 80+, Firefox, Safari, the Jellyfin apps) the original, exactly as before.
# Whatever fails (download, an Abyss update that changes the loader), nothing is changed and Jellyfin starts as usual.
#
# Install: copy to /mnt/cache/docker/jellyfin/custom-cont-init.d/zz-abyss-legacy.sh, chmod +x, restart Jellyfin.
# esbuild (a single static binary, MIT) is downloaded once from the npm registry and checked against its SHA-256.

UI_DIR="${ABYSS_UI_DIR:-/usr/share/jellyfin/web/ui}"   # the variables are only for a test run elsewhere
TOOL_DIR="${ABYSS_TOOL_DIR:-/config/abyss-legacy}"
ESB_VER="0.24.2"
ESB_TGZ_SHA256="aa469e907204bade7c37df6376f7ce3562574e937a61984ba8b89db63e03883a"
ESB_URL="https://registry.npmjs.org/@esbuild/linux-x64/-/linux-x64-${ESB_VER}.tgz"

log() { echo "**** [abyss-legacy] $* ****"; }

get_esbuild() {
    ESB="${TOOL_DIR}/esbuild-${ESB_VER}"
    [ -x "$ESB" ] && return 0
    [ "$(uname -m)" = "x86_64" ] || { log "not x86_64, skipped"; return 1; }
    mkdir -p "$TOOL_DIR" || return 1
    local t="${TOOL_DIR}/dl.$$"
    rm -rf "$t"; mkdir -p "$t" || return 1
    if ! curl -fsSL "$ESB_URL" -o "$t/esb.tgz"; then log "esbuild download failed"; rm -rf "$t"; return 1; fi
    if [ "$(sha256sum "$t/esb.tgz" | cut -d' ' -f1)" != "$ESB_TGZ_SHA256" ]; then log "esbuild checksum mismatch"; rm -rf "$t"; return 1; fi
    tar -xzf "$t/esb.tgz" -C "$t" package/bin/esbuild && mv -f "$t/package/bin/esbuild" "$ESB" && chmod 755 "$ESB"
    rm -rf "$t"
    [ -x "$ESB" ] || { log "esbuild unpack failed"; return 1; }
    log "esbuild ${ESB_VER} installed"
}

# flex gap -> margins between the children; :focus-visible -> :focus (CSS from esbuild on stdin)
legacy_css_fix() {
    perl -0777 -pe '
        s{(?<=[{}]|\A)([^{}]+)\{([^{}]*)\}}{
            my ($sel, $body) = ($1, $2); my $out = $sel . chr(123) . $body . chr(125);  # chr: braces would end the s{}{} block
            if ($body =~ /display:\s*(?:inline-)?flex\b/ && $body =~ /(?<![-\w])gap:\s*([^;]+);/) {
                my @g = split /\s+/, $1; my ($rg, $cg) = (@g > 1 ? @g : ($g[0], $g[0]));
                my $col = $body =~ /flex-direction:\s*column/;
                my $m = $col ? "margin-top: $rg" : "margin-left: $cg";
                my $s = join(", ", map { my $x = $_; $x =~ s/^\s+|\s+$//g; "$x > * + *" } split /,/, $sel);
                $out .= "\n" . $s . " " . chr(123) . "\n  $m;\n" . chr(125);
            }
            $out
        }ge;
        s/:focus-visible/:focus/g;
    '
}

build() {
    local html="${UI_DIR}/spotlight.html" css="${UI_DIR}/spotlight.css" loader="${UI_DIR}/spotlight-loader.js"
    for f in "$html" "$css" "$loader"; do [ -f "$f" ] || { log "missing $(basename "$f"), skipped"; return 1; }; done
    get_esbuild || return 1
    local t; t=$(mktemp -d) || return 1

    # the page: exactly one inline <script> block, its tags on lines of their own
    local open close
    open=$(grep -n -E '^[[:space:]]*<script>[[:space:]]*$' "$html" | cut -d: -f1)
    close=$(grep -n -E '^[[:space:]]*</script>[[:space:]]*$' "$html" | cut -d: -f1)
    if [ "$(printf '%s\n' "$open" | grep -c .)" != 1 ] || [ "$(printf '%s\n' "$close" | grep -c .)" != 1 ] || [ "$open" -ge "$close" ]; then
        log "spotlight.html layout changed (inline script), skipped"; rm -rf "$t"; return 1
    fi
    sed -n "$((open + 1)),$((close - 1))p" "$html" > "$t/in.js"
    if ! "$ESB" --loader=js --target=chrome68 --log-level=error < "$t/in.js" > "$t/out.js"; then log "esbuild could not compile the script"; rm -rf "$t"; return 1; fi
    { sed -n "1,${open}p" "$html" | sed 's/href="spotlight\.css"/href="spotlight-legacy.css"/'; cat "$t/out.js"; sed -n "${close},\$p" "$html"; } > "$t/legacy.html"
    grep -q 'href="spotlight-legacy.css"' "$t/legacy.html" || { log "spotlight.html has no spotlight.css link, skipped"; rm -rf "$t"; return 1; }

    # the style sheet
    if ! "$ESB" --loader=css --target=chrome68 --log-level=error < "$css" > "$t/esb.css"; then log "esbuild could not compile the CSS"; rm -rf "$t"; return 1; fi
    legacy_css_fix < "$t/esb.css" > "$t/legacy.css" && [ -s "$t/legacy.css" ] || { log "CSS fix failed"; rm -rf "$t"; return 1; }

    # the loader: the two places that name spotlight.html, both must be there exactly as expected
    if [ "$(grep -c '"ui/spotlight.html"' "$loader")" != 1 ] || [ "$(grep -c 'new URL("spotlight.html", cs.src)' "$loader")" != 1 ]; then
        log "spotlight-loader.js changed, skipped (old browsers keep the original spotlight)"; rm -rf "$t"; return 1
    fi
    # the choice goes right after a leading "use strict"; (so the loader stays in strict mode), else on top
    cat > "$t/add.js" <<'EOF'
/* JELLYNAS zz-abyss-legacy.sh: Chrome below 80 (LG webOS 6 and older) loads spotlight-legacy.html */
var ABYSS_SPOT_PAGE = (function () { var m = /Chrome\/(\d+)/.exec(navigator.userAgent || ""); return m && +m[1] < 80 ? "spotlight-legacy.html" : "spotlight.html"; })();
EOF
    sed -e 's#"ui/spotlight.html"#"ui/" + ABYSS_SPOT_PAGE#' -e 's#new URL("spotlight.html", cs.src)#new URL(ABYSS_SPOT_PAGE, cs.src)#' "$loader" > "$t/patched.js"
    local first; first=$(grep -n -m1 '[^[:space:]]' "$t/patched.js" | cut -d: -f1)
    if sed -n "${first}p" "$t/patched.js" | grep -q -E "^[[:space:]]*[\"']use strict[\"'];?[[:space:]]*$"; then
        { sed -n "1,${first}p" "$t/patched.js"; cat "$t/add.js"; sed -n "$((first + 1)),\$p" "$t/patched.js"; } > "$t/loader.js"
    else
        cat "$t/add.js" "$t/patched.js" > "$t/loader.js"
    fi

    cp -f "$t/legacy.html" "${UI_DIR}/spotlight-legacy.html" && cp -f "$t/legacy.css" "${UI_DIR}/spotlight-legacy.css" \
        && chmod 644 "${UI_DIR}/spotlight-legacy.html" "${UI_DIR}/spotlight-legacy.css" \
        && cp -f "$t/loader.js" "$loader" && chmod 644 "$loader"
    local rc=$?
    rm -rf "$t"
    [ $rc = 0 ] && log "legacy spotlight written (Chrome below 80 gets it)" || log "could not write the files"
}

build || true
exit 0
