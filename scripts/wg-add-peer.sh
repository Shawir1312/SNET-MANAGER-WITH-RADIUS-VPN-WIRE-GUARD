#!/bin/bash
# S.NET RADIUS & VPN — Tambah peer WireGuard baru secara live + persist ke wg0.conf
# Usage: wg-add-peer.sh <public_key> <allowed_ip/32>

set -e

WG_IFACE="${WG_IFACE:-wg0}"
WG_CONF="/etc/wireguard/${WG_IFACE}.conf"

PUBKEY="$1"
ALLOWED_IP="$2"

if [ -z "$PUBKEY" ] || [ -z "$ALLOWED_IP" ]; then
    echo "Usage: $0 <public_key> <allowed_ip/32>"
    exit 1
fi

# Tambah peer secara live (langsung aktif tanpa restart service)
wg set "$WG_IFACE" peer "$PUBKEY" allowed-ips "$ALLOWED_IP" 2>/dev/null || true

# Tulis ke wg0.conf jika file tersedia
if [ -f "$WG_CONF" ]; then
    if ! grep -Fq "$PUBKEY" "$WG_CONF"; then
        {
            echo ""
            echo "[Peer]"
            echo "PublicKey = $PUBKEY"
            echo "AllowedIPs = $ALLOWED_IP"
        } >> "$WG_CONF"
    fi
fi

echo "OK: peer $PUBKEY ditambahkan dengan IP $ALLOWED_IP"
