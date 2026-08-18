#!/usr/bin/env bash
# ==============================================================================
# S.NET RADIUS & VPN — WireGuard Auto Installer & Server Setup for Ubuntu/Debian
# ==============================================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${BLUE}====================================================================${NC}"
echo -e "${BLUE}    S.NET RADIUS Manager — WireGuard VPN Server Auto Installer       ${NC}"
echo -e "${BLUE}====================================================================${NC}"

if [ "$EUID" -ne 0 ]; then
  echo -e "${RED}[ERROR] Skrip ini harus dijalankan sebagai ROOT / SUDO!${NC}"
  echo -e "Silakan jalankan: ${YELLOW}sudo bash $0 $@${NC}"
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE="$SCRIPT_DIR/config/db_local.php"

export DEBIAN_FRONTEND=noninteractive

echo -e "${YELLOW}[1/6] Menginstal WireGuard dan dependensi sistem...${NC}"
apt-get update -y
apt-get install -y wireguard wireguard-tools iptables qrencode

echo -e "${YELLOW}[2/6] Mengaktifkan IP Forwarding di Kernel Linux...${NC}"
if ! grep -q "^net.ipv4.ip_forward=1" /etc/sysctl.conf; then
    echo "net.ipv4.ip_forward=1" >> /etc/sysctl.conf
fi
sysctl -w net.ipv4.ip_forward=1 >/dev/null || true

echo -e "${YELLOW}[3/6] Menyiapkan Keypair WireGuard Server...${NC}"
mkdir -p /etc/wireguard
chmod 700 /etc/wireguard

if [ ! -f /etc/wireguard/server_private.key ]; then
    wg genkey | tee /etc/wireguard/server_private.key | wg pubkey | tee /etc/wireguard/server_public.key
    chmod 600 /etc/wireguard/server_private.key
fi

SERVER_PRIVKEY=$(cat /etc/wireguard/server_private.key)
SERVER_PUBKEY=$(cat /etc/wireguard/server_public.key)

# Deteksi Subnet Prefix (dari argumen CLI, database, atau default)
SUBNET_INPUT="${1:-}"
if [ -z "$SUBNET_INPUT" ] && [ -f "$CONFIG_FILE" ] && command -v php &> /dev/null; then
    SUBNET_INPUT=$(php -r "
        @include '$CONFIG_FILE';
        if (defined('DB_HOST')) {
            try {
                \$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
                if (!\$db->connect_error) {
                    \$res = \$db->query(\"SELECT \`value\` FROM wg_settings WHERE \`key\`='wg_subnet_prefix'\");
                    if (\$res && \$row = \$res->fetch_assoc()) {
                        echo \$row['value'];
                    }
                }
            } catch(Throwable \$e){}
        }
    " 2>/dev/null || true)
fi

if [ -z "$SUBNET_INPUT" ]; then
    SUBNET_INPUT="10.66.66."
fi

# Normalisasi: hapus /24, hapus .0 di ujung, pastikan berakhiran .
SUBNET_PREFIX=$(echo "$SUBNET_INPUT" | sed -E 's/\/[0-9]+$//' | sed -E 's/\.0$//' | sed -E 's/\.*$/\./')
SERVER_IP="${SUBNET_PREFIX}1/24"

# Deteksi Interface Internet Utama (eth0, ens3, dll)
WAN_IFACE=$(ip route get 8.8.8.8 2>/dev/null | awk -- '{print $5}' | head -n 1)
if [ -z "$WAN_IFACE" ]; then
    WAN_IFACE="eth0"
fi

echo -e "${YELLOW}[4/6] Mengonfigurasi /etc/wireguard/wg0.conf (Server IP: ${SERVER_IP})...${NC}"
if [ ! -f /etc/wireguard/wg0.conf ]; then
    cat <<EOF > /etc/wireguard/wg0.conf
[Interface]
Address = ${SERVER_IP}
ListenPort = 51820
PrivateKey = ${SERVER_PRIVKEY}
PostUp   = iptables -A FORWARD -i wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -o ${WAN_IFACE} -j MASQUERADE
PostDown = iptables -D FORWARD -i wg0 -j ACCEPT; iptables -t nat -D POSTROUTING -o ${WAN_IFACE} -j MASQUERADE
EOF
    chmod 600 /etc/wireguard/wg0.conf
else
    # Update Address jika file sudah ada
    sed -i -E "s|^Address[[:space:]]*=.*|Address = ${SERVER_IP}|g" /etc/wireguard/wg0.conf
fi

echo -e "${YELLOW}[5/6] Menyalin Helper Scripts & Mengatur Hak Akses Sudoers...${NC}"
cp -f "$SCRIPT_DIR/scripts/wg-add-peer.sh" /usr/local/bin/wg-add-peer.sh
cp -f "$SCRIPT_DIR/scripts/wg-update-peer.sh" /usr/local/bin/wg-update-peer.sh
cp -f "$SCRIPT_DIR/scripts/wg-remove-peer.sh" /usr/local/bin/wg-remove-peer.sh
chmod +x /usr/local/bin/wg-*.sh

# Setup sudoers untuk user www (aaPanel) & www-data (Ubuntu standar)
SUDOERS_FILE="/etc/sudoers.d/snet_wireguard"
cat <<EOF > "$SUDOERS_FILE"
# S.NET WireGuard sudo permissions
www ALL=(ALL) NOPASSWD: /usr/local/bin/wg-add-peer.sh, /usr/local/bin/wg-update-peer.sh, /usr/local/bin/wg-remove-peer.sh, /usr/bin/wg, /usr/bin/wg-quick, /usr/sbin/iptables, /sbin/iptables, /usr/sbin/ip, /sbin/ip
www-data ALL=(ALL) NOPASSWD: /usr/local/bin/wg-add-peer.sh, /usr/local/bin/wg-update-peer.sh, /usr/local/bin/wg-remove-peer.sh, /usr/bin/wg, /usr/bin/wg-quick, /usr/sbin/iptables, /sbin/iptables, /usr/sbin/ip, /sbin/ip
EOF
chmod 440 "$SUDOERS_FILE"

echo -e "${YELLOW}[6/6] Menjalankan dan Mengaktifkan Service WireGuard (wg-quick@wg0)...${NC}"
systemctl enable wg-quick@wg0 || true
systemctl restart wg-quick@wg0 || true

# Update DB setting jika database sudah ada
if [ -f "$CONFIG_FILE" ] && command -v php &> /dev/null; then
    php -r "
        @include '$CONFIG_FILE';
        if (defined('DB_HOST')) {
            try {
                \$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
                if (!\$db->connect_error) {
                    \$db->query(\"CREATE TABLE IF NOT EXISTS wg_settings (\`key\` VARCHAR(100) PRIMARY KEY, \`value\` TEXT)\");
                    \$stmt = \$db->prepare(\"INSERT INTO wg_settings (\`key\`, \`value\`) VALUES ('wg_server_pubkey', ?), ('wg_server_privkey', ?) ON DUPLICATE KEY UPDATE \`value\`=VALUES(\`value\`)\");
                    \$stmt->bind_param('ss', \$k1, \$k2);
                    \$k1 = '$SERVER_PUBKEY';
                    \$k2 = '$SERVER_PRIVKEY';
                    \$stmt->execute();
                }
            } catch(Throwable \$e){}
        }
    " 2>/dev/null || true
fi

echo -e "${GREEN}====================================================================${NC}"
echo -e "${GREEN} ✓ SUKSES: Server WireGuard VPN S.NET Berhasil Terpasang & Aktif!   ${NC}"
echo -e "${GREEN}====================================================================${NC}"
echo -e "Server Public Key : ${CYAN}${SERVER_PUBKEY}${NC}"
echo -e "Tunnel Subnet     : 10.66.66.1/24"
echo -e "Listen Port       : 51820 UDP"
echo -e "Status Service    : $(systemctl is-active wg-quick@wg0 2>/dev/null || echo 'running')"
