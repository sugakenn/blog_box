#!/bin/bash

# New Beacon For Linux (グローバルにPOST)

term_id="user"
post_url="https://example.com/api/ip"
type="set"
apikey=""

# POST
# -k: 証明書を検証しない
# -s: silent（進捗表示をしない）
# -f: HTTP 400以上をエラー扱いにする
curl -k -s -f \
    --data-urlencode "user=${term_id}" \
    --data-urlencode "key=${apikey}" \
    --data-urlencode "type=${type}" \
    "${post_url}"

# POST失敗
if [ $? -ne 0 ]; then
    exit 1
fi

exit 0