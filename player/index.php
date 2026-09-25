<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>noize-buffet</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    #player-wrap { aspect-ratio: 16 / 9; }
    #player-wrap iframe, #player { width: 100%; height: 100%; }
    #queue-list tr { cursor: pointer; }
    #chat-log { height: 55vh; overflow-y: auto; }
    .chat-text { white-space: pre-wrap; }
    .chat-bubble { max-width: 90%; width: fit-content; }
    #chat-log { display: flex; flex-direction: column; }
    .chat-md { white-space: normal; }
    .chat-md p, .chat-md ul, .chat-md ol { margin-bottom: .4rem; }
  </style>
</head>
<body>
<nav class="navbar bg-body-tertiary mb-3">
  <div class="container-fluid d-flex gap-3">
    <span class="navbar-brand">noize-buffet</span>
    <span id="position" class="text-secondary"></span>
    <span id="agent-status" class="badge text-bg-info d-none">agent working…</span>
    <span id="conn-status" class="badge text-bg-danger d-none"></span>
    <span id="save-status" class="badge text-bg-secondary ms-auto">idle</span>
  </div>
</nav>

<main class="container-fluid">
  <div class="row g-4">
    <div class="col-xl-5">
      <div id="empty-queue" class="alert alert-info d-none">No songs yet. Answer the agent in the chat, or ask it for songs.</div>
      <div id="player-wrap" class="mb-2"><div id="player"></div></div>
      <div id="unavailable" class="alert alert-danger d-none"></div>
      <div class="progress mb-2" role="progressbar" aria-label="Furthest point reached">
        <div id="progress-bar" class="progress-bar" style="width: 0%">0%</div>
      </div>
      <div class="d-flex gap-2">
        <button id="btn-back" class="btn btn-outline-light">⏮ Back</button>
        <button id="btn-next" class="btn btn-outline-light">Next ⏭</button>
      </div>
    </div>

    <div class="col-xl-3">
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

      <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" id="off-brief">
        <label class="form-check-label" for="off-brief">Good, but off-brief</label>
      </div>
      <div class="btn-group btn-group-sm mb-2" role="group" aria-label="New to me">
        <input type="radio" class="btn-check" name="newtome" id="ntm-new" value="1">
        <label class="btn btn-outline-light" for="ntm-new">New to me</label>
        <input type="radio" class="btn-check" name="newtome" id="ntm-knew" value="0">
        <label class="btn btn-outline-light" for="ntm-knew">Knew it</label>
        <input type="radio" class="btn-check" name="newtome" id="ntm-unset" value="">
        <label class="btn btn-outline-secondary" for="ntm-unset">–</label>
      </div>

      <div class="small text-body-secondary">Your notes on this song (the agent records them from the chat):</div>
      <div id="song-notes" class="chat-text small border rounded p-2 text-body-secondary">none yet</div>
    </div>

    <div class="col-xl-4">
      <h2 class="h6 mb-2">Talk to the agent</h2>
      <div id="chat-log" class="border rounded p-2 mb-2 bg-body-secondary"></div>
      <form id="chat-form" class="d-flex gap-2">
        <textarea id="chat-input" class="form-control" rows="2" placeholder="What do you think of this song? Or ask for anything…"></textarea>
        <button class="btn btn-primary" type="submit">Send</button>
      </form>
    </div>
  </div>

  <table class="table table-sm table-hover mt-4">
    <thead><tr><th>#</th><th>Artist</th><th>Song</th><th>Batch</th><th>Rating</th><th>Heard</th></tr></thead>
    <tbody id="queue-list"></tbody>
  </table>
</main>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
<script src="app.js"></script>
<script src="https://www.youtube.com/iframe_api"></script>
</body>
</html>
