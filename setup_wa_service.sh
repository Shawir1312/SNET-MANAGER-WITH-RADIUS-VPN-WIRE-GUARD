#!/bin/bash
# ==============================================================================
# S.NET RADIUS & PPPOE MANAGER — WHATSAPP WEB SCAN QR ENGINE AUTO-INSTALLER
# ==============================================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}==============================================================${NC}"
echo -e "${GREEN}  S.NET WHATSAPP WEB SCAN QR ENGINE — AUTO INSTALLER (VPS)    ${NC}"
echo -e "${BLUE}==============================================================${NC}"

# Check root
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}[ERROR] Skrip ini harus dijalankan sebagai root (sudo bash setup_wa_service.sh)${NC}"
    exit 1
fi

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
WA_DIR="$SCRIPT_DIR/wa-service"

echo -e "\n${YELLOW}[1/4] Memeriksa Instalasi Node.js & npm...${NC}"
if ! command -v node &> /dev/null || ! command -v npm &> /dev/null; then
    echo -e "${BLUE}Node.js belum ditemukan. Memasang Node.js v20 LTS...${NC}"
    apt-get update -y
    apt-get install -y curl gnupg build-essential
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt-get install -y nodejs
else
    NODE_VER=$(node -v)
    echo -e "${GREEN}✓ Node.js sudah terpasang: $NODE_VER${NC}"
fi

echo -e "\n${YELLOW}[2/4] Menginstal Dependencies WhatsApp Baileys di $WA_DIR...${NC}"
cd "$WA_DIR"
npm install --production

echo -e "\n${YELLOW}[3/4] Menyiapkan Background Service (systemd: snet-wa.service)...${NC}"
SERVICE_FILE="/etc/systemd/system/snet-wa.service"

cat <<EOF > "$SERVICE_FILE"
[Unit]
Description=S.NET WhatsApp Web Microservice (Baileys)
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=$WA_DIR
ExecStart=$(which node) server.js
Restart=always
RestartSec=5
Environment=PORT=3000
Environment=NODE_ENV=production

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable snet-wa
systemctl restart snet-wa

echo -e "\n${YELLOW}[4/4] Memeriksa Status Layanan...${NC}"
sleep 2

if systemctl is-active --quiet snet-wa; then
    echo -e "${GREEN}✓ Service snet-wa BERHASIL DIJALANKAN (Active: running)${NC}"
else
    echo -e "${RED}⚠️ Service snet-wa gagal start. Cek log dengan: journalctl -u snet-wa -e${NC}"
fi

echo -e "\n${BLUE}==============================================================${NC}"
echo -e "${GREEN}  🎉 INSTALASI SELESAI DENGAN SUKSES!                         ${NC}"
echo -e "${BLUE}==============================================================${NC}"
echo -e "Engine WhatsApp Web sekarang aktif di latar belakang (Port 3000)."
echo -e "Silakan buka menu web admin: ${YELLOW}Broadband ➔ WhatsApp Notifikasi${NC}"
echo -e "Lalu klik tombol ${GREEN}[Scan QR Code]${NC} untuk menghubungkan WhatsApp Anda!\n"
