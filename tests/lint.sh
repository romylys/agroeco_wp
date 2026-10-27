#!/usr/bin/env bash
# Перевірка синтаксису всіх PHP-файлів плагіна і тестів (php -l).
set -uo pipefail
cd "$(dirname "$0")/.."

status=0
while IFS= read -r -d '' file; do
	if ! out="$(php -l "$file" 2>&1)"; then
		echo "$out"
		status=1
	fi
done < <(find agroeco-multilingual tests -name '*.php' -not -path '*/.work/*' -print0)

[ "$status" -eq 0 ] && echo "php -l: OK"
exit "$status"
