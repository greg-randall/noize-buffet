---
description: Check requirements and show how to start noize-buffet
---

Check the requirements for noize-buffet and report each as OK or MISSING, with how to fix it:

1. `yt-dlp --version` (install: `python3 -m pip install -U yt-dlp`)
2. `php -v` (need 8.1 or newer) and `php -m` includes `pdo_sqlite`
3. `python3 --version`
4. `claude --version` (you are running inside it, so it's installed; remind the user they must be logged in)
5. A TypeSafe key in `.env` as `TYPESAFE_API=...` (or `TYPESAFE_API_KEY=...`). **If it's missing, warn loudly**: comment mining (coming in a later stage) will fall back to a simple keyword filter, which is noisier, reads fewer comments and uses more of the user's Claude allowance. A normal month of use costs well under $5 on TypeSafe; tell them to check typesafe.ai for current free credits. Never print the key.

Create the `data/` folder if it doesn't exist.

Then tell the user to open **two terminals** in this folder and run:

    PHP_CLI_SERVER_WORKERS=4 php -S localhost:8000 -t player

    php scripts/job_worker.php

and then open http://localhost:8000. The agent will start the interview in the chat panel.
