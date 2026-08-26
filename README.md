# Listening Room Song Gallery

Interactive single-page song gallery styled as records and sleeves. Songs are discovered from a private library at request time; no database, build step, or JavaScript framework is required.

## Features

- Circular draggable song carousel
- Record playback with needle cue sound
- Record seeking, volume control, and sleeve gestures
- Keyboard and touch controls
- Lyrics displayed on the back of each sleeve
- ZIP downloads generated on demand
- Stable short share URLs such as `/s/zWOyqMC4`
- Per-song Open Graph and Twitter metadata
- Direct-link clipboard copying
- Persistent user-selectable room background color
- Random initial song when visiting `/`
- Source MP3s, full-size PNGs, lyrics, and IDs stored outside the public web root

## Requirements

- PHP 8.3+
- PHP extensions: `gd`, `zip`
- Nginx with PHP-FPM and `X-Accel-Redirect` support
- HTTPS for the modern Clipboard API

No package manager or compilation step is needed.

## Configuration

Create local configuration from the supplied template:

```bash
cp .env-example .env
```

```dotenv
SONG_LIBRARY_PATH=library
SONG_COVER_CACHE_PATH=public/cache/covers
SONG_DOWNLOADS_ENABLED=true
```

Relative paths are resolved from the project root; absolute paths also work. The application loads `.env` without an external package. Real process/server environment variables take precedence. Keep `.env` private and uncommitted; update `.env-example` when adding settings.

## Project structure

```text
project/
├── .env                         # local, ignored
├── .env-example
├── .gitignore
├── README.md
├── AGENTS.md
├── library/                     # private: outside Nginx document root
│   └── Song Name/
│       ├── Song Name.mp3
│       ├── Song Name.png        # full-size source cover art
│       ├── Song Name-lyrics.txt
│       ├── .song-id
└── public/                      # Nginx document root
    ├── index.php
    ├── assets/
    │   ├── app.js
    │   ├── site.css
    │   ├── record.svg
    │   └── pickup-needle.ogg
    └── cache/
        └── covers/              # generated lightweight JPGs only
```

The original MP3, full-size PNG, lyrics, and ID files never need to exist under `public/`. PHP reads them from `library/`. Cover PNGs are converted into public half-size JPEGs at quality 75. MP3 playback uses an opaque `/media/<id>/audio.mp3` endpoint; PHP authorizes the ID and Nginx serves the private file internally with byte-range support. WebSockets are neither needed nor desirable for ordinary MP3 playback.

## Adding a song

Create one direct child folder under `library/`. The folder name becomes the displayed song name.

Required:

- One `.mp3` file

Optional:

- Full-size source cover art: `.png` preferred; `.jpg`, `.jpeg`, and `.webp` are accepted fallbacks
- Lyrics: `-lyrics.txt` or ` - lyrics.txt`
- Other companion files

The application tolerates small filename differences, including curly apostrophes and extra spaces. When GD is available, source cover art is converted into a cached half-size JPEG at quality 75. Source artwork stays private.

Load the gallery once after adding a song. PHP creates a persistent `.song-id` when directory permissions permit it. Preserve this file: it is the song's permanent public identifier and survives folder renames.

To generate IDs and cover caches from the command line:

```bash
REQUEST_URI=/ php public/index.php > /dev/null
```

## Share URLs and metadata

Every song receives an eight-character URL-safe ID:

```text
https://example.com/s/zWOyqMC4
```

Opening this URL:

- Selects the requested song
- Generates song-specific title and description metadata
- Generates `og:*` song, JPEG image, and audio metadata
- Generates Twitter card metadata
- Emits a canonical share URL

Selecting another song updates the browser URL with `history.replaceState()`. This keeps the address bar shareable without filling browser history. Clicking **DIRECT LINK** also copies the full share URL to the clipboard.

IDs are public identifiers, not authentication or access-control tokens. Private filesystem placement prevents direct path browsing, but playable songs remain publicly retrievable through the media endpoint.

### Nginx routes

Nginx exposes clean share and media URLs while keeping the library location internal:

```nginx
location ^~ /s/ {
    rewrite "^/s/([A-Za-z0-9_-]{8})/?$" /index.php?share=$1 last;
    return 404;
}

location ^~ /media/ {
    rewrite "^/media/([A-Za-z0-9_-]{8})/audio\\.mp3$" /index.php?media=$1 last;
    return 404;
}

location ^~ /_song_library/ {
    internal;
    alias /absolute/path/to/project/library/;
    autoindex off;
    disable_symlinks on;
}
```


After editing Nginx:

```bash
nginx -t
systemctl restart nginx
```

## Controls

### Pointer

- Drag gallery: browse songs
- Click cover art: select song; lift record when sleeved
- Click record: play or pause
- Drag record left/right: seek backward or forward
- Drag record down: return it to sleeve
- Scroll over raised record: adjust volume
- Leave and re-enter a song after sleeving: lift record again

### Touch-only devices

Touch-specific help replaces pointer and keyboard help when CSS detects a coarse primary pointer with no hover-capable input.

### Keyboard

| Keys | Action |
|---|---|
| `Left` / `A` | Browse left |
| `Right` / `D` | Browse right |
| `Down` / `S` | Sleeve selected record |
| `Up` / `W` | Unsleeve selected record |
| `Enter` / `Space` | Unsleeve when sleeved; otherwise play or pause |

## Downloads

**DOWNLOAD ZIP** creates a fresh archive from private song files.

Downloads can be disabled globally with `SONG_DOWNLOADS_ENABLED=0`, or per song by adding an empty `.no-download` file to its library folder. The server then rejects ZIP requests, hides the download action, and centers the direct link. This controls the ZIP feature only; browsers must still receive audio to play a song.

- Hidden files are excluded
- All source image files are excluded
- Exactly one generated JPG cover is included
- MP3, lyrics, and other companion files are included
- Filename is lowercase, uses underscores for spaces, and removes special characters

Example:

```text
theres_a_way_back_home.zip
```

## Room color

The fixed room-color swatch expands to show **ROOM COLOR** and **DEFAULT** when pressed. The picker changes the background while preserving the dark gradient. The selected color is stored in local storage under:

```text
listening-room-background
```

**DEFAULT** clears the saved value and restores the original deep-purple theme.

## Validation

```bash
php -l public/index.php
node --check public/assets/app.js
nginx -t
```

Example live checks:

```bash
curl -I https://example.com/
curl -I https://example.com/s/zWOyqMC4
curl -I https://example.com/cache/covers/zWOyqMC4.jpg
curl -I https://example.com/media/zWOyqMC4/audio.mp3
curl -H 'Range: bytes=0-1023' -I https://example.com/media/zWOyqMC4/audio.mp3
```
