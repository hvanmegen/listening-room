/**
 * Song gallery carousel and playback controller.
 * Keeps carousel rotation, needle cue, audio, sleeve flip, and record gestures in sync.
 */
(() => {
  const roomColor = document.querySelector('#roomColor');
  const resetRoomColor = document.querySelector('#resetRoomColor');
  const roomColorControl = roomColor?.closest('.room-color-control');
  const rootStyle = document.documentElement.style;
  const defaultRoomColor = '#24121e';
  const roomColorKey = 'listening-room-background';

  /** Convert a hex color to HSL for related dark gradient shades. */
  function hexToHsl(hex) {
    const channels = [1, 3, 5].map((offset) => parseInt(hex.slice(offset, offset + 2), 16) / 255);
    const [r, g, b] = channels;
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const lightness = (max + min) / 2;
    if (max === min) return [0, 0, lightness * 100];
    const delta = max - min;
    const saturation = delta / (1 - Math.abs(2 * lightness - 1));
    let hue = max === r
      ? ((g - b) / delta) % 6
      : max === g ? (b - r) / delta + 2 : (r - g) / delta + 4;
    hue = (hue * 60 + 360) % 360;
    return [hue, saturation * 100, lightness * 100];
  }

  const hsl = (hue, saturation, lightness) => (
    `hsl(${Math.round(hue)} ${Math.round(saturation)}% ${Math.round(lightness)}%)`
  );

  /** Apply one chosen color while retaining the room's dark gradient depth. */
  function applyRoomColor(color) {
    if (!/^#[a-f0-9]{6}$/i.test(color)) return;
    const [hue, saturation, lightness] = hexToHsl(color);
    rootStyle.setProperty('--room-middle', color);
    rootStyle.setProperty('--room-glow', hsl(hue, Math.min(100, saturation + 18), Math.min(60, lightness + 14)));
    rootStyle.setProperty('--room-start', hsl(hue + 5, Math.min(85, saturation + 8), Math.min(18, Math.max(4, lightness * .35))));
    rootStyle.setProperty('--room-end', hsl(hue + 35, Math.min(75, saturation + 10), Math.min(16, Math.max(4, lightness * .28))));
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', color);
  }

  if (roomColor && resetRoomColor && roomColorControl) {
    const setColorControlExpanded = (expanded) => {
      roomColorControl.classList.toggle('is-expanded', expanded);
      roomColor.setAttribute('aria-expanded', String(expanded));
    };

    roomColor.addEventListener('click', () => setColorControlExpanded(true));
    document.addEventListener('pointerdown', (event) => {
      if (!roomColorControl.contains(event.target)) setColorControlExpanded(false);
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') setColorControlExpanded(false);
    });

    try {
      const savedColor = window.localStorage.getItem(roomColorKey);
      if (savedColor) {
        roomColor.value = savedColor;
        applyRoomColor(savedColor);
      }
    } catch (_) {}

    roomColor.addEventListener('input', () => {
      applyRoomColor(roomColor.value);
      try { window.localStorage.setItem(roomColorKey, roomColor.value); } catch (_) {}
    });
    resetRoomColor.addEventListener('click', () => {
      roomColor.value = defaultRoomColor;
      ['--room-glow', '--room-start', '--room-middle', '--room-end'].forEach((property) => rootStyle.removeProperty(property));
      document.querySelector('meta[name="theme-color"]')?.setAttribute('content', '#14101a');
      try { window.localStorage.removeItem(roomColorKey); } catch (_) {}
      setColorControlExpanded(false);
      roomColor.focus({ preventScroll: true });
    });
  }

  const viewport = document.querySelector('#songCarousel');
  const carouselTrack = document.querySelector('#songGallery');
  const cards = [...document.querySelectorAll('.song-card')];
  if (!viewport || !carouselTrack || !cards.length) return;

  const playerOf = (card) => card.querySelector('audio');
  const step = 360 / cards.length;
  const cardMatchesSongId = (card, requestedId) => card.dataset.songId === requestedId;
  const songIndexFromLocation = () => {
    const pathMatch = window.location.pathname.match(/\/s\/([A-Za-z0-9_-]{8})\/?$/);
    const requestedId = window.location.hash.slice(1) ||
      pathMatch?.[1] || viewport.dataset.initialSongId;
    return cards.findIndex((card) => cardMatchesSongId(card, requestedId));
  };
  const requestedIndex = songIndexFromLocation();
  const initialIndex = requestedIndex >= 0
    ? requestedIndex
    : Math.floor(Math.random() * cards.length);

  // One shared needle player prevents overlapping cue effects.
  const needle = new Audio('/assets/pickup-needle.ogg');
  needle.preload = 'auto';
  needle.volume = 0.25;

  let cueToken = 0;
  let cueTimer = 0;
  let carouselRotation = 0;
  let activeIndex = 0;
  let carouselDrag = null;
  let suppressCarouselClick = false;
  const copyResetTimers = new WeakMap();

  /** Copy text in browsers where the asynchronous Clipboard API is unavailable. */
  function fallbackCopy(text) {
    const field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.opacity = '0';
    document.body.append(field);
    field.select();
    const copied = document.execCommand('copy');
    field.remove();
    return copied;
  }

  /** Copy a clean absolute song URL and show short inline feedback. */
  async function copyDirectLink(link) {
    const directUrl = new URL(link.href, window.location.origin);

    let copied = false;
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(directUrl.href);
        copied = true;
      } else {
        copied = fallbackCopy(directUrl.href);
      }
    } catch (_) {
      copied = fallbackCopy(directUrl.href);
    }

    const previousTimer = copyResetTimers.get(link);
    if (previousTimer) window.clearTimeout(previousTimer);
    link.textContent = copied ? 'LINK COPIED' : 'COPY FAILED';
    copyResetTimers.set(link, window.setTimeout(() => {
      link.textContent = 'DIRECT LINK';
      copyResetTimers.delete(link);
    }, 1400));
  }

  /** Keep array navigation wrapped at both ends. */
  const wrapIndex = (index) => (index % cards.length + cards.length) % cards.length;

  /** Format scrub feedback as minutes and zero-padded seconds. */
  function formatTime(seconds) {
    const safeSeconds = Math.max(0, Number.isFinite(seconds) ? seconds : 0);
    return `${Math.floor(safeSeconds / 60)}:${String(Math.floor(safeSeconds % 60)).padStart(2, '0')}`;
  }

  /**
   * Rotate the carousel track while counter-rotating every sleeve.
   * Counter-rotation keeps song sleeves facing the camera around the 3D ring.
   */
  function renderCarousel(rotation) {
    carouselRotation = rotation;
    carouselTrack.style.setProperty('--carousel-rotation', `${rotation}deg`);
    cards.forEach((card, index) => {
      const slotAngle = index * step;
      card.style.setProperty('--slot-angle', `${slotAngle}deg`);
      card.style.setProperty('--counter-angle', `${-(slotAngle + rotation)}deg`);
    });
  }

  /** Find the shortest equivalent rotation that brings one song forward. */
  function rotationForIndex(index) {
    const baseRotation = -index * step;
    const nearestTurn = Math.round((carouselRotation - baseRotation) / 360);
    return baseRotation + nearestTurn * 360;
  }

  /** Keep address bar and basic page metadata aligned with selected song. */
  function syncSongUrl(card) {
    const shareUrl = new URL(`/s/${encodeURIComponent(card.dataset.songId)}`, window.location.origin);
    if (window.location.href !== shareUrl.href) {
      window.history.replaceState(null, '', shareUrl.href);
    }
    viewport.dataset.initialSongId = card.dataset.songId;

    const pageTitle = `${card.dataset.songName} · Listening Room`;
    const description = `Listen to “${card.dataset.songName}” in the Listening Room.`;
    document.title = pageTitle;
    document.querySelector('link[rel="canonical"]')?.setAttribute('href', shareUrl.href);
    document.querySelector('meta[name="description"]')?.setAttribute('content', description);
    document.querySelector('meta[property="og:title"]')?.setAttribute('content', pageTitle);
    document.querySelector('meta[property="og:description"]')?.setAttribute('content', description);
    document.querySelector('meta[property="og:url"]')?.setAttribute('content', shareUrl.href);
    document.querySelector('meta[name="twitter:title"]')?.setAttribute('content', pageTitle);
    document.querySelector('meta[name="twitter:description"]')?.setAttribute('content', description);
  }

  /** Keep hidden and off-axis controls out of pointer and keyboard navigation. */
  function syncCardInteractivity(card, isActive = card.classList.contains('is-active')) {
    const isFlipped = card.classList.contains('is-flipped');
    const record = card.querySelector('.record-drawer');
    const front = card.querySelector('.sleeve-front');
    const back = card.querySelector('.sleeve-back');

    record.inert = !isActive;
    front.inert = !isActive || isFlipped;
    back.inert = !isActive || !isFlipped;
  }

  /** Select one front song without automatically starting it. */
  function selectCard(index, { raise = true } = {}) {
    const previousIndex = activeIndex;
    activeIndex = wrapIndex(index);

    // A sleeved record stays down until pointer re-entry or this song rotates away.
    if (activeIndex !== previousIndex) {
      cards[previousIndex].classList.remove('is-dismissed');
    }

    const activeCard = cards[activeIndex];

    cards.forEach((card, cardIndex) => {
      const isActive = cardIndex === activeIndex;
      card.classList.toggle('is-active', isActive);
      card.setAttribute('aria-selected', String(isActive));

      if (!isActive && !card.classList.contains('is-playing') && !card.classList.contains('is-cueing')) {
        card.classList.remove('is-raised', 'is-flipped');
      }
      syncCardInteractivity(card, isActive);
    });

    if (raise) activeCard.classList.add('is-raised');
    syncSongUrl(activeCard);
    renderCarousel(rotationForIndex(activeIndex));
  }

  /** Stop the cue and invalidate any delayed song start. */
  function cancelNeedle() {
    cueToken += 1;
    clearTimeout(cueTimer);
    needle.onerror = null;
    needle.pause();
    try { needle.currentTime = 0; } catch (_) {}
    cards.forEach((card) => card.classList.remove('is-cueing'));
  }

  /** Enforce one audible song at a time. */
  function stopOtherCards(activeCard) {
    cards.forEach((card) => {
      if (card === activeCard) return;
      playerOf(card).pause();
      card.classList.remove('is-playing', 'is-raised', 'is-cueing');
    });
  }

  /** Start or resume a song; its play event starts both visual animations. */
  function beginAudio(card, fromNeedle = false) {
    if (!fromNeedle) cancelNeedle();
    stopOtherCards(card);
    card.classList.remove('is-cueing');
    card.classList.add('is-raised');
    playerOf(card).play().catch(() => card.classList.remove('is-raised'));
  }

  /**
   * Start the needle immediately, then start music 1.5 seconds later.
   * The 2.76-second cue keeps playing so its existing fade overlaps the song.
   */
  function cueFirstPlay(card) {
    cancelNeedle();
    stopOtherCards(card);

    const token = cueToken;
    const player = playerOf(card);
    player.dataset.needlePlayed = 'true';
    card.classList.add('is-cueing', 'is-raised');

    let finished = false;
    const finish = () => {
      if (finished || token !== cueToken) return;
      finished = true;
      clearTimeout(cueTimer);
      beginAudio(card, true);
    };

    needle.onerror = finish;
    cueTimer = window.setTimeout(finish, 1500);
    needle.play().catch(finish);
  }

  /** Shared playback command for exposed record interaction. */
  function togglePlayback(card) {
    const player = playerOf(card);
    selectCard(Number(card.dataset.index));

    if (card.classList.contains('is-cueing')) {
      cancelNeedle();
      card.classList.remove('is-raised');
      return;
    }

    if (!player.paused) {
      cancelNeedle();
      player.pause();
      card.classList.add('is-raised');
      return;
    }

    if (player.dataset.needlePlayed !== 'true') cueFirstPlay(card);
    else beginAudio(card);
  }

  /** Show only this song's lyrics back. */
  function flipToLyrics(card, { moveFocus = false } = {}) {
    selectCard(Number(card.dataset.index));
    cards.forEach((other) => {
      if (other !== card) {
        other.classList.remove('is-flipped');
        syncCardInteractivity(other);
      }
    });
    card.classList.add('is-flipped');
    syncCardInteractivity(card);
    if (moveFocus) card.querySelector('.front-tab').focus({ preventScroll: true });
  }

  /** Drag-down action: pause, retract, and restore the front cover. */
  function dismissRecord(card) {
    cancelNeedle();
    const player = playerOf(card);
    player.pause();
    player.currentTime = 0;
    delete player.dataset.needlePlayed;
    card.dispatchEvent(new Event('recordreset'));
    card.classList.remove('is-playing', 'is-raised', 'is-cueing', 'is-flipped');
    card.classList.add('is-dismissed');
    syncCardInteractivity(card);
  }

  /** Lift a sleeved record without starting playback. */
  function unsleeveRecord(card) {
    card.classList.remove('is-dismissed');
    card.classList.add('is-raised');
  }



  cards.forEach((card) => {
    const player = playerOf(card);
    const record = card.querySelector('.record-drawer');
    const recordBody = card.querySelector('.record-body');
    const hint = card.querySelector('.drag-hint');
    const coverSurface = card.querySelector('.cover-surface');
    const lyricsTab = card.querySelector('.lyrics-tab');
    const frontTab = card.querySelector('.front-tab');
    const directLink = card.querySelector('.direct-link');

    let recordDrag = null;
    let scrubAngle = 0;
    let volumeHintTimer = 0;
    let suppressRecordClick = false;
    let raiseOnPointerEnter = false;

    card.addEventListener('recordreset', () => {
      raiseOnPointerEnter = false;
      scrubAngle = 0;
      recordBody.style.setProperty('--scrub-angle', '0deg');
      hint.textContent = 'SLEEVE';
    });

    // Keep a returned record down while the pointer remains over its card.
    // Leaving arms it; entering again restores normal hover lift.
    card.addEventListener('pointerleave', (event) => {
      if (event.pointerType !== 'touch' && card.classList.contains('is-dismissed')) {
        raiseOnPointerEnter = true;
      }
    });
    card.addEventListener('pointerenter', () => {
      if (!raiseOnPointerEnter) return;
      raiseOnPointerEnter = false;
      unsleeveRecord(card);
    });

    // Cover art selects its song and restores its record only when sleeved.
    coverSurface.addEventListener('click', () => {
      card.classList.remove('is-flipped');
      syncCardInteractivity(card);
      if (card.classList.contains('is-dismissed')) {
        raiseOnPointerEnter = false;
        unsleeveRecord(card);
      }
      selectCard(Number(card.dataset.index));
    });
    lyricsTab.addEventListener('click', (event) => flipToLyrics(card, { moveFocus: event.detail === 0 }));
    frontTab.addEventListener('click', (event) => {
      card.classList.remove('is-flipped');
      syncCardInteractivity(card);
      if (event.detail === 0) lyricsTab.focus({ preventScroll: true });
    });
    directLink.addEventListener('click', (event) => {
      event.preventDefault();
      window.history.replaceState(null, '', directLink.href);
      viewport.dataset.initialSongId = card.dataset.songId;
      copyDirectLink(directLink);
    });

    player.addEventListener('play', () => {
      stopOtherCards(card);
      card.classList.add('is-playing', 'is-raised');
    });
    player.addEventListener('pause', () => {
      card.classList.remove('is-playing');
    });
    player.addEventListener('ended', () => {
      card.classList.remove('is-playing', 'is-raised');
      player.currentTime = 0;
    });

    // The exposed record owns its vertical and horizontal control gestures.
    // Mouse wheel/trackpad adjusts song volume only over the raised record.
    record.addEventListener('wheel', (event) => {
      const canAdjust = card.classList.contains('is-active') && (
        card.classList.contains('is-raised') ||
        card.classList.contains('is-playing') ||
        card.classList.contains('is-cueing')
      );
      if (!canAdjust) return;

      event.preventDefault();
      const normalizedDelta = event.deltaMode === 1
        ? event.deltaY * 16
        : event.deltaMode === 2 ? event.deltaY * 100 : event.deltaY;
      player.volume = Math.max(0, Math.min(1, player.volume - normalizedDelta * 0.001));

      hint.textContent = `VOLUME ${Math.round(player.volume * 100)}%`;
      card.classList.add('is-adjusting-volume');
      clearTimeout(volumeHintTimer);
      volumeHintTimer = window.setTimeout(() => card.classList.remove('is-adjusting-volume'), 700);
    }, { passive: false });

    record.addEventListener('pointerdown', (event) => {
      const raised = card.classList.contains('is-active') && (
        card.classList.contains('is-raised') ||
        card.classList.contains('is-playing') ||
        card.classList.contains('is-cueing') ||
        card.matches(':hover')
      );

      if (!raised || event.button !== 0) return;
      recordDrag = {
        id: event.pointerId,
        x: event.clientX,
        y: event.clientY,
        dx: 0,
        dy: 0,
        axis: null,
        startTime: player.currentTime,
        startAngle: scrubAngle,
        wasPlaying: !player.paused,
      };
      record.setPointerCapture(event.pointerId);
    });

    record.addEventListener('pointermove', (event) => {
      if (!recordDrag || recordDrag.id !== event.pointerId) return;

      recordDrag.dx = event.clientX - recordDrag.x;
      recordDrag.dy = event.clientY - recordDrag.y;

      // Lock one axis after a dead zone to prevent diagonal accidents.
      if (!recordDrag.axis && Math.hypot(recordDrag.dx, recordDrag.dy) > 12) {
        recordDrag.axis = Math.abs(recordDrag.dx) > Math.abs(recordDrag.dy) ? 'x' : 'y';
        card.classList.add('is-dragging', 'is-raised');

        if (recordDrag.axis === 'x') {
          if (card.classList.contains('is-cueing')) cancelNeedle();
          if (recordDrag.wasPlaying) player.pause();
          card.classList.add('is-scrubbing');
        }
      }
      if (!recordDrag.axis) return;

      if (recordDrag.axis === 'x') {
        // Six visual turns across one record width gives precise, useful seeking.
        const turns = (recordDrag.dx / record.clientWidth) * 6;
        const nextAngle = recordDrag.startAngle + turns * 360;
        scrubAngle = nextAngle;
        recordBody.style.setProperty('--scrub-angle', `${nextAngle}deg`);
        record.style.setProperty('--drag-x', '0px');
        record.style.setProperty('--drag-y', '0px');

        if (Number.isFinite(player.duration) && player.duration > 0) {
          // Each full manual turn commits one five-second seek step.
          const seekSteps = Math.round(turns);
          const nextTime = Math.max(0, Math.min(player.duration, recordDrag.startTime + seekSteps * 5));
          player.currentTime = nextTime;
          hint.textContent = `${formatTime(nextTime)} / ${formatTime(player.duration)}`;
        } else {
          hint.textContent = 'LOADING';
        }
      } else {
        // The raised state is exactly -60%. Clamp the downward drag to +60%
        // so the record can return into its sleeve but never pass through it.
        const sleeveDepth = record.clientHeight * 0.6;
        const insertedDistance = Math.min(sleeveDepth, Math.max(0, recordDrag.dy));
        record.style.setProperty('--drag-x', '0px');
        record.style.setProperty('--drag-y', `${insertedDistance}px`);
        hint.textContent = 'SLEEVE';
      }
    });

    function finishRecordDrag(event) {
      if (!recordDrag || recordDrag.id !== event.pointerId) return;

      const moved = recordDrag.axis !== null;
      const { axis, dy, wasPlaying } = recordDrag;
      recordDrag = null;

      if (record.hasPointerCapture(event.pointerId)) record.releasePointerCapture(event.pointerId);
      record.style.setProperty('--drag-x', '0px');
      record.style.setProperty('--drag-y', '0px');
      card.classList.remove('is-dragging', 'is-scrubbing');
      suppressRecordClick = moved;

      if (axis === 'y' && dy > 75) {
        dismissRecord(card);
      } else if (axis === 'x' && wasPlaying) {
        player.play().catch(() => {});
      }
    }

    record.addEventListener('pointerup', finishRecordDrag);
    record.addEventListener('pointercancel', finishRecordDrag);
    record.addEventListener('click', (event) => {
      if (suppressRecordClick) {
        suppressRecordClick = false;
        event.preventDefault();
        return;
      }
      togglePlayback(card);
    });

  });

  // Page-level browsing and playback shortcuts. Links and sleeve controls
  // keep their native keyboard behavior; the record uses these same shortcuts.
  document.addEventListener('keydown', (event) => {
    if (event.altKey || event.ctrlKey || event.metaKey) return;

    const target = event.target instanceof Element ? event.target : null;
    if (target?.closest('input, textarea, select, [contenteditable="true"]')) return;
    if (target?.closest('a, button:not(.record-drawer)')) return;

    const key = event.key.toLowerCase();
    if (key === 'arrowleft' || key === 'a') {
      event.preventDefault();
      selectCard(activeIndex - 1);
      return;
    }
    if (key === 'arrowright' || key === 'd') {
      event.preventDefault();
      selectCard(activeIndex + 1);
      return;
    }

    const activeCard = cards[activeIndex];
    if (key === 'arrowdown' || key === 's') {
      event.preventDefault();
      if (!activeCard.classList.contains('is-dismissed')) dismissRecord(activeCard);
      return;
    }

    if (key === 'arrowup' || key === 'w') {
      event.preventDefault();
      if (activeCard.classList.contains('is-dismissed')) unsleeveRecord(activeCard);
      return;
    }

    if (key === 'enter' || key === ' ') {
      event.preventDefault();
      if (event.repeat) return;
      if (activeCard.classList.contains('is-dismissed')) unsleeveRecord(activeCard);
      else togglePlayback(activeCard);
    }
  });

  // Block the browser's image ghost/copy operation inside the carousel.
  viewport.addEventListener('dragstart', (event) => event.preventDefault());

  // Dragging empty space or cover art turns the full song carousel.
  viewport.addEventListener('pointerdown', (event) => {
    if (event.button !== 0 || event.target.closest('button, a, .lyrics-window')) return;

    const pressedCard = event.target.closest('.song-card');
    carouselDrag = {
      id: event.pointerId,
      x: event.clientX,
      startRotation: carouselRotation,
      moved: false,
      cardIndex: pressedCard ? Number(pressedCard.dataset.index) : null,
    };
  });

  viewport.addEventListener('pointermove', (event) => {
    if (!carouselDrag || carouselDrag.id !== event.pointerId) return;

    const deltaX = event.clientX - carouselDrag.x;
    if (Math.abs(deltaX) > 6 && !carouselDrag.moved) {
      carouselDrag.moved = true;
      viewport.classList.add('is-carousel-dragging');
      viewport.setPointerCapture(event.pointerId);
    }
    if (!carouselDrag.moved) return;

    renderCarousel(carouselDrag.startRotation + deltaX * 0.28);
  });

  function finishCarouselDrag(event) {
    if (!carouselDrag || carouselDrag.id !== event.pointerId) return;

    const { moved, cardIndex } = carouselDrag;
    carouselDrag = null;
    if (viewport.hasPointerCapture(event.pointerId)) viewport.releasePointerCapture(event.pointerId);
    viewport.classList.remove('is-carousel-dragging');

    // A clean press/release selects its transformed sleeve directly. This is
    // more reliable than browser click targeting inside a 3D scene.
    if (!moved) {
      if (cardIndex !== null) {
        cards[cardIndex].classList.remove('is-flipped');
        selectCard(cardIndex);
      }
      return;
    }

    // Snap the nearest ring position to the front after release.
    const wrappedRotation = ((carouselRotation + 180) % 360 + 360) % 360 - 180;
    const nearestIndex = wrapIndex(Math.round(-wrappedRotation / step));
    selectCard(nearestIndex);

    suppressCarouselClick = true;
    window.setTimeout(() => { suppressCarouselClick = false; }, 0);
  }

  viewport.addEventListener('pointerup', finishCarouselDrag);
  viewport.addEventListener('pointercancel', finishCarouselDrag);
  viewport.addEventListener('click', (event) => {
    if (!suppressCarouselClick) return;
    event.preventDefault();
    event.stopImmediatePropagation();
  }, true);

  // Legacy hashes and browser history both update the selected song.
  const selectSongFromLocation = () => {
    const locationIndex = songIndexFromLocation();
    if (locationIndex >= 0) selectCard(locationIndex);
  };
  window.addEventListener('hashchange', selectSongFromLocation);
  window.addEventListener('popstate', selectSongFromLocation);

  // Lay out the ring invisibly, then enable transitions after initial paint.
  renderCarousel(0);
  selectCard(initialIndex);
  viewport.getBoundingClientRect();
  window.requestAnimationFrame(() => viewport.classList.remove('is-initializing'));
})();
