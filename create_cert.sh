#!/bin/bash
#
# ============================================================
# Certificate Creation Script
# ============================================================
#
# OpenSSLを使用してサーバーやクライアント証明書を作成します。
#
# 以下の処理を順番に実行します。
#   1. RSA 3072bit 秘密鍵の作成
#   2. CSR（証明書署名要求）の作成
#   3. openssl.cnf の [v3_sig] を使用してCA署名
#   4. 秘密鍵と証明書をPKCS#12（PFX）形式にまとめる
#
# Common Name（CN）と Subject Alternative Name（SAN）には
# 同じDNS名を設定します。
#
# Usage:
#   ./create-server-cert.sh <openssl.cnf> <CommonName> [CA_CERT]
#
# Arguments:
#   openssl.cnf   CA設定ファイル
#   CommonName    Common Name（SANにも同じ値を設定）FQDN形式
#   CA_CERT       PFXに含めるCA証明書（省略可）
#
# Example:
#   ./create-cert.sh /etc/ssl/ca/openssl.cnf server.example.com
#
#   CA証明書もPFXに含める場合:
#   ./create-cert.sh /etc/ssl/ca/openssl.cnf server.example.com ca-chain.crt
#
# Output:
#   <CommonName>.key   秘密鍵
#   <CommonName>.crt   証明書
#   <CommonName>.pfx   PKCS#12ファイル
#
# 出力先:
#   openssl.cnf と同じディレクトリ
#
# Requirements:
#   openssl.cnf に [v3_sig] セクションが定義されていること
#
# ============================================================

#!/bin/bash
set -e

# ----------------------------------------
# 引数チェック
# ----------------------------------------
if [ "$#" -lt 2 ] || [ "$#" -gt 3 ]; then
    echo "Usage: $0 <openssl.cnf> <CommonName> [CA_CERT]"
    exit 1
fi

CONFIG="$1"
COMMON_NAME="$2"
CA_CERT="${3:-}"

# ----------------------------------------
# openssl.cnf の存在確認
# ----------------------------------------
if [ ! -f "$CONFIG" ]; then
    echo "Error: config file not found: $CONFIG"
    exit 1
fi

# CONFIGを絶対パスにする
CONFIG="$(realpath "$CONFIG")"

# openssl.cnf と同じディレクトリ
BASE_DIR="$(dirname "$CONFIG")"

# ----------------------------------------
# Root CA が指定された場合のチェック
# ----------------------------------------
if [ -n "$CA_CERT" ]; then
    if [ ! -f "$CA_CERT" ]; then
        echo "Error: Root CA certificate not found: $CA_CERT"
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
# CN と SAN は同じ値
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
# openssl.cnf の [v3_sig] を使用
# ----------------------------------------
echo "=== Sign certificate ==="

openssl ca \
    -config "$CONFIG" \
    -extensions v3_sig \
    -in "$CSR" \
    -out "$CRT"

# ----------------------------------------
# 4. PFX作成
# CA_CERT指定時はPFXへ含める
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
echo "Private key : $KEY"
echo "Certificate : $CRT"
echo "PFX         : $PFX"

if [ -n "$CA_CERT" ]; then
    echo "CA_CERT     : $CA_CERT"
fi
