#!/usr/bin/env bash
# Stand-in for `claude` in tests: records its arguments and NB_JOB_ID, prints a JSON result.
# NUL-separated so arguments containing newlines (the prompt) survive intact.
printf '%s\0' "$@" > "$NB_FAKE_ARGS"
printf 'NB_JOB_ID=%s\0' "$NB_JOB_ID" >> "$NB_FAKE_ARGS"
if [ -n "$NB_FAKE_FAIL" ]; then
    echo '{"type":"result","is_error":true,"result":"boom","session_id":"sess-err"}'
    exit 1
fi
echo '{"type":"result","is_error":false,"result":"hello from fake","session_id":"sess-123"}'
