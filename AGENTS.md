# AGENTS.md

## Scope

These instructions apply to the complete song-gallery project. Application code lives under `public/`; private song content lives under `library/`.

## Architecture

- Keep the application dependency-free: PHP, HTML, CSS, and vanilla JavaScript.
- `public/index.php` discovers songs from direct child folders under `library/` by default.
- `SONG_LIBRARY_PATH` and `SONG_COVER_CACHE_PATH` may override those project-root-relative locations.
- One library folder is one logical song.
- Never place source MP3s, full-size cover art, lyrics, or ID files under the public web root.
- Public cover URLs point only to generated JPGs under `public/cache/covers/`.
- Public audio URLs are `/media/<id>/audio.mp3`. PHP resolves the opaque ID, then `X-Accel-Redirect` lets Nginx serve the private MP3 with Range support.
- Nginx's `/_song_library/` alias must remain `internal`; never expose a browsable library alias.
- PHP owns discovery, cover caching, ZIP generation, stable IDs, route resolution, media authorization, and social metadata.
- JavaScript owns carousel behavior, playback, gestures, clipboard copying, URL synchronization, and saved room color.
- CSS owns responsive presentation and input-capability-specific help.
- Share routes are `/s/<id>`. Nginx passes the ID internally as `?share=<id>`.
- Asset and media URLs must remain root-relative so pages work under `/s/<id>`.

## Required terminology

Use these names consistently in UI copy, comments, selectors, and variables:

- **song**: musical item
- **record**: round physical playback control
- **sleeve**: square front/back packaging
- **cover art**: song image
- **song gallery**: collection
- **carousel track**: navigation implementation

Do not reintroduce `album`, `vinyl`, or `disc` as synonyms. Do not use bare `track` to mean a song.

## Stable IDs and links

- Never delete, regenerate, or casually edit `.song-id`.
- Current IDs are eight URL-safe characters and are case-sensitive.
- Folder renames must not change the song ID.
- IDs are public identifiers, never access-control secrets.
- Direct links must remain valid server-rendered URLs so social crawlers receive correct metadata.
- A selected song must update the address bar using `replaceState`, not `pushState`, to avoid history spam.

## Song content safety

- Treat all files under `library/` as user content.
- Never delete, rename, re-encode, or overwrite private media unless explicitly requested.
- Preserve MP3 audio streams when editing metadata.
- Prefer full-size PNG source cover art in each library folder.
- Generated public JPG covers are disposable cache files; source cover art is not.
- Generated ZIPs must exclude hidden files and all source images, add exactly one cached JPG, and retain MP3, lyrics, and companion files.
- Enforce global `SONG_DOWNLOADS_ENABLED=0` and per-song `.no-download` on both UI and ZIP endpoint.
- Disabling ZIP downloads does not disable playback or make browser-delivered audio impossible to save.
- Preserve Unicode filenames and folder names.

## Interface behavior to preserve

- No visible play/pause button; the exposed record is the playback control.
- Only one song may play at once.
- Paused record animations resume without rotation jumps.
- A returned record stays sleeved while the pointer remains inside its song card.
- Leaving and re-entering, clicking cover art, or using unsleeve keys may lift it again.
- No share ID means a random initial song.
- A valid share route selects its song.
- Direct-link clicks update/copy the clean `/s/<id>` URL and show brief feedback.
- Touch-only help is selected with input-capability media queries, not user-agent detection.
- User background color persists in local storage; deep purple remains the default.

## Accessibility

- Preserve useful ARIA labels and native button/link semantics.
- Do not hijack keyboard events from links, sleeve buttons, form fields, or editable content.
- Maintain reduced-motion behavior.
- Keep focus-visible styling and sufficient contrast.

## Environment configuration

- Local settings live in project-root `.env`, above `public/`; never commit that file.
- Keep `.env-example` current and free of secrets.
- Server/process environment values override `.env` values.
- Keep the dependency-free loader conservative; do not turn it into a shell parser.

## Editing rules

- Bump the query-string version in `index.php` after changing `assets/site.css` or `assets/app.js`.
- Keep visible help text synchronized with actual controls.
- Keep per-song Open Graph, Twitter, canonical, JPG image, and audio metadata server-rendered.
- If the public domain changes, update `$siteOrigin` in `index.php` and Nginx configuration.
- Nginx configuration currently lives outside this project at `/etc/nginx/sites-enabled/<site>`.

## Validation

Run proportionate checks after changes:

```bash
php -l public/index.php
node --check public/assets/app.js
nginx -t
```

For share, media, or routing changes, also verify:

- `/` returns 200
- Every `/s/<id>` returns 200 with matching metadata
- Invalid `/s/...` returns 404
- Direct `/library/...`, old `/songs/...`, and external `/_song_library/...` requests cannot expose private files
- CSS, JavaScript, cached JPG, and media URLs return correct content types
- Media Range requests return `206 Partial Content`
- ZIP downloads open successfully, contain exactly one JPG, and contain no PNG files

Do not restart Nginx unless its configuration changed and `nginx -t` succeeds first.
