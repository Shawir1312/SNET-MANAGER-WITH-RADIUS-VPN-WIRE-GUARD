#!/bin/bash
# S.NET RADIUS & VPN — Hapus peer WireGuard secara live + hapus dari wg0.conf
# Usage: wg-remove-peer.sh <public_key>

set -e

WG_IFACE="${WG_IFACE:-wg0}"
WG_CONF="/etc/wireguard/${WG_IFACE}.conf"

PUBKEY="$1"

if [ -z "$PUBKEY" ]; then
    echo "Usage: $0 <public_key>"
    exit 1
fi

# Hapus peer secara live
wg set "$WG_IFACE" peer "$PUBKEY" remove 2>/dev/null || true

# Hapus blok [Peer] dari wg0.conf
if [ -f "$WG_CONF" ]; then
    awk -v pubkey="$PUBKEY" '
        BEGIN { skip = 0 }
        /^\[Peer\]/ { block = $0; buffer = $0 "\n"; getline line
                      buffer = buffer line "\n"
                      if (line ~ pubkey) { skip = 1 } else { printf "%s", buffer; skip = 0 }
                      next }
        { print }
    ' "$WG_CONF" > "${WG_CONF}.tmp" && mv "${WG_CONF}.tmp" "$WG_CONF"
fi

echo "OK: peer $PUBKEY dihapus"
