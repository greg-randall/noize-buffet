<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>noize-buffet</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    /* App layout on wide screens: the page never scrolls (the top bar stays put); the playlist, mining table,
       debug log and chat each scroll inside their own box. Narrow screens stack everything and scroll normally. */
    @media (min-width: 992px) {
      html, body { height: 100%; }
      body { display: flex; flex-direction: column; overflow: hidden; }
      #app { flex: 1 1 auto; min-height: 0; }
      #app > .row { height: 100%; }
      #left-col, #chat-col { height: 100%; display: flex; flex-direction: column; min-height: 0; }
      #tabs-box { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }
      #tabs-box .tab-content { flex: 1 1 auto; min-height: 0; }
      #tabs-box .tab-pane { height: 100%; overflow-y: auto; }
      #chat-col #chat-log { flex: 1 1 auto; height: auto; min-height: 0; }
    }
    @media (max-width: 991.98px) {
      #tabs-box .tab-pane { max-height: 60vh; overflow-y: auto; }
      #chat-log { height: 55vh; }
    }
    #player-wrap { aspect-ratio: 16 / 9; }
    #player-wrap iframe, #player { width: 100%; height: 100%; }
    #queue-list tr { cursor: pointer; }
    #queue-list td.num { width: 2.5rem; }
    /* The playlist scrolls on its own, so the current song can sit at the top (see markCurrent in app.js). */
    #queue-scroll { position: relative; }
    #tabs-box thead th { position: sticky; top: 0; z-index: 1; background: var(--bs-body-bg); }
    /* Read-only: shown as a quote, not a box, so it doesn't look editable. */
    #song-notes { border-left: 3px solid var(--bs-secondary-border-subtle); padding-left: .6rem; font-style: italic; }
    #chat-log { overflow-y: auto; display: flex; flex-direction: column; }
    .chat-text { white-space: pre-wrap; }
    .chat-bubble { max-width: 90%; width: fit-content; }
    .chat-md { white-space: normal; }
    .chat-md p, .chat-md ul, .chat-md ol { margin-bottom: .4rem; }
    #debug-log { font-family: var(--bs-font-monospace); font-size: .8rem; white-space: pre-wrap; word-break: break-word; }
    #debug-log .detail { color: var(--bs-secondary-color); }
    #debug-log .err { color: var(--bs-danger-text-emphasis); }
    #debug-log:not(.verbose) .detail { display: none; }
    #mining-status { cursor: pointer; }
  </style>
</head>
<body>
<nav class="navbar bg-body-tertiary mb-3 flex-shrink-0">
  <div class="container-fluid d-flex gap-3">
    <span class="navbar-brand">noize-buffet</span>
    <span id="position" class="text-secondary"></span>
    <span id="agent-status" class="badge text-bg-info d-none">agent working…</span>
    <span id="mining-status" class="badge text-bg-secondary d-none" role="button"></span>
    <span id="conn-status" class="badge text-bg-danger d-none"></span>
    <span id="save-status" class="badge text-bg-secondary ms-auto">idle</span>
  </div>
</nav>

<main id="app" class="container-fluid pb-3">
  <div class="row g-4">
    <div class="col-lg-8" id="left-col">
      <div class="row g-3 flex-shrink-0">
        <div class="col-md-7">
          <div id="empty-queue" class="alert alert-info d-none">No songs yet. Answer the agent in the chat, or ask it for songs.</div>
          <div id="player-wrap" class="mb-2"><div id="player"></div></div>
          <div id="unavailable" class="alert alert-danger d-none"></div>
        </div>

        <div class="col-md-5">
          <h2 class="h5 mb-0" id="song-title"></h2>
          <div class="text-secondary" id="song-artist"></div>
          <div class="small text-secondary mb-2" id="song-meta"></div>
          <p class="small" id="song-reason"></p>

          <div id="rating-group" class="btn-group mb-2 flex-wrap" role="group" aria-label="Rating">
            <button class="btn btn-outline-success" data-rating="top">top</button>
            <button class="btn btn-outline-success" data-rating="yes">yes</button>
            <button class="btn btn-outline-info" data-rating="good">good</button>
            <button class="btn btn-outline-secondary" data-rating="ok">ok</button>
            <button class="btn btn-outline-warning" data-rating="meh">meh</button>
            <button class="btn btn-outline-danger" data-rating="no">no</button>
          </div>

          <!-- Set by the agent from the chat ("never heard this", "not what I'm after"); shown, not clickable. -->
          <div id="song-tags" class="mb-2 d-flex gap-1 flex-wrap"></div>

          <div class="small text-body-secondary mb-1">Your notes on this song (the agent records them from the chat):</div>
          <div id="song-notes" class="chat-text small text-body-secondary">none yet</div>
          <div class="d-flex gap-2 mt-3">
            <button id="btn-back" class="btn btn-outline-light">⏮ Back</button>
            <button id="btn-next" class="btn btn-outline-light">Next ⏭</button>
          </div>
        </div>
      </div>

      <div id="tabs-box" class="mt-3">
        <ul class="nav nav-tabs flex-shrink-0" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-playlist" data-bs-toggle="tab" data-bs-target="#queue-scroll" type="button" role="tab">Playlist</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-mining" data-bs-toggle="tab" data-bs-target="#mining-pane" type="button" role="tab">Comment mining</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-debug" data-bs-toggle="tab" data-bs-target="#debug-pane" type="button" role="tab">Debug</button>
          </li>
        </ul>
        <div class="tab-content">
          <div id="queue-scroll" class="tab-pane show active" role="tabpanel">
            <table class="table table-sm table-hover mb-0">
              <thead><tr><th>#</th><th>Artist</th><th>Song</th><th>Why</th><th>Rating</th><th>Heard</th></tr></thead>
              <tbody id="queue-list"></tbody>
            </table>
          </div>

          <div id="mining-pane" class="tab-pane" role="tabpanel">
            <div class="small text-body-secondary my-2">Songs you rate top or yes, and songs you name, get their YouTube comments read for other artists people mention.
              <span id="mining-summary" class="text-body"></span></div>
            <table class="table table-sm small mb-0">
              <thead><tr><th>Song</th><th>Status</th><th>Filter</th><th>Comments</th><th>Flagged</th><th>Covered</th><th>Mentions</th><th>Notes</th><th>Problems</th></tr></thead>
              <tbody id="mining-list"></tbody>
            </table>
          </div>

          <div id="debug-pane" class="tab-pane" role="tabpanel">
            <div class="d-flex gap-3 align-items-center my-2 small">
              <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" id="debug-verbose">
                <label class="form-check-label" for="debug-verbose">Show detail (every save, player state and load)</label>
              </div>
              <button id="debug-clear" class="btn btn-sm btn-outline-secondary ms-auto">Clear</button>
            </div>
            <div id="debug-log"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4" id="chat-col">
      <h2 class="h6 mb-2">Talk to the agent</h2>
      <div id="chat-log" class="border rounded p-2 mb-2 bg-body-secondary"></div>
      <form id="chat-form" class="d-flex gap-2 flex-shrink-0">
        <textarea id="chat-input" class="form-control" rows="2" placeholder="What do you think of this song? Or ask for anything…"></textarea>
        <button class="btn btn-primary" type="submit">Send</button>
      </form>
    </div>
  </div>
</main>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
<script src="app.js"></script>
<script src="https://www.youtube.com/iframe_api"></script>
</body>
</html>
