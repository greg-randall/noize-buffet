/* global $, YT */
'use strict';

const SAVE_EVERY_MS = 10000;
const NOTES_DEBOUNCE_MS = 1000;
const CHAT_POLL_MS = 2000;
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
  notesTimer: null,
  lastChatId: 0,
  agentBusy: false,
};

const cur = () => state.songs[state.i];
const notesDirty = () => !!cur() && $('#notes').val() !== (cur().notes || '');

function setStatus(text, cls) {
  $('#save-status').text(text).attr('class', 'badge ms-auto ' + cls);
}

function payload(extra) {
  const s = cur();
  return Object.assign({
    video_id: s.video_id, furthest_pct: state.furthest, duration_s: state.duration, notes: $('#notes').val(),
  }, extra || {});
}

function save(extra) {
  if (!cur()) return $.Deferred().resolve().promise();
  const idx = state.i;
  state.lastSaveAt = Date.now();
  return $.ajax({
    url: 'api.php?action=save', method: 'POST', contentType: 'application/json',
    data: JSON.stringify(payload(extra)), dataType: 'json',
  }).done(res => {
    Object.assign(state.songs[idx], res.row);
    renderRow(idx);
    setStatus('saved ' + new Date().toLocaleTimeString(), 'text-bg-success');
  }).fail(xhr => {
    const msg = (xhr.responseJSON && xhr.responseJSON.error) || xhr.status || 'network';
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
  $tr.toggleClass('table-active', idx === state.i);
}

function renderQueue() {
  const $tb = $('#queue-list').empty();
  state.songs.forEach((s, idx) => {
    $('<tr>').attr('data-idx', idx).append(
      $('<td>').text(idx + 1), $('<td>').text(s.artist), $('<td>').text(s.title),
      $('<td class="small text-secondary">').text(s.batch_summary || ''),
      $('<td class="rating">'), $('<td class="heard">')).appendTo($tb);
    renderRow(idx);
  });
  $('#empty-queue').toggleClass('d-none', state.songs.length > 0);
}

function refreshQueue(initial) {
  return $.getJSON('api.php', {action: 'queue'}).done(songs => {
    const currentId = cur() ? cur().video_id : null;
    state.songs = songs;
    renderQueue();
    if (initial || currentId === null) {
      const first = songs.findIndex(s => !s.last_played && !s.unplayable_error);
      if (songs.length) loadSong(first === -1 ? 0 : first, false, false);
    } else {
      state.i = Math.max(0, songs.findIndex(s => s.video_id === currentId));
      renderRow(state.i);
      $('#position').text(`Song ${state.i + 1} of ${songs.length}`);
    }
  });
}

// ---------- player ----------

function showProgress() {
  const pct = Math.round(state.furthest);
  $('#progress-bar').css('width', pct + '%').text(pct + '%');
}

function cueOrLoad(id, autoplay) {
  if (!state.ready) { state.pendingVideo = {id, autoplay}; return; }
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
  $('#song-title').text(s.title);
  $('#song-artist').text(s.artist);
  $('#song-meta').text([s.bucket, s.source].filter(Boolean).join(' · '));
  $('#song-reason').text(s.reason || '');
  $('#notes').val(s.notes || '');
  $('#rating-group button').removeClass('active').filter(`[data-rating="${s.rating}"]`).addClass('active');
  $('#off-brief').prop('checked', Number(s.off_brief) === 1);
  const ntm = s.new_to_me === null || s.new_to_me === undefined ? '' : String(s.new_to_me);
  $(`input[name="newtome"][value="${ntm}"]`).prop('checked', true);
  $('#unavailable').addClass('d-none');
  $('#position').text(`Song ${idx + 1} of ${state.songs.length}`);
  $('#queue-list tr').removeClass('table-active');
  $(`#queue-list tr[data-idx="${idx}"]`).addClass('table-active');
  showProgress();
  cueOrLoad(s.video_id, autoplay);
}

// Save the current song on the way out, but only if it was played (or has an unsaved note).
function saveOnLeave(movingForward) {
  clearTimeout(state.notesTimer);
  if (state.played) {
    const ended = state.ready && state.player.getPlayerState() === YT.PlayerState.ENDED;
    save((movingForward && !ended && state.furthest < 100) ? {skipped: 1} : {});
  } else if (notesDirty()) {
    save();
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
  if (e.data === YT.PlayerState.PLAYING) state.played = true;
  if (e.data === YT.PlayerState.PAUSED) save();
  if (e.data === YT.PlayerState.ENDED) {
    state.furthest = 100;
    showProgress();
    const advance = state.interacted && state.i < state.songs.length - 1;
    save({finished: 1}).always(() => { if (advance) loadSong(state.i + 1, true, false); });
  }
}

function onError(e) {
  const why = YT_ERRORS[e.data] || 'unknown';
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
  const cls = m.role === 'system' ? 'text-warning' : (m.role === 'user' ? 'text-info' : 'text-secondary');
  $('#chat-log').append($('<div class="mb-2">').append(
    $('<div class="small">').addClass(cls).text(label), $('<div class="chat-text">').text(m.text)));
  const log = document.getElementById('chat-log');
  log.scrollTop = log.scrollHeight;
}

function pollChat() {
  $.getJSON('api.php', {action: 'chat', after: state.lastChatId}).done(res => {
    res.messages.forEach(m => { appendChat(m); state.lastChatId = Number(m.id); });
    const busy = !!res.jobs.running || res.jobs.queued > 0;
    $('#agent-status').toggleClass('d-none', !busy).text(res.jobs.running ? 'agent working…' : 'waiting for the agent…');
    if (state.agentBusy && !busy) refreshQueue(false);
    state.agentBusy = busy;
  });
}

// ---------- wiring ----------

$(function () {
  $.ajax({url: 'api.php?action=start', method: 'POST', contentType: 'application/json', data: '{}'});
  refreshQueue(true);
  pollChat();
  setInterval(pollChat, CHAT_POLL_MS);
  setInterval(poll, 1000);

  $('#rating-group').on('click', 'button', function () {
    if (!cur()) return;
    state.interacted = true;
    $('#rating-group button').removeClass('active');
    $(this).addClass('active');
    save({rating: $(this).data('rating')});
  });

  $('#off-brief').on('change', function () {
    if (!cur()) return;
    state.interacted = true;
    save({off_brief: this.checked});
  });

  $('input[name="newtome"]').on('change', function () {
    if (!cur()) return;
    state.interacted = true;
    save({new_to_me: this.value === '' ? null : this.value === '1'});
  });

  $('#notes').on('input', function () {
    if (!cur()) return;
    state.interacted = true;
    clearTimeout(state.notesTimer);
    state.notesTimer = setTimeout(() => save(), NOTES_DEBOUNCE_MS);
  });

  $('#btn-next').on('click', () => leaveAndGo(state.i + 1));
  $('#btn-back').on('click', () => leaveAndGo(state.i - 1));
  $('#queue-list').on('click', 'tr', function () { leaveAndGo(parseInt($(this).data('idx'), 10)); });

  $('#chat-form').on('submit', function (e) {
    e.preventDefault();
    const text = $('#chat-input').val().trim();
    if (!text) return;
    $('#chat-input').val('');
    $.ajax({url: 'api.php?action=send', method: 'POST', contentType: 'application/json', data: JSON.stringify({message: text})})
      .done(() => pollChat())
      .fail(xhr => appendChat({role: 'system', text: 'Could not send: ' + ((xhr.responseJSON && xhr.responseJSON.error) || xhr.status)}));
  });
  $('#chat-input').on('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('#chat-form').trigger('submit'); }
  });

  window.addEventListener('beforeunload', () => {
    if (!cur() || !(state.played || notesDirty())) return;
    navigator.sendBeacon('api.php?action=save', new Blob([JSON.stringify(payload())], {type: 'application/json'}));
  });
});
