<?php
declare(strict_types=1);

/** Load simple KEY=VALUE settings without overriding server environment values. */
function loadEnvironmentFile(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        if (!str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if (preg_match('/\A[A-Z_][A-Z0-9_]*\z/', $name) !== 1 || getenv($name) !== false) {
            continue;
        }
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

loadEnvironmentFile(dirname(__DIR__) . '/.env');

$projectRoot = dirname(__DIR__);

/** Resolve an absolute path or a path relative to the project root. */
function configuredPath(string $projectRoot, string $setting, string $default): string
{
    $value = getenv($setting);
    $path = is_string($value) && trim($value) !== '' ? trim($value) : $default;
    return str_starts_with($path, '/') ? rtrim($path, '/') : $projectRoot . '/' . trim($path, '/');
}

// Every direct child directory in the private library represents one song.
$songsRoot = configuredPath($projectRoot, 'SONG_LIBRARY_PATH', 'library');
$coverCacheRoot = configuredPath($projectRoot, 'SONG_COVER_CACHE_PATH', 'public/cache/covers');
$downloadEnvironment = getenv('SONG_DOWNLOADS_ENABLED');
$downloadsEnabled = $downloadEnvironment === false
    || filter_var($downloadEnvironment, FILTER_VALIDATE_BOOL);

/** Public URL for a generated JPG cover. Source artwork stays private. */
function coverPublicPath(string $songId): string
{
    return '/cache/covers/' . rawurlencode($songId) . '.jpg';
}

/** Public endpoint for streamed audio. Filesystem names never enter the URL. */
function audioPublicPath(string $songId): string
{
    return '/media/' . rawurlencode($songId) . '/audio.mp3';
}

/**
 * Find the documented exact filename first, then tolerate small differences.
 * The fallback handles curly apostrophes and extra spaces in uploaded files.
 */
function findSongFile(string $folderPath, string $songName, array $suffixes): ?string
{
    // Prefer: <folder name><suffix>.
    foreach ($suffixes as $suffix) {
        $candidate = $songName . $suffix;
        if (is_file($folderPath . '/' . $candidate)) {
            return $candidate;
        }
    }

    // Otherwise accept the first file with a matching suffix.
    foreach (scandir($folderPath) ?: [] as $candidate) {
        if (!is_file($folderPath . '/' . $candidate)) {
            continue;
        }
        foreach ($suffixes as $suffix) {
            if (str_ends_with(strtolower($candidate), strtolower($suffix))) {
                return $candidate;
            }
        }
    }

    return null;
}

/** Convert any seed into a compact eight-character URL-safe ID. */
function compactSongId(string $seed): string
{
    return substr(rtrim(strtr(base64_encode(hash('sha256', $seed, true)), '+/', '-_'), '='), 0, 8);
}

/** Return a persistent opaque ID which survives folder renames. */
function songId(string $folderPath): string
{
    $idPath = $folderPath . '/.song-id';
    $fallback = compactSongId($folderPath);
    $storedReadOnly = trim((string) @file_get_contents($idPath));
    if (preg_match('/\A[A-Za-z0-9_-]{8}\z/', $storedReadOnly) === 1) {
        return $storedReadOnly;
    }

    $handle = @fopen($idPath, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return $fallback;
    }

    try {
        rewind($handle);
        $stored = trim((string) stream_get_contents($handle));
        if (preg_match('/\A[A-Za-z0-9_-]{8}\z/', $stored) === 1) {
            return $stored;
        }

        $id = compactSongId(random_bytes(32));

        rewind($handle);
        ftruncate($handle, 0);
        if (fwrite($handle, $id . "\n") !== 9) {
            return $fallback;
        }
        fflush($handle);
        @chmod($idPath, 0644);
        return $id;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** Stop an endpoint request without rendering the gallery. */
function abortRequest(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

/** Resolve a song by its opaque ID. */
function findSongDirectory(string $songsRoot, string $requestedId): ?array
{
    if (preg_match('/\A[A-Za-z0-9_-]{8}\z/', $requestedId) !== 1) {
        return null;
    }

    foreach (is_dir($songsRoot) ? (scandir($songsRoot) ?: []) : [] as $candidate) {
        $candidatePath = $songsRoot . '/' . $candidate;
        if ($candidate === '.' || $candidate === '..' || !is_dir($candidatePath)) {
            continue;
        }
        $mp3 = findSongFile($candidatePath, $candidate, ['.mp3']);
        if ($mp3 !== null && hash_equals(songId($candidatePath), $requestedId)) {
            return [
                'folder' => $candidate,
                'path' => $candidatePath,
                'id' => songId($candidatePath),
                'mp3' => $mp3,
            ];
        }
    }

    return null;
}

/** Find source cover art, preferring the full-size PNG. */
function findSourceCover(string $folderPath, string $songName): ?string
{
    foreach (['.png', '.jpg', '.jpeg', '.webp'] as $extension) {
        $filename = findSongFile($folderPath, $songName, [$extension]);
        if ($filename !== null) {
            return $filename;
        }
    }
    return null;
}

/**
 * Create a public, lightweight JPG cache from private source cover art.
 *
 * Output uses half the source dimensions and JPEG quality 75. Atomic rename
 * prevents concurrent requests from exposing a partially written JPG.
 */
function ensureCachedJpegCover(
    string $folderPath,
    string $songName,
    string $songId,
    string $coverCacheRoot
): ?string {
    $sourceFilename = findSourceCover($folderPath, $songName);
    if ($sourceFilename === null) {
        return null;
    }

    $cacheFilename = $songId . '.jpg';
    $targetPath = $coverCacheRoot . '/' . $cacheFilename;
    $sourcePath = $folderPath . '/' . $sourceFilename;
    $sourceModified = filemtime($sourcePath) ?: 0;
    $targetModified = is_file($targetPath) ? (filemtime($targetPath) ?: 0) : 0;
    if ($targetModified >= $sourceModified) {
        return $cacheFilename;
    }
    if (!extension_loaded('gd')) {
        return is_file($targetPath) ? $cacheFilename : null;
    }
    if (!is_dir($coverCacheRoot) && !@mkdir($coverCacheRoot, 0775, true) && !is_dir($coverCacheRoot)) {
        return null;
    }

    $extension = strtolower(pathinfo($sourceFilename, PATHINFO_EXTENSION));
    $source = match ($extension) {
        'png' => @imagecreatefrompng($sourcePath),
        'jpg', 'jpeg' => @imagecreatefromjpeg($sourcePath),
        'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
        default => false,
    };
    if ($source === false) {
        return is_file($targetPath) ? $cacheFilename : null;
    }

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $targetWidth = max(1, (int) round($sourceWidth * 0.5));
    $targetHeight = max(1, (int) round($sourceHeight * 0.5));
    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($target === false) {
        imagedestroy($source);
        return is_file($targetPath) ? $cacheFilename : null;
    }

    // JPEG has no alpha channel, so composite transparent pixels on white.
    $white = imagecolorallocate($target, 255, 255, 255);
    imagefill($target, 0, 0, $white);
    imagecopyresampled(
        $target,
        $source,
        0,
        0,
        0,
        0,
        $targetWidth,
        $targetHeight,
        $sourceWidth,
        $sourceHeight
    );
    imageinterlace($target, true);

    $temporaryPath = $targetPath . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if (imagejpeg($target, $temporaryPath, 75)) {
        @chmod($temporaryPath, 0644);
        if (!@rename($temporaryPath, $targetPath)) {
            @unlink($temporaryPath);
        }
    } else {
        @unlink($temporaryPath);
    }

    imagedestroy($target);
    imagedestroy($source);
    return is_file($targetPath) ? $cacheFilename : null;
}

/** Build and stream a fresh ZIP selected by opaque song ID. */
function downloadSongZip(
    string $songsRoot,
    string $coverCacheRoot,
    string $requestedId,
    bool $downloadsEnabled
): never {
    $song = findSongDirectory($songsRoot, $requestedId);
    if ($song === null) {
        abortRequest(404, 'Song not found.');
    }

    $folder = $song['folder'];
    $folderPath = $song['path'];
    if (!$downloadsEnabled || is_file($folderPath . '/.no-download')) {
        abortRequest(403, 'Downloading is disabled for this song.');
    }

    $temporaryPath = tempnam(sys_get_temp_dir(), 'song-download-');
    if ($temporaryPath === false) {
        abortRequest(500, 'Could not prepare download.');
    }

    $zip = new ZipArchive();
    if ($zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($temporaryPath);
        abortRequest(500, 'Could not prepare download.');
    }

    // Include exactly one generated JPG cover; never include private source art.
    $cachedCover = ensureCachedJpegCover($folderPath, $folder, $song['id'], $coverCacheRoot);
    if ($cachedCover !== null) {
        $zip->addFile($coverCacheRoot . '/' . $cachedCover, $folder . '/' . $folder . '.jpg');
    }

    foreach (scandir($folderPath) ?: [] as $filename) {
        $path = $folderPath . '/' . $filename;
        if ($filename[0] === '.' || !is_file($path) || is_link($path)) {
            continue;
        }
        if (in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'webp'], true)) {
            continue;
        }
        $zip->addFile($path, $folder . '/' . $filename);
    }

    if (!$zip->close() || !is_file($temporaryPath)) {
        @unlink($temporaryPath);
        abortRequest(500, 'Could not prepare download.');
    }

    // Friendly portable filename: lowercase, spaces to underscores, specials removed.
    $asciiFolder = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $folder);
    $filenameStem = strtolower($asciiFolder !== false ? $asciiFolder : $folder);
    $filenameStem = preg_replace('/\s+/', '_', trim($filenameStem)) ?? '';
    $filenameStem = preg_replace('/[^a-z0-9_]/', '', $filenameStem) ?? '';
    $filenameStem = trim((string) preg_replace('/_+/', '_', $filenameStem), '_');
    $downloadName = ($filenameStem !== '' ? $filenameStem : 'song') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Length: ' . (string) filesize($temporaryPath));
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Cache-Control: private, no-store');
    readfile($temporaryPath);
    @unlink($temporaryPath);
    exit;
}

/** Authorize Nginx to serve one private MP3 with native Range support. */
function serveSongAudio(string $songsRoot, string $requestedId): never
{
    $song = findSongDirectory($songsRoot, $requestedId);
    if ($song === null) {
        abortRequest(404, 'Song not found.');
    }

    header('Content-Type: audio/mpeg');
    header('Content-Disposition: inline; filename="song.mp3"');
    header('Cache-Control: public, max-age=3600');
    header('Accept-Ranges: bytes');
    header('X-Content-Type-Options: nosniff');
    header(
        'X-Accel-Redirect: /_song_library/'
        . rawurlencode($song['folder']) . '/'
        . rawurlencode($song['mp3'])
    );
    exit;
}

if (isset($_GET['download'])) {
    if (!is_string($_GET['download'])) {
        abortRequest(400, 'Invalid download request.');
    }
    downloadSongZip($songsRoot, $coverCacheRoot, $_GET['download'], $downloadsEnabled);
}

if (isset($_GET['media'])) {
    if (!is_string($_GET['media'])) {
        abortRequest(400, 'Invalid media request.');
    }
    serveSongAudio($songsRoot, $_GET['media']);
}

// Convert valid private song folders into a small view-model for the template.
$songs = [];
foreach (is_dir($songsRoot) ? (scandir($songsRoot) ?: []) : [] as $folder) {
    $folderPath = $songsRoot . '/' . $folder;
    if ($folder === '.' || $folder === '..' || !is_dir($folderPath)) {
        continue;
    }

    // Audio is required; cover art and lyrics are optional.
    $mp3 = findSongFile($folderPath, $folder, ['.mp3']);
    if ($mp3 === null) {
        continue;
    }

    $id = songId($folderPath);
    $cover = ensureCachedJpegCover($folderPath, $folder, $id, $coverCacheRoot);
    $lyricsFile = findSongFile($folderPath, $folder, ['-lyrics.txt', ' - lyrics.txt']);
    $lyrics = $lyricsFile !== null ? file_get_contents($folderPath . '/' . $lyricsFile) : false;

    $songs[] = [
        'id' => $id,
        'downloadEnabled' => $downloadsEnabled && !is_file($folderPath . '/.no-download'),
        'name' => $folder,
        'coverUrl' => $cover !== null ? coverPublicPath($id) : null,
        'audioUrl' => audioPublicPath($id),
        'lyrics' => is_string($lyrics) ? trim($lyrics) : '',
    ];
}

// Natural sorting keeps numbered or similarly named songs intuitive.
usort($songs, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

$siteOrigin = rtrim((string) (getenv('SONG_SITE_ORIGIN') ?: 'https://example.com'), '/');
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestPath = is_string($requestPath) ? $requestPath : '/';
$routeSongId = null;
if (isset($_GET['share'])) {
    if (!is_string($_GET['share']) || preg_match('/\A[A-Za-z0-9_-]{8}\z/', $_GET['share']) !== 1) {
        abortRequest(404, 'Song not found.');
    }
    $routeSongId = $_GET['share'];
} elseif (preg_match('#\A/s/([A-Za-z0-9_-]{8})/?\z#', $requestPath, $matches) === 1) {
    // Direct parsing supports PHP's development server; Nginx passes ?share=.
    $routeSongId = $matches[1];
} elseif (str_starts_with($requestPath, '/s/')) {
    abortRequest(404, 'Song not found.');
}

$selectedSong = null;
if ($routeSongId !== null) {
    foreach ($songs as $song) {
        if (hash_equals($song['id'], $routeSongId)) {
            $selectedSong = $song;
            break;
        }
    }
    if ($selectedSong === null) {
        abortRequest(404, 'Song not found.');
    }
    if (!hash_equals($selectedSong['id'], $routeSongId)) {
        header('Location: ' . $siteOrigin . '/s/' . rawurlencode($selectedSong['id']), true, 301);
        exit;
    }
}

$pageTitle = $selectedSong !== null
    ? $selectedSong['name'] . ' · Listening Room'
    : 'Song Gallery · Listening Room';
$pageDescription = $selectedSong !== null
    ? 'Listen to “' . $selectedSong['name'] . '” in the Listening Room.'
    : 'Browse songs, play records, and turn over sleeves to read the lyrics.';
$canonicalUrl = $selectedSong !== null
    ? $siteOrigin . '/s/' . rawurlencode($selectedSong['id'])
    : $siteOrigin . '/';
$shareImage = $selectedSong !== null && $selectedSong['coverUrl'] !== null
    ? $siteOrigin . $selectedSong['coverUrl']
    : null;
$shareAudio = $selectedSong !== null
    ? $siteOrigin . $selectedSong['audioUrl']
    : null;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#14101a">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:type" content="<?= $selectedSong !== null ? 'music.song' : 'website' ?>">
  <meta property="og:site_name" content="Listening Room">
  <meta property="og:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
  <?php if ($shareImage !== null): ?>
  <meta property="og:image" content="<?= htmlspecialchars($shareImage, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:image:secure_url" content="<?= htmlspecialchars($shareImage, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:image:type" content="image/jpeg">
  <meta property="og:image:alt" content="Cover art for <?= htmlspecialchars($selectedSong['name'], ENT_QUOTES, 'UTF-8') ?>">
  <?php endif; ?>
  <?php if ($shareAudio !== null): ?>
  <meta property="og:audio" content="<?= htmlspecialchars($shareAudio, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:audio:secure_url" content="<?= htmlspecialchars($shareAudio, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:audio:type" content="audio/mpeg">
  <?php endif; ?>
  <meta name="twitter:card" content="<?= $shareImage !== null ? 'summary_large_image' : 'summary' ?>">
  <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
  <?php if ($shareImage !== null): ?>
  <meta name="twitter:image" content="<?= htmlspecialchars($shareImage, ENT_QUOTES, 'UTF-8') ?>">
  <meta name="twitter:image:alt" content="Cover art for <?= htmlspecialchars($selectedSong['name'], ENT_QUOTES, 'UTF-8') ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="/assets/site.css?v=48">
</head>
<body>
  <main class="wrap">
    <!-- Page introduction -->
    <header>
      <p class="eyebrow">Welcome to the listening room</p>
      <h1>Song Gallery</h1>
      <p class="subtitle">Browse the songs. Click a record to play or pause, or turn over a sleeve to read the lyrics.</p>
    </header>

    <div class="room-color-control" aria-label="Room background color">
      <label for="roomColor">ROOM COLOR</label>
      <input class="room-color-input" id="roomColor" type="color" value="#24121e"
             aria-label="Choose room background color" aria-expanded="false">
      <button class="room-color-reset" id="resetRoomColor" type="button">DEFAULT</button>
    </div>

    <!-- Song gallery is generated from the private library at request time. -->
    <?php if ($songs === []): ?>
      <section class="empty">No songs yet. Add a folder under <code>library</code>.</section>
    <?php else: ?>
      <!-- Perspective viewport holds the draggable song carousel. -->
      <div class="carousel-viewport is-initializing" id="songCarousel"
           data-initial-song-id="<?= $selectedSong['id'] ?? '' ?>">
        <section class="gallery carousel-track" id="songGallery" aria-label="Song gallery">
        <?php foreach ($songs as $index => $song): ?>
          <article class="song-card" data-index="<?= $index ?>"
                   data-song-id="<?= $song['id'] ?>"
                   data-song-name="<?= htmlspecialchars($song['name'], ENT_QUOTES, 'UTF-8') ?>"
                   aria-label="Select song <?= htmlspecialchars($song['name'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="player-stack">
              <!-- Shadow is a separate 3D plane behind this sleeve. -->
              <span class="sleeve-shadow" aria-hidden="true"></span>

              <!-- Record combines groove artwork with spinning circular cover art. -->
              <button class="record-drawer" type="button"
                      aria-label="Play or pause <?= htmlspecialchars($song['name'], ENT_QUOTES, 'UTF-8') ?>"
                      title="Click the record to play or pause. Drag left or right to seek, drag down to return it to the sleeve, move away and back to lift it again, or scroll to adjust volume.">
                <span class="record-body" aria-hidden="true">
                  <img class="record-grooves" src="/assets/record.svg" alt="" draggable="false">
                  <span class="record-label-position">
                    <span class="record-label">
                      <span class="record-label-spinner">
                        <?php if ($song['coverUrl'] !== null): ?>
                          <img src="<?= htmlspecialchars($song['coverUrl'], ENT_QUOTES, 'UTF-8') ?>" alt="" draggable="false">
                        <?php endif; ?>
                      </span>
                    </span>
                  </span>
                  <span class="spindle-hole"></span>
                </span>
                <span class="drag-hint" aria-hidden="true">SLEEVE</span>
              </button>

              <!-- Square sleeve flips between cover artwork and lyrics. -->
              <div class="sleeve-scene">
                <div class="sleeve">
                  <!-- Front: cover art selects the song; exposed record controls playback. -->
                  <section class="sleeve-face sleeve-front">
                    <div class="cover-surface" title="Click the cover art to select this song or lift its sleeved record.">
                      <?php if ($song['coverUrl'] !== null): ?>
                        <img class="cover-art"
                             src="<?= htmlspecialchars($song['coverUrl'], ENT_QUOTES, 'UTF-8') ?>"
                             alt="Cover art for <?= htmlspecialchars($song['name'], ENT_QUOTES, 'UTF-8') ?>"
                             draggable="false">
                      <?php else: ?>
                        <span class="cover-placeholder">Cover art awaits</span>
                      <?php endif; ?>
                    </div>
                    <button class="lyrics-tab" type="button"
                            aria-label="Read lyrics for <?= htmlspecialchars($song['name'], ENT_QUOTES, 'UTF-8') ?>">READ LYRICS</button>
                  </section>

                  <!-- Back: title and independently scrollable lyrics window. -->
                  <section class="sleeve-face sleeve-back"
                           aria-label="Lyrics for <?= htmlspecialchars($song['name'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="back-heading">
                      <h2 class="back-title"><?= htmlspecialchars($song['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                    </div>
                    <?php if ($song['lyrics'] !== ''): ?>
                      <pre class="lyrics-window"><?= htmlspecialchars($song['lyrics'], ENT_QUOTES, 'UTF-8') ?></pre>
                    <?php else: ?>
                      <div class="lyrics-window no-lyrics">No lyrics supplied.</div>
                    <?php endif; ?>
                    <nav class="lyrics-actions<?= $song['downloadEnabled'] ? '' : ' is-link-only' ?>" aria-label="Song links for <?= htmlspecialchars($song['name'], ENT_QUOTES, 'UTF-8') ?>">
                      <?php if ($song['downloadEnabled']): ?>
                      <a class="lyrics-action" href="/?download=<?= $song['id'] ?>">DOWNLOAD ZIP</a>
                      <?php endif; ?>
                      <a class="lyrics-action direct-link" href="/s/<?= rawurlencode($song['id']) ?>" title="Copy direct song link to clipboard">DIRECT LINK</a>
                    </nav>
                    <button class="front-tab" type="button"
                            aria-label="View front of sleeve for <?= htmlspecialchars($song['name'], ENT_QUOTES, 'UTF-8') ?>">VIEW FRONT</button>
                  </section>
                </div>
              </div>

              <!-- Title occupies its own plane between sleeve and floor shadow. -->
              <strong class="front-title"><?= htmlspecialchars($song['name'], ENT_QUOTES, 'UTF-8') ?></strong>
            </div>

            <!-- Native controls stay hidden; assets/app.js owns playback. -->
            <audio preload="metadata" hidden>
              <source src="<?= htmlspecialchars($song['audioUrl'], ENT_QUOTES, 'UTF-8') ?>" type="audio/mpeg">
            </audio>
          </article>
        <?php endforeach; ?>
        </section>
        <p class="carousel-help">
          <span class="pointer-controls">POINTER: DRAG GALLERY TO BROWSE · CLICK RECORD TO PLAY · DRAG RECORD LEFT/RIGHT TO SEEK BACKWARD/FORWARD</span>
          <span class="touch-controls">TOUCH: SWIPE GALLERY TO BROWSE · TAP RECORD TO PLAY · DRAG RECORD LEFT/RIGHT TO SEEK BACKWARD/FORWARD</span>
          <span class="keyboard-controls">KEYBOARD: ←/A LEFT · →/D RIGHT · ↓/S SLEEVE · ↑/W UNSLEEVE · ENTER/SPACE UNSLEEVE OR PLAY</span>
        </p>
      </div>
    <?php endif; ?>
  </main>
  <!-- Playback, flip, and pointer-drag behavior. -->
  <script src="/assets/app.js?v=30"></script>
</body>
</html>
