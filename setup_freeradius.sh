#!/usr/bin/env bash
# ==============================================================================
# S.NET RADIUS Manager — FreeRADIUS Auto Installer & Configurator for Ubuntu/Debian
# ==============================================================================

set -e

# Color definitions
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${BLUE}====================================================================${NC}"
echo -e "${BLUE}    S.NET RADIUS Manager — FreeRADIUS Auto Installer & Configurator   ${NC}"
echo -e "${BLUE}====================================================================${NC}"

# Check root privilege
if [ "$EUID" -ne 0 ]; then
  echo -e "${RED}[ERROR] Skrip ini harus dijalankan sebagai ROOT / SUDO!${NC}"
  echo -e "Silakan jalankan: ${YELLOW}sudo bash $0 $@${NC}"
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE="$SCRIPT_DIR/config/db_local.php"

# Default or argument-provided DB parameters
DB_HOST="${1:-}"
DB_USER="${2:-}"
DB_PASS="${3:-}"
DB_NAME="${4:-}"
DB_PORT="${5:-3306}"

# If parameters not passed via CLI, try parsing from config/db_local.php using PHP CLI
if [ -z "$DB_HOST" ] && [ -f "$CONFIG_FILE" ]; then
    echo -e "${BLUE}[INFO] Membaca konfigurasi database dari ${CONFIG_FILE}...${NC}"
    if command -v php &> /dev/null; then
        DB_PARAMS=$(php -r "
            @include '$CONFIG_FILE';
            if (defined('DB_HOST')) {
                echo DB_HOST . '|' . DB_USER . '|' . DB_PASS . '|' . DB_NAME . '|' . DB_PORT;
            }
        ")
        if [ -n "$DB_PARAMS" ]; then
            IFS='|' read -r DB_HOST DB_USER DB_PASS DB_NAME DB_PORT <<< "$DB_PARAMS"
        fi
    fi
fi

# Fallback regex if php CLI was not used
if [ -z "$DB_HOST" ] && [ -f "$CONFIG_FILE" ]; then
    DB_HOST=$(grep "define('DB_HOST'" "$CONFIG_FILE" | sed -E "s/.*'DB_HOST', *'([^']*)'.*/\1/" || echo "127.0.0.1")
    DB_USER=$(grep "define('DB_USER'" "$CONFIG_FILE" | sed -E "s/.*'DB_USER', *'([^']*)'.*/\1/" || echo "radius")
    DB_PASS=$(grep "define('DB_PASS'" "$CONFIG_FILE" | sed -E "s/.*'DB_PASS', *'([^']*)'.*/\1/" || echo "")
    DB_NAME=$(grep "define('DB_NAME'" "$CONFIG_FILE" | sed -E "s/.*'DB_NAME', *'([^']*)'.*/\1/" || echo "radius")
    DB_PORT=$(grep "define('DB_PORT'" "$CONFIG_FILE" | sed -E "s/.*'DB_PORT', *([0-9]+).*/\1/" || echo "3306")
fi

# Fallback defaults if still empty
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_USER="${DB_USER:-radius}"
DB_NAME="${DB_NAME:-radius}"
DB_PORT="${DB_PORT:-3306}"

if [ "$DB_HOST" = "localhost" ]; then
    DB_HOST="127.0.0.1"
fi

echo -e "${YELLOW}[1/5] Memeriksa paket FreeRADIUS di sistem Ubuntu...${NC}"
export DEBIAN_FRONTEND=noninteractive

if ! command -v freeradius &> /dev/null || [ ! -f /usr/sbin/freeradius ]; then
    echo -e "${BLUE}[INFO] Menginstal paket freeradius, freeradius-mysql, freeradius-utils...${NC}"
    apt-get update -y
    apt-get install -y freeradius freeradius-mysql freeradius-utils
else
    echo -e "${GREEN}[OK] Paket freeradius sudah terinstal.${NC}"
    # Pastikan freeradius-mysql terinstal
    if ! dpkg -l | grep -q freeradius-mysql; then
        echo -e "${BLUE}[INFO] Menginstal paket freeradius-mysql...${NC}"
        apt-get install -y freeradius-mysql freeradius-utils
    fi
fi

echo -e "${YELLOW}[2/5] Mengonfigurasi modul SQL FreeRADIUS...${NC}"
SQL_CONF="/etc/freeradius/3.0/mods-available/sql"

if [ -f "$SQL_CONF" ]; then
    # Backup original config once
    if [ ! -f "${SQL_CONF}.orig" ]; then
        cp "$SQL_CONF" "${SQL_CONF}.orig"
    fi

    # Write clean SQL module configuration
    cat <<EOF > "$SQL_CONF"
# Generated automatically by S.NET RADIUS Manager
sql {
    driver = "rlm_sql_mysql"
    dialect = "mysql"
    server = "${DB_HOST}"
    port = ${DB_PORT}
    login = "${DB_USER}"
    password = "${DB_PASS}"
    radius_db = "${DB_NAME}"

    acct_table1 = "radacct"
    acct_table2 = "radacct"
    postauth_table = "radpostauth"
    authcheck_table = "radcheck"
    authreply_table = "radreply"
    groupcheck_table = "radgroupcheck"
    groupreply_table = "radgroupreply"
    usergroup_table = "radusergroup"
    nas_table = "nas"

    sql_user_name = "%{%{Stripped-User-Name}:-%{%{User-Name}:-DEFAULT}}"
    default_user_profile = ""

    simul_count_query = "SELECT COUNT(*) FROM \${acct_table1} WHERE username = '%{SQL-User-Name}' AND acctstoptime IS NULL"
    simul_verify_query = "SELECT radacctid, acctsessionid, username, nasipaddress, nasportid, framedipaddress, callingstationid, framedprotocol FROM \${acct_table1} WHERE username = '%{SQL-User-Name}' AND acctstoptime IS NULL"

    read_clients = yes
    client_table = "nas"
    group_attribute = "SQL-Group"

    safe_characters = "@abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-_: /"

    \$INCLUDE \${modconfdir}/\${.:name}/main/\${dialect}/queries.conf
}
EOF

    # Set proper permissions
    chgrp -h freerad "$SQL_CONF" 2>/dev/null || true
    chmod 640 "$SQL_CONF" 2>/dev/null || true
    echo -e "${GREEN}[OK] File ${SQL_CONF} berhasil dikonfigurasi.${NC}"
else
    echo -e "${RED}[ERROR] File ${SQL_CONF} tidak ditemukan!${NC}"
fi

echo -e "${YELLOW}[3/5] Mengaktifkan modul SQL di mods-enabled...${NC}"
mkdir -p /etc/freeradius/3.0/mods-enabled
ln -sf /etc/freeradius/3.0/mods-available/sql /etc/freeradius/3.0/mods-enabled/sql

echo -e "${YELLOW}[4/5] Mengaktifkan SQL di default site FreeRADIUS...${NC}"
DEFAULT_SITE="/etc/freeradius/3.0/sites-available/default"
if [ -f "$DEFAULT_SITE" ]; then
    # Matikan sqlippool jika sempat aktif
    sed -i 's/^[[:space:]]*sqlippool/#    sqlippool/g' "$DEFAULT_SITE" || true
    # Aktifkan hanya kata 'sql' (word boundary)
    sed -i -E 's/^[[:space:]]*#[[:space:]]*sql([[:space:]]*$)/    sql\1/g' "$DEFAULT_SITE" || true
    sed -i -E 's/^[[:space:]]*-sql([[:space:]]*$)/    sql\1/g' "$DEFAULT_SITE" || true
fi

INNER_SITE="/etc/freeradius/3.0/sites-available/inner-tunnel"
if [ -f "$INNER_SITE" ]; then
    sed -i 's/^[[:space:]]*sqlippool/#    sqlippool/g' "$INNER_SITE" || true
    sed -i -E 's/^[[:space:]]*#[[:space:]]*sql([[:space:]]*$)/    sql\1/g' "$INNER_SITE" || true
    sed -i -E 's/^[[:space:]]*-sql([[:space:]]*$)/    sql\1/g' "$INNER_SITE" || true
fi

echo -e "${YELLOW}[5/5] Membuka Port Firewall & Merestart Service FreeRADIUS...${NC}"
# Buka port FreeRADIUS di Firewall UFW & iptables
if command -v ufw &>/dev/null; then
    ufw allow 1812:1813/udp 2>/dev/null || true
    ufw allow 3799/udp 2>/dev/null || true
fi
iptables -I INPUT -p udp --dport 1812 -j ACCEPT 2>/dev/null || true
iptables -I INPUT -p udp --dport 1813 -j ACCEPT 2>/dev/null || true
iptables -I INPUT -p udp --dport 3799 -j ACCEPT 2>/dev/null || true

systemctl enable freeradius || true
systemctl restart freeradius || true

sleep 2

# Verify service status
if systemctl is-active --quiet freeradius; then
    echo -e "${GREEN}====================================================================${NC}"
    echo -e "${GREEN} ✓ SUKSES: FreeRADIUS Berhasil Terinstal & Terhubung ke Database!   ${NC}"
    echo -e "${GREEN}====================================================================${NC}"
    echo -e "Status FreeRADIUS : ${GREEN}ACTIVE (RUNNING)${NC}"
    echo -e "Database Server   : ${DB_HOST}:${DB_PORT}"
    echo -e "Database Name     : ${DB_NAME}"
    echo -e "Database User     : ${DB_USER}"
else
    echo -e "${RED}====================================================================${NC}"
    echo -e "${RED} [PERINGATAN] Service FreeRADIUS belum dapat berjalan normal!        ${NC}"
    echo -e "${RED} Jalankan debug: 'freeradius -X' untuk melihat pesan error detail.   ${NC}"
    echo -e "${RED}====================================================================${NC}"
fi
