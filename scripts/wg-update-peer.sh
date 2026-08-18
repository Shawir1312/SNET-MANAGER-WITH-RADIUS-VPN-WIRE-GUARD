#!/bin/bash
# S.NET RADIUS & VPN — Update AllowedIPs peer WireGuard live + update wg0.conf
# Usage: wg-update-peer.sh <public_key> <allowed_ip/32,...>

set -e

WG_IFACE="${WG_IFACE:-wg0}"
WG_CONF="/etc/wireguard/${WG_IFACE}.conf"

PUBKEY="$1"
ALLOWED_IP="$2"

if [ -z "$PUBKEY" ] || [ -z "$ALLOWED_IP" ]; then
    echo "Usage: $0 <public_key> <allowed_ips>"
    exit 1
fi

# Update peer secara live
wg set "$WG_IFACE" peer "$PUBKEY" allowed-ips "$ALLOWED_IP" 2>/dev/null || true

# Update blok [Peer] di wg0.conf
if [ -f "$WG_CONF" ]; then
    if grep -Fq "$PUBKEY" "$WG_CONF"; then
        awk -v pubkey="$PUBKEY" -v newip="$ALLOWED_IP" '
            BEGIN { in_target = 0 }
            /^\[Peer\]/ { in_target = 0 }
            $0 ~ "PublicKey[[:space:]]*=[[:space:]]*" pubkey { in_target = 1 }
            in_target && /^AllowedIPs[[:space:]]*=/ {
                print "AllowedIPs = " newip
                in_target = 0
                next
            }
            { print }
        ' "$WG_CONF" > "${WG_CONF}.tmp" && mv "${WG_CONF}.tmp" "$WG_CONF"
    else
        {
            echo ""
            echo "[Peer]"
            echo "PublicKey = $PUBKEY"
            echo "AllowedIPs = $ALLOWED_IP"
        } >> "$WG_CONF"
    fi
fi

echo "OK: peer $PUBKEY diperbarui dengan AllowedIPs $ALLOWED_IP"
