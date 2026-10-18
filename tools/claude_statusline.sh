#!/bin/sh
# Claude Code status line in the claude container (settings.json statusLine -> sh ~/.claude/statusline.sh; this file is the
# source, copy it there). Shows context and plan usage in the terminal, and leaves the plan usage for the Sysbox Claude
# button tooltip in ~/usage (the home is on the cache SSD): hist.log gets a line only when a value changes, the file
# 'seen' is touched at most once a minute. Read-only towards everything else.
in=$(cat)
D="$HOME/usage"
mkdir -p "$D" 2>/dev/null
vals=$(printf '%s' "$in" | jq -r '[(.rate_limits.seven_day.used_percentage // "-"), (.rate_limits.seven_day.resets_at // "-"),
  (.rate_limits.five_hour.used_percentage // "-"), (.rate_limits.five_hour.resets_at // "-"),
  (.context_window.used_percentage // "-")] | map(tostring) | join(" ")' 2>/dev/null)
set -- $vals
w7=${1:--}; r7=${2:--}; w5=${3:--}; r5=${4:--}; ctx=${5:--}

if [ "$w7" != "-" ] && [ "$r7" != "-" ]; then
  now=$(date +%s)
  cur="$w7 $r7 $w5 $r5"
  last=$(tail -n 1 "$D/hist.log" 2>/dev/null | cut -d' ' -f2-)
  if [ "$cur" != "$last" ]; then
    echo "$now $cur" >> "$D/hist.log"
    # keep about two weeks of changes
    if [ "$(wc -l < "$D/hist.log")" -gt 4000 ]; then tail -n 2000 "$D/hist.log" > "$D/hist.tmp" && mv "$D/hist.tmp" "$D/hist.log"; fi
  fi
  [ -z "$(find "$D/seen" -mmin -1 2>/dev/null)" ] && touch "$D/seen"
fi

out=""
[ "$ctx" != "-" ] && out="ctx $(printf '%.0f' "$ctx")%"
[ "$w5" != "-" ] && out="${out:+$out · }5h $(printf '%.0f' "$w5")%"
[ "$w7" != "-" ] && out="${out:+$out · }7d $(printf '%.0f' "$w7")%"
printf '%s' "$out"
