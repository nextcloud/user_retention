#!/bin/bash

export CYPRESS_baseUrl=${CYPRESS_baseUrl:-https://nextcloud.local/index.php}
export APP_SOURCE=$PWD/..
export LANG="en_EN.UTF-8"

if ! npm exec wait-on >/dev/null; then
	npm install --no-save wait-on
fi

if npm exec wait-on -- -i 500 -t 1000 "$CYPRESS_baseUrl" 2>/dev/null; then
	echo Server is up at "$CYPRESS_baseUrl"
fi

(cd .. && npm exec cypress -- "$@")
