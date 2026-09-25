#!/usr/bin/env bash
# Stand-in for `claude` in tests: records its arguments and NB_JOB_ID, prints a JSON result.
printf '%s\n' "$@" > "$NB_FAKE_ARGS"
echo "NB_JOB_ID=$NB_JOB_ID" >> "$NB_FAKE_ARGS"
if [ -n "$NB_FAKE_FAIL" ]; then
    echo '{"type":"result","is_error":true,"result":"boom","session_id":"sess-err"}'
    exit 1
fi
echo '{"type":"result","is_error":false,"result":"hello from fake","session_id":"sess-123"}'
