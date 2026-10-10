#!/bin/sh
set -eu

test -x /usr/bin/nohup
test -x /usr/bin/sleep
/usr/bin/nohup /usr/bin/sleep 300 </dev/null >/dev/null 2>&1 &
pid=$!
printf '%s\n' "$pid"
