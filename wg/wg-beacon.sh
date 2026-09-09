#!/bin/bash

# WireGuard用の定時更新用 .shファイル
#
# systemd timer や cron などから定期実行する
# rootでの実行を想定
#
# このファイルは一般ユーザーから書き込みできないようにすること
#
# 例:
#   /usr/local/sbin/wg-beacon.sh
#
# 所有者・権限:
#   chown root:root /usr/local/sbin/wg-beacon.sh
#   chmod 700 /usr/local/sbin/wg-beacon.sh


# 設定 -------------------------------------------------

WG="/usr/bin/wg"

# 中央サーバー側PeerのPublicKey
PUBKEY="中央サーバーのPUBLIC_KEY"

# WireGuardインターフェース名
NET="wg0"

# 中央サーバー
ENDPOINT="endpoint.jp:51820"

# ログファイル
LOG_FILE="/var/log/wg-update.log"

# 中央サーバーのWireGuardトンネルIP
PING_IP="172.30.255.1"

# -------------------------------------------------------


echo "$(date '+%Y-%m-%d %H:%M:%S') INFO WireGuard endpoint update start" \
    >> "$LOG_FILE"


# Endpointを更新
"$WG" set "$NET" peer "$PUBKEY" endpoint "$ENDPOINT"

if [ $? -ne 0 ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') ERROR WireGuard endpoint update fail" \
        >> "$LOG_FILE"

    exit 1
fi


# WireGuard通信を発生させる
ping -c 1 -W 3 "$PING_IP" >> "$LOG_FILE" 2>&1

exit 0
