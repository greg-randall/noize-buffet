<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>noize-buffet</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geo:ital@0;1&display=swap">
  <style>
    /* App layout on wide screens: the page never scrolls; the playlist, mining table,
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
      #chat-box { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }
      #chat-box #chat-log { flex: 1 1 auto; height: auto; min-height: 0; }
      #tabs-box { min-height: 13rem; }
      /* The video is as wide as its column allows, but shrinks on a short screen so the tabs keep 13rem. */
      #player-wrap { width: min(100%, calc((100vh - 16rem) * 16 / 9)); }
      /* The song panel is as tall as the video and scrolls inside, so a long reason never pushes the tabs down. */
      #song-col { position: relative; }
      #song-panel { position: absolute; top: 0; bottom: 0; left: calc(var(--bs-gutter-x) * .5);
        right: calc(var(--bs-gutter-x) * .5); display: flex; flex-direction: column; }
      /* Back/Next stay pinned at the bottom; only the song's details above them scroll. */
      #song-info { flex: 1 1 auto; min-height: 0; overflow-y: auto; }
    }
    @media (max-width: 991.98px) {
      #tabs-box .tab-pane { max-height: 60vh; overflow-y: auto; }
      #chat-log { height: 55vh; }
    }
    #player-wrap { aspect-ratio: 16 / 9; position: relative; }
    /* The name straddles the video's top-left corner (hanging a little off its edges), on top of everything; clicks
       go through to the video. */
    #brand { position: absolute; top: -12px; left: -6px; z-index: 1000000; pointer-events: none; user-select: none;
      font-family: 'Geo', sans-serif; font-size: 4rem; line-height: .75; text-transform: uppercase; letter-spacing: .02em;
      padding: .05em 0 0 .05em; color: #fff; opacity: .9;
      text-shadow:
        /* tight: a heavy dark edge all round, diagonals included */
        -3px 0 4px #000, 3px 0 4px #000, 0 -3px 4px #000, 0 3px 4px #000,
        -3px -3px 4px #000, 3px -3px 4px #000, -3px 3px 4px #000, 3px 3px 4px #000,
        /* wide: a dark, blurry halo well out from the letters */
        -16px 0 28px #000, 16px 0 28px #000, 0 -16px 28px #000, 0 16px 28px #000,
        -12px -12px 28px #000, 12px -12px 28px #000, -12px 12px 28px #000, 12px 12px 28px #000; }
    #status-overlay { position: absolute; top: .45rem; right: 1.4rem; left: .6rem; z-index: 2; display: flex; gap: .3rem;
      flex-wrap: wrap; justify-content: flex-end; opacity: .45; transition: opacity .15s; pointer-events: none; }
    #status-overlay > * { pointer-events: auto; white-space: nowrap; }
    #status-overlay:hover { opacity: 1; }
    #conn-status { position: absolute; bottom: .6rem; left: .6rem; right: 1.4rem; z-index: 2; white-space: normal;
      text-align: left; }
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
    .panel-label { font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .06em;
      color: var(--bs-secondary-color); margin-bottom: .25rem; }
    #song-bucket { font-size: .65rem; letter-spacing: .06em; }
    #song-source a { color: inherit; }
  </style>
</head>
<body>
<main id="app" class="container-fluid py-3">
  <div class="row g-4">
    <div class="col-lg-8 col-xl-9" id="left-col">
      <div class="row g-3 flex-shrink-0">
        <div class="col-md-8">
          <div id="empty-queue" class="alert alert-info d-none">No songs yet. Answer the agent in the chat, or ask it for songs.</div>
          <div id="player-wrap" class="mb-2"><div id="player"></div><div id="brand" aria-hidden="true">noize_buffet</div></div>
          <div id="unavailable" class="alert alert-danger d-none"></div>
        </div>

        <div class="col-md-4" id="song-col"><div id="song-panel"><div id="song-info">
          <div class="d-flex align-items-center gap-2 mb-1">
            <span id="song-bucket" class="badge rounded-pill text-bg-secondary text-uppercase d-none"></span>
            <span id="position" class="small text-body-secondary ms-auto"></span>
          </div>
          <h2 class="fs-5 fw-semibold mb-0 lh-sm" id="song-title"></h2>
          <div class="text-body-secondary mb-3" id="song-artist"></div>

          <div id="rating-group" class="btn-group btn-group-sm w-100 mb-2" role="group" aria-label="Rating">
            <button class="btn btn-outline-success" data-rating="top">top</button>
            <button class="btn btn-outline-success" data-rating="yes">yes</button>
            <button class="btn btn-outline-info" data-rating="good">good</button>
            <button class="btn btn-outline-secondary" data-rating="ok">ok</button>
            <button class="btn btn-outline-warning" data-rating="meh">meh</button>
            <button class="btn btn-outline-danger" data-rating="no">no</button>
          </div>
          <!-- Set by the agent from the chat ("never heard this", "not what I'm after"); shown, not clickable. -->
          <div id="song-tags" class="mb-2 d-flex gap-1 flex-wrap"></div>

          <div class="panel-label mt-3">Why it's here</div>
          <p class="small mb-1" id="song-reason"></p>
          <div class="small text-body-secondary text-truncate" id="song-source"></div>

          <div class="panel-label mt-3" title="The agent records them from what you say in the chat">Your notes</div>
          <div id="song-notes" class="chat-text small text-body-secondary">none yet</div>
          </div>
          <div class="d-flex gap-2 pt-3 flex-shrink-0">
            <button id="btn-back" class="btn btn-outline-light flex-fill py-2">Back</button>
            <button id="btn-next" class="btn btn-light flex-fill py-2 fw-semibold">Next</button>
          </div>
        </div></div>
      </div>

      <div id="tabs-box" class="mt-2">
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
              <thead><tr><th>Updated</th><th>Song</th><th>Status</th><th>Filter</th><th>Comments</th><th>Flagged</th><th>Covered</th><th>Mentions</th><th>Notes</th><th>Problems</th></tr></thead>
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

    <div class="col-lg-4 col-xl-3" id="chat-col">
      <div id="chat-box" class="position-relative mb-2">
        <div id="chat-log" class="border rounded p-2 bg-body-secondary"></div>
        <!-- Status floats faintly over the chat's top-right corner; hover to read it. Connection errors stay solid. -->
        <div id="status-overlay">
          <span id="agent-status" class="badge text-bg-info d-none">agent working…</span>
          <span id="mining-status" class="badge text-bg-secondary d-none" role="button"></span>
          <span id="save-status" class="badge text-bg-secondary">idle</span>
        </div>
        <span id="conn-status" class="badge text-bg-danger d-none"></span>
      </div>
      <form id="chat-form" class="d-flex gap-2 flex-shrink-0">
        <textarea id="chat-input" class="form-control" rows="4" placeholder="What do you think of this song? Or ask for anything…"></textarea>
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
