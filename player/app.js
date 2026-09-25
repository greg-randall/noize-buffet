/* global $, YT */
'use strict';

const SAVE_EVERY_MS = 10000;
const YT_ERRORS = {2: 'bad video id', 5: 'HTML5 player error', 100: 'removed or private', 101: 'embedding disabled', 150: 'embedding disabled'};

const state = {
  songs: [],
  i: 0,
  player: null,
  ready: false,
  pendingVideo: null,  // {id, autoplay} queued before the player is ready
  interacted: false,   // user touched this song (or navigated to it)
  played: false,       // this song actually started playing during this visit
  furthest: 0,
  duration: null,
  lastSaveAt: 0,
  lastChatId: 0,
  chatState: '',       // last job state seen by the long-poll ("<running id>:<queued count>")
  jobs: null,
  agentBusy: false,
};

const cur = () => state.songs[state.i];

// Noisy on purpose: every interesting event goes to the browser console with an [nb] prefix.
const log = (...args) => console.log('[nb]', ...args);
const YT_STATES = {'-1': 'unstarted', 0: 'ended', 1: 'playing', 2: 'paused', 3: 'buffering', 5: 'cued'};
window.nb = state; // type `nb` in the console to inspect the current state

function connError(what, xhr) {
  const msg = (xhr.responseJSON && xhr.responseJSON.error) || (xhr.status ? 'HTTP ' + xhr.status : "can't reach server");
  console.error('[nb]', what, 'failed:', xhr.status, xhr.responseText);
  $('#conn-status').removeClass('d-none').text(`${what} failed: ${msg}`);
}

function connOk() {
  $('#conn-status').addClass('d-none');
}

function setStatus(text, cls) {
  $('#save-status').text(text).attr('class', 'badge ms-auto ' + cls);
}

function payload(extra) {
  const s = cur();
  return Object.assign({
    video_id: s.video_id, furthest_pct: state.furthest, duration_s: state.duration,
  }, extra || {});
}

function save(extra) {
  if (!cur()) return $.Deferred().resolve().promise();
  const idx = state.i;
  state.lastSaveAt = Date.now();
  const body = payload(extra);
  log('save →', body);
  return $.ajax({
    url: 'api.php?action=save', method: 'POST', contentType: 'application/json',
    data: JSON.stringify(body), dataType: 'json',
  }).done(res => {
    log('save ✓', res.row);
    Object.assign(state.songs[idx], res.row);
    renderRow(idx);
    setStatus('saved ' + new Date().toLocaleTimeString(), 'text-bg-success');
  }).fail(xhr => {
    const msg = (xhr.responseJSON && xhr.responseJSON.error) || xhr.status || 'network';
    console.error('[nb] save failed:', xhr.status, xhr.responseText, body);
    setStatus('save failed: ' + msg, 'text-bg-warning');
  });
}

// ---------- queue ----------

function renderRow(idx) {
  const s = state.songs[idx];
  const $tr = $(`#queue-list tr[data-idx="${idx}"]`);
  $tr.find('.rating').text(s.rating || '');
  $tr.find('.heard').text(s.unplayable_error ? 'err ' + s.unplayable_error
    : (s.furthest_pct != null ? Math.round(s.furthest_pct) + '%' : ''));
  const current = idx === state.i;
  $tr.toggleClass('table-active', current);
  $tr.find('.num').text(current ? '▶' : idx + 1).attr('title', current ? 'Now playing' : null);
}

// Highlight the current song (▶ in place of its number); with scroll, move the playlist so it sits at the top.
function markCurrent(scroll) {
  state.songs.forEach((_, idx) => renderRow(idx));
  if (!scroll) return;
  const tr = document.querySelector(`#queue-list tr[data-idx="${state.i}"]`);
  const box = document.getElementById('queue-scroll');
  if (!tr || !box) return;
  const header = box.querySelector('thead').offsetHeight;
  box.scrollTo({top: Math.max(0, tr.offsetTop - header), behavior: 'smooth'});
}

function renderQueue() {
  const $tb = $('#queue-list').empty();
  state.songs.forEach((s, idx) => {
    $('<tr>').attr('data-idx', idx).append(
      $('<td class="num">'), $('<td>').text(s.artist), $('<td>').text(s.title),
      $('<td class="small text-secondary">').text(s.batch_summary || ''),
      $('<td class="rating">'), $('<td class="heard">')).appendTo($tb);
    renderRow(idx);
  });
  $('#empty-queue').toggleClass('d-none', state.songs.length > 0);
}

function refreshQueue(initial) {
  return $.getJSON('api.php', {action: 'queue'}).done(songs => {
    connOk();
    log('queue loaded:', songs.length, 'songs', {initial});
    const currentId = cur() ? cur().video_id : null;
    state.songs = songs;
    renderQueue();
    if (initial || currentId === null) {
      const first = songs.findIndex(s => !s.last_played && !s.unplayable_error);
      if (songs.length) loadSong(first === -1 ? 0 : first, false, false);
    } else {
      state.i = Math.max(0, songs.findIndex(s => s.video_id === currentId));
      markCurrent(false); // don't jump the playlist while they may be scrolling through it
      $('#position').text(`Song ${state.i + 1} of ${songs.length}`);
    }
  }).fail(xhr => connError('loading the queue', xhr));
}

// ---------- player ----------

function showProgress() {
  const pct = Math.round(state.furthest);
  $('#progress-bar').css('width', pct + '%').text(pct + '%');
}

function cueOrLoad(id, autoplay) {
  if (!state.ready) { log('player not ready yet; queued', id); state.pendingVideo = {id, autoplay}; return; }
  log(autoplay ? 'load+play' : 'cue', id);
  if (autoplay) state.player.loadVideoById(id); else state.player.cueVideoById(id);
}

// byUser: reached via Back/Next/queue click (counts as interaction); false on first load and auto-advance.
function loadSong(idx, autoplay, byUser) {
  if (idx < 0 || idx >= state.songs.length) return;
  state.i = idx;
  const s = cur();
  state.interacted = !!byUser;
  state.played = false;
  state.furthest = s.furthest_pct || 0;
  state.duration = s.duration_s || null;
  log(`song ${idx + 1}/${state.songs.length}:`, s.video_id, s.artist, '-', s.title, {autoplay, byUser, furthest: state.furthest});
  $('#song-title').text(s.title);
  $('#song-artist').text(s.artist);
  $('#song-meta').text([s.bucket, s.source].filter(Boolean).join(' · '));
  $('#song-reason').text(s.reason || '');
  renderSongNotes();
  $('#chat-input').attr('placeholder', `What do you think of "${s.title}"? Or ask for anything…`);
  renderSongControls();
  $('#unavailable').addClass('d-none');
  $('#position').text(`Song ${idx + 1} of ${state.songs.length}`);
  markCurrent(true);
  showProgress();
  cueOrLoad(s.video_id, autoplay);
}

function renderSongNotes() {
  const s = cur();
  $('#song-notes').text(s && s.notes ? s.notes : 'none yet');
}

// Rating buttons and toggles for the current song. Also called after the agent replies, since it may have set them.
function renderSongControls() {
  const s = cur();
  if (!s) return;
  $('#rating-group button').removeClass('active').filter(`[data-rating="${s.rating}"]`).addClass('active');
  $('#off-brief').prop('checked', Number(s.off_brief) === 1);
  const ntm = s.new_to_me === null || s.new_to_me === undefined ? '' : String(s.new_to_me);
  $(`input[name="newtome"][value="${ntm}"]`).prop('checked', true);
}

// The current song, sent with each chat message so the agent knows what you're listening to.
function songContext() {
  const s = cur();
  if (!s) return null;
  return {video_id: s.video_id, artist: s.artist, title: s.title, furthest_pct: Math.round(state.furthest),
    rating: s.rating || null, off_brief: Number(s.off_brief) === 1, new_to_me: s.new_to_me === null || s.new_to_me === undefined ? null : Number(s.new_to_me) === 1};
}

// Save the current song on the way out, but only if it actually played.
function saveOnLeave(movingForward) {
  if (state.played) {
    const ended = state.ready && state.player.getPlayerState() === YT.PlayerState.ENDED;
    const skipped = movingForward && !ended && state.furthest < 100;
    log('leaving played song', cur().video_id, skipped ? '→ marked skipped' : '→ saved', {furthest: state.furthest, ended});
    save(skipped ? {skipped: 1} : {});
  } else {
    log('leaving unplayed song → not recorded', cur().video_id);
  }
}

function leaveAndGo(idx) {
  if (!cur()) return;
  saveOnLeave(idx > state.i);
  loadSong(idx, true, true);
}

function poll() {
  if (!state.ready || !cur()) return;
  if (state.player.getPlayerState() !== YT.PlayerState.PLAYING) return;
  const d = state.player.getDuration(), t = state.player.getCurrentTime();
  if (d > 0) {
    state.duration = d;
    const pct = Math.min(100, (t / d) * 100);
    if (pct > state.furthest) { state.furthest = pct; showProgress(); }
  }
  if (Date.now() - state.lastSaveAt >= SAVE_EVERY_MS) save();
}

function onStateChange(e) {
  log('player state:', YT_STATES[e.data] || e.data, cur() ? cur().video_id : '');
  if (e.data === YT.PlayerState.PLAYING) state.played = true;
  if (e.data === YT.PlayerState.PAUSED) save();
  if (e.data === YT.PlayerState.ENDED) {
    state.furthest = 100;
    showProgress();
    const advance = state.interacted && state.i < state.songs.length - 1;
    log('song ended →', advance ? 'auto-advancing' : 'stopping',
      {interacted: state.interacted, lastSong: state.i >= state.songs.length - 1});
    save({finished: 1}).always(() => { if (advance) loadSong(state.i + 1, true, false); });
  }
}

function onError(e) {
  const why = YT_ERRORS[e.data] || 'unknown';
  console.error('[nb] YouTube error', e.data, why, cur() ? cur().video_id : '');
  $('#unavailable').removeClass('d-none').text(`Unavailable (error ${e.data}: ${why}). Use Next to move on.`);
  save({error: String(e.data)});
}

window.onYouTubeIframeAPIReady = function () {
  state.player = new YT.Player('player', {
    playerVars: {rel: 0, playsinline: 1},
    events: {
      onReady: () => {
        state.ready = true;
        if (state.pendingVideo) { cueOrLoad(state.pendingVideo.id, state.pendingVideo.autoplay); state.pendingVideo = null; }
      },
      onStateChange,
      onError,
    },
  });
};

// ---------- chat ----------

function appendChat(m) {
  const label = {user: 'you', parent: 'agent', system: 'system'}[m.role] || m.role;
  // Chat-style bubbles: you on the right in blue, the agent on the left in grey, system notes in amber.
  const bubble = {
    user: 'ms-auto bg-primary-subtle border-primary-subtle',
    parent: 'me-auto bg-body-tertiary',
    system: 'mx-auto bg-warning-subtle border-warning-subtle',
  }[m.role] || 'bg-body-tertiary';
  // Agent replies are Markdown, rendered and sanitised; user and system messages stay plain text.
  const $body = m.role === 'parent' && window.marked && window.DOMPurify
    ? $('<div class="chat-md">').html(DOMPurify.sanitize(marked.parse(m.text)))
    : $('<div class="chat-text">').text(m.text);
  $('#chat-log').append($('<div class="chat-bubble border rounded-3 px-2 py-1 mb-2">').addClass(bubble).append(
    $('<div class="small text-body-secondary">').text(label), $body));
  const log = document.getElementById('chat-log');
  log.scrollTop = log.scrollHeight;
}

// Long-poll: the server holds each request until something changes, then we immediately ask again.
function pollChat() {
  $.getJSON('api.php', {action: 'chat', after: state.lastChatId, wait: 1, state: state.chatState}).done(res => {
    connOk();
    res.messages.forEach(m => { log(`chat [${m.role}]`, m.text); appendChat(m); state.lastChatId = Number(m.id); });
    const activity = res.jobs.running && res.jobs.running.activity;
    if (activity && activity !== (state.jobs && state.jobs.running && state.jobs.running.activity)) log('agent doing', activity);
    state.chatState = res.state;
    state.jobs = res.jobs;
    const busy = !!res.jobs.running || res.jobs.queued > 0;
    if (busy !== state.agentBusy) log('agent', busy ? 'busy' : 'idle', res.jobs);
    // Picks up new songs, and notes, ratings and toggles the agent recorded.
    if (state.agentBusy && !busy) refreshQueue(false).done(() => { renderSongNotes(); renderSongControls(); });
    state.agentBusy = busy;
    renderAgentStatus();
    setTimeout(pollChat, 0);
  }).fail(xhr => {
    connError('chat', xhr);
    setTimeout(pollChat, 2000);
  });
}

// "agent working… 0:47" in the navbar, and a temporary bubble at the bottom of the chat saying what the agent is
// doing right now ("Searching YouTube (12 songs)… 0:47"), so a long batch visibly isn't stuck.
function renderAgentStatus() {
  const j = state.jobs;
  const busy = !!j && (!!j.running || j.queued > 0);
  let timer = '';
  if (busy && j.running) {
    const s = Math.max(0, Math.floor((Date.now() - Date.parse(j.running.started_at)) / 1000));
    timer = ` ${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
  }
  $('#agent-status').toggleClass('d-none', !busy).text(j && j.running ? `agent working…${timer}` : 'waiting for the agent…');

  let $bubble = $('#agent-activity');
  if (!busy) { $bubble.remove(); return; }
  if (!$bubble.length) {
    $bubble = $('<div id="agent-activity" class="chat-bubble me-auto border rounded-3 px-2 py-1 mb-2 bg-body-tertiary text-body-secondary">')
      .append($('<div class="small">').text('agent'),
        $('<div>').append($('<span class="spinner-grow spinner-grow-sm me-2" aria-hidden="true">'), $('<span class="activity-text fst-italic">')));
  }
  if (!$bubble.is('#chat-log > :last-child')) {
    $('#chat-log').append($bubble); // keep it below the newest message
    const log = document.getElementById('chat-log');
    log.scrollTop = log.scrollHeight;
  }
  const doing = j.running ? (j.running.activity || 'Working') : 'Waiting for the agent';
  $bubble.find('.activity-text').text(`${doing}…${timer}`);
}

// ---------- wiring ----------

$(function () {
  log('page loaded; state is window.nb');
  $.ajax({url: 'api.php?action=start', method: 'POST', contentType: 'application/json', data: '{}'})
    .done(res => log(res.started ? 'first run: interview queued' : 'existing install'))
    .fail(xhr => connError('starting up', xhr));
  refreshQueue(true);
  pollChat();
  setInterval(renderAgentStatus, 1000);
  setInterval(poll, 1000);

  $('#rating-group').on('click', 'button', function () {
    if (!cur()) return;
    state.interacted = true;
    $('#rating-group button').removeClass('active');
    $(this).addClass('active');
    log('rated', $(this).data('rating'));
    save({rating: $(this).data('rating')});
  });

  $('#off-brief').on('change', function () {
    if (!cur()) return;
    state.interacted = true;
    log('off-brief', this.checked);
    save({off_brief: this.checked});
  });

  $('input[name="newtome"]').on('change', function () {
    if (!cur()) return;
    state.interacted = true;
    log('new to me', this.value === '' ? 'unset' : this.value === '1');
    save({new_to_me: this.value === '' ? null : this.value === '1'});
  });

  $('#btn-next').on('click', () => { log('clicked Next'); leaveAndGo(state.i + 1); });
  $('#btn-back').on('click', () => { log('clicked Back'); leaveAndGo(state.i - 1); });
  $('#queue-list').on('click', 'tr', function () { leaveAndGo(parseInt($(this).data('idx'), 10)); });

  $('#chat-form').on('submit', function (e) {
    e.preventDefault();
    const text = $('#chat-input').val().trim();
    if (!text) return;
    $('#chat-input').val('');
    if (cur()) state.interacted = true; // talking about the song counts as being here
    const song = songContext();
    log('sending chat:', text, song ? `(about ${song.artist} - ${song.title})` : '');
    $.ajax({url: 'api.php?action=send', method: 'POST', contentType: 'application/json', data: JSON.stringify({message: text, song})})
      .done(() => log('sent; the long-poll will pick up the reply'))
      .fail(xhr => appendChat({role: 'system', text: 'Could not send: ' + ((xhr.responseJSON && xhr.responseJSON.error) || xhr.status)}));
  });
  $('#chat-input').on('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('#chat-form').trigger('submit'); }
  });

  window.addEventListener('beforeunload', () => {
    if (!cur() || !state.played) return;
    navigator.sendBeacon('api.php?action=save', new Blob([JSON.stringify(payload())], {type: 'application/json'}));
  });
});
