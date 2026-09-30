<?php
// ?v=<modified time> on app.css and app.js: a normal refresh picks up new versions after a git pull.
?>
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
  <link rel="stylesheet" href="app.css?v=<?= filemtime(__DIR__ . '/app.css') ?>">
</head>
<body>
<main id="app" class="container-fluid py-3">
  <div class="row g-4">
    <div class="col-lg-8 col-xl-9" id="left-col">
      <div class="row g-3 flex-shrink-0">
        <div class="col-md-8">
          <div id="empty-queue" class="alert alert-info d-none">No songs yet. Answer the agent in the chat, or ask it for songs.</div>
          <div id="player-wrap" class="mb-2"><div id="player"></div><div id="brand" aria-hidden="true">noize&#717;buffet</div>
            <div id="blocked-overlay" class="d-none">
              <div class="blocked-why"></div>
              <a id="blocked-play" class="btn btn-light btn-lg fw-semibold px-4 py-3" href="#" target="_blank" rel="noopener">Play on YouTube</a>
              <div id="blocked-note" class="small text-body-secondary"></div>
            </div></div>
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
            <button class="btn rate" data-rating="top">top</button>
            <button class="btn rate" data-rating="yes">yes</button>
            <button class="btn rate" data-rating="good">good</button>
            <button class="btn rate" data-rating="ok">ok</button>
            <button class="btn rate" data-rating="meh">meh</button>
            <button class="btn rate" data-rating="no">no</button>
          </div>
          <!-- Outlined when off, filled when on. Click them, or the agent sets them from the chat ("never heard this",
               "not what I'm after"). -->
          <div id="song-tags" class="mb-2 d-flex gap-1 flex-wrap">
            <button id="tag-new" class="tag-toggle" type="button" title="Click: new to you, then you knew it, then not set">new to you</button>
            <button id="tag-offbrief" class="tag-toggle" type="button" title="Good, but not what you're looking for here">off-brief</button>
          </div>

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
      <div id="status-bar" class="d-flex justify-content-end align-items-center gap-1 mb-2 flex-shrink-0">
        <select id="station" class="form-select form-select-sm me-auto" aria-label="Station"></select>
        <span id="conn-status" class="badge text-bg-danger d-none"></span>
        <span id="agent-status" class="badge d-none">agent working…</span>
        <span id="mining-status" class="badge text-bg-secondary d-none" role="button"></span>
        <span id="save-status" class="badge text-bg-secondary">idle</span>
      </div>
      <div id="chat-box" class="mb-2">
        <div id="chat-log" class="border rounded p-2 bg-body-secondary"></div>
      </div>
      <form id="chat-form" class="d-flex gap-2 flex-shrink-0">
        <textarea id="chat-input" class="form-control" rows="4" placeholder="What do you think of this song? Or ask for anything…"></textarea>
        <button id="chat-send" class="btn" type="submit">Send</button>
      </form>
    </div>
  </div>
</main>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
<script src="app.js?v=<?= filemtime(__DIR__ . '/app.js') ?>"></script>
<script src="https://www.youtube.com/iframe_api"></script>
</body>
</html>
