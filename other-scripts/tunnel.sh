#!/bin/bash

HOST="markangelogonzalespinoyride@54.251.171.207"
PORT=2222
FORWARD="5433:postgres-riderapp:5432"
LOG_INTERVAL=5
RECONNECT_DELAY=5

SOCKET="/tmp/pinoyride_ssh_tunnel.sock"

cleanup() {
    echo
    echo "Closing SSH tunnel..."

    if [ -S "$SOCKET" ]; then
        ssh -S "$SOCKET" -O exit "$HOST" -p "$PORT" 2>/dev/null
        rm -f "$SOCKET"
    fi

    exit 0
}

trap cleanup SIGINT SIGTERM

while true; do

    # Remove old socket if one exists
    rm -f "$SOCKET"

    # ---------------------------------------------------------
    # Establish SSH tunnel.
    #
    # IMPORTANT:
    # - Password prompt happens here.
    # - No heartbeat is running yet.
    # - ssh -f backgrounds itself AFTER authentication.
    # ---------------------------------------------------------
    ssh -M -S "$SOCKET" \
        -L "$FORWARD" \
        "$HOST" \
        -p "$PORT" \
        -N -f \
        -o ServerAliveInterval=60 \
        -o ServerAliveCountMax=3 \
        -o ExitOnForwardFailure=yes

    SSH_RESULT=$?

    if [ $SSH_RESULT -ne 0 ]; then
        echo
        echo "SSH connection failed."
        echo "Retrying in $RECONNECT_DELAY seconds..."
        sleep "$RECONNECT_DELAY"
        continue
    fi

    # ---------------------------------------------------------
    # Authentication succeeded and tunnel is now running.
    # Only NOW do we display messages.
    # ---------------------------------------------------------

    echo
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] SSH tunnel connected"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] localhost:$FORWARD"
    echo

    # ---------------------------------------------------------
    # Monitor the SSH connection
    # ---------------------------------------------------------

    while true; do

        sleep "$LOG_INTERVAL"

        # Check whether SSH master is still alive
        ssh -S "$SOCKET" -O check "$HOST" -p "$PORT" \
            >/dev/null 2>&1

        if [ $? -ne 0 ]; then
            echo
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] connection lost"
            break
        fi

        echo "[$(date '+%Y-%m-%d %H:%M:%S')] still connected"

    done

    # Clean up socket
    rm -f "$SOCKET"

    echo "[$(date '+%Y-%m-%d %H:%M:%S')] reconnecting in $RECONNECT_DELAY seconds..."

    sleep "$RECONNECT_DELAY"

done