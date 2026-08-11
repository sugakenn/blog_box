#!/bin/bash
#
# ============================================================
# Certificate Creation Script
# ============================================================
#
# OpenSSLを使用してサーバー証明書またはクライアント証明書を作成します。
#
# Usage:
#   ./create-cert.sh <server|client> <openssl.cnf> <CommonName> [CA_CERT]
#
# Arguments:
#   server|client  証明書の種類
#                  server -> openssl.cnf の [v3_server] を使用
#                  client -> openssl.cnf の [v3_client] を使用
#   openssl.cnf    CA設定ファイル
#   CommonName     Common Name（FQDN形式）
#   CA_CERT        PFXに含めるCA証明書またはCAチェーン（省略可）
#
# Example:
#   ./create-cert.sh server /etc/ssl/ca/openssl.cnf server.example.com
#
#   ./create-cert.sh client /etc/ssl/ca/openssl.cnf client.example.com ca-chain.crt
#
# Output:
#   <CommonName>.key
#   <CommonName>.crt
#   <CommonName>.pfx
#
# ============================================================

set -e

# ----------------------------------------
# 引数チェック
# ----------------------------------------
if [ "$#" -lt 3 ] || [ "$#" -gt 4 ]; then
    echo "Usage: $0 <server|client> <openssl.cnf> <CommonName> [CA_CERT]"
    exit 1
fi

CERT_TYPE="$1"
CONFIG="$2"
COMMON_NAME="$3"
CA_CERT="${4:-}"

# ----------------------------------------
# 証明書タイプのチェック
# ----------------------------------------
case "$CERT_TYPE" in
    server|client)
        ;;
    *)
        echo "Error: certificate type must be 'server' or 'client'."
        exit 1
        ;;
esac

# 使用するv3セクション
V3_SECTION="v3_${CERT_TYPE}"

# ----------------------------------------
# openssl.cnf の存在確認
# ----------------------------------------
if [ ! -f "$CONFIG" ]; then
    echo "Error: config file not found: $CONFIG"
    exit 1
fi

CONFIG="$(realpath "$CONFIG")"

BASE_DIR="$(dirname "$CONFIG")"

# ----------------------------------------
# CA証明書が指定された場合のチェック
# ----------------------------------------
if [ -n "$CA_CERT" ]; then
    if [ ! -f "$CA_CERT" ]; then
        echo "Error: CA certificate not found: $CA_CERT"
        exit 1
    fi

    CA_CERT="$(realpath "$CA_CERT")"
fi

# ----------------------------------------
# 出力ファイル
# ----------------------------------------
KEY="${BASE_DIR}/${COMMON_NAME}.key"
CSR="${BASE_DIR}/${COMMON_NAME}.csr"
CRT="${BASE_DIR}/${COMMON_NAME}.crt"
PFX="${BASE_DIR}/${COMMON_NAME}.pfx"

# ----------------------------------------
# 1. 秘密鍵作成
# ----------------------------------------
echo "=== Create private key ==="

openssl genpkey \
    -algorithm RSA \
    -pkeyopt rsa_keygen_bits:3072 \
    -out "$KEY"

chmod 600 "$KEY"

# ----------------------------------------
# 2. CSR作成
# ----------------------------------------
echo "=== Create CSR ==="

openssl req \
    -new \
    -config "$CONFIG" \
    -key "$KEY" \
    -out "$CSR" \
    -subj "/CN=${COMMON_NAME}" \
    -addext "subjectAltName=DNS:${COMMON_NAME}"

# ----------------------------------------
# 3. CA署名
# server -> [v3_server]
# client -> [v3_client]
# ----------------------------------------
echo "=== Sign certificate (${V3_SECTION}) ==="

openssl ca \
    -config "$CONFIG" \
    -extensions "$V3_SECTION" \
    -in "$CSR" \
    -out "$CRT"

# ----------------------------------------
# 4. PFX作成
# ----------------------------------------
echo "=== Create PFX ==="

if [ -n "$CA_CERT" ]; then

    openssl pkcs12 \
        -export \
        -inkey "$KEY" \
        -in "$CRT" \
        -certfile "$CA_CERT" \
        -out "$PFX"

else

    openssl pkcs12 \
        -export \
        -inkey "$KEY" \
        -in "$CRT" \
        -out "$PFX"

fi

# ----------------------------------------
# CSR削除
# ----------------------------------------
rm -f "$CSR"

echo
echo "Completed."
echo "Type        : $CERT_TYPE"
echo "V3 section  : $V3_SECTION"
echo "Private key : $KEY"
echo "Certificate : $CRT"
echo "PFX         : $PFX"

if [ -n "$CA_CERT" ]; then
    echo "CA_CERT     : $CA_CERT"
fi
