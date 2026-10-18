# Translating the plugins

The texts of the pages and tiles live in dictionaries, one per plugin: `<plugin>/lang/en.json` is the English source, and a translation is a file next to it named by the two-letter language code (`de.json`, `hu.json`, ...). The plugins show the language chosen in the Language block of any settings page (by default Unraid's own language, Settings, Display Settings). Any text that is missing from a translation shows in English, so a partial translation is fine.

Dictionaries so far: `monitor/lang/en.json` (the others follow plugin by plugin).

## The format

```json
{
  "_meta": { "plugin": "Monitor", "language": "English", "about": "what the plugin is" },
  "btn.hide":   { "text": "Hide", "note": "button next to a tile's name while editing ... Short." },
  "tile.count": { "one": "{n} torrent", "other": "{n} torrents", "note": "..." }
}
```

- The key (`btn.hide`) never changes.
- `text` is the text; a count has `one` (exactly 1) and `other` (every other number) instead.
- `{n}`, `{name}` and other words in braces are filled in by the plugin: keep them exactly as they are, in the place the sentence needs.
- `note` tells where the text appears and how long it may be. A translation does not need the notes.
- Product names (Unraid, Sysbox, Diskbox, Seedbox, Streambox, Monitor, Jellyfin, Radarr, Sonarr, Prowlarr, qBittorrent, autobrr, Discord) stay as they are.

## A translation file

```json
{
  "_meta": { "language": "Deutsch", "translator": "your name" },
  "btn.hide": { "text": "Ausblenden" },
  "tile.count": { "one": "{n} Torrent", "other": "{n} Torrents" }
}
```

Then check it: `python3 tools/i18n_check.py` (valid JSON, only known keys, the same placeholders as English; it also counts what is still missing).

## Translating with an AI model

Give the model the whole `en.json` of one plugin and this prompt (replace LANGUAGE):

```
You translate the user interface of an Unraid server plugin (a web page and dashboard tiles for a home
media server) from English to LANGUAGE.

Input: a JSON dictionary. Every entry has a key, an English "text" (or "one" and "other" for counts) and a
"note" that says where the text appears and how much room it has.

Output: only a JSON object, nothing before or after it, with
- "_meta": {"language": "<the language's own name>", "translator": "AI, to be reviewed"}
- every key of the input, with "text" (or "one" and "other") translated; leave out "note".

Rules:
- Keep every key exactly as it is.
- Keep every placeholder in braces ({n}, {name}, ...) exactly as it is, and put it where the sentence needs it.
- Follow the note: a button label stays as short as the English one; a tooltip or a hint can be a sentence.
- Use the words an Unraid user sees in Unraid's own LANGUAGE interface (array, parity, pool, share, Docker
  container), and the usual LANGUAGE terms of media servers and torrent clients.
- Do not translate product names: Unraid, Sysbox, Diskbox, Seedbox, Streambox, Monitor, Jellyfin, Radarr,
  Sonarr, Prowlarr, qBittorrent, autobrr, Discord, Shelly, Tailscale.
- Plain, friendly, short. Address the user the way LANGUAGE software usually does.
- For counts: "one" is used for exactly 1, "other" for every other number. If LANGUAGE uses the same form for
  both, write it twice.
```

Save the answer as `<plugin>/lang/<code>.json`, run the check, and have someone who speaks the language read it on the real page: a translation that is almost right is worse than English.
