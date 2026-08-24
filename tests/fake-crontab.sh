#!/bin/sh

STATE="${FRIENDLINKS_FAKE_CRONTAB_STATE:-/tmp/friendlinks-fake-crontab}"

if [ "${FRIENDLINKS_FAKE_CRONTAB_REQUIRE_C_LOCALE:-0}" = "1" ] \
    && [ "${LC_ALL:-}" != "C" ]; then
    echo "aucune crontab pour friendlinks-test" >&2
    exit 1
fi

case "${1:-}" in
    -l)
        if [ -f "$STATE" ]; then
            cat "$STATE"
            exit 0
        fi
        echo "no crontab for friendlinks-test" >&2
        exit 1
        ;;
    -)
        if [ "${FRIENDLINKS_FAKE_CRONTAB_FAIL_WRITE:-0}" = "1" ]; then
            echo "simulated crontab write failure" >&2
            exit 2
        fi
        cat > "$STATE"
        exit 0
        ;;
    *)
        echo "unsupported fake crontab arguments" >&2
        exit 2
        ;;
esac
