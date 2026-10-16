#!/bin/bash

# Updates the translations: extracts the strings into languages/orchestra-projects.pot, merges them into
# every languages/*.po file and compiles the .mo files that WordPress loads.
# Requires GNU gettext (macOS: brew install gettext).

set -euo pipefail

cd "$(dirname "$0")/.."

DOMAIN="orchestra-projects"
POT="languages/$DOMAIN.pot"

find src "$DOMAIN.php" -name '*.php' | sort | xargs xgettext \
    --language=PHP \
    --from-code=UTF-8 \
    --keyword=__ --keyword=_e --keyword=esc_html__ --keyword=esc_html_e --keyword=esc_attr__ --keyword=esc_attr_e \
    --keyword=_n:1,2 --keyword=_x:1,2c --keyword=esc_html_x:1,2c --keyword=esc_attr_x:1,2c \
    --add-comments=translators \
    --package-name="$DOMAIN" \
    --sort-by-file \
    --output="$POT"

for po in languages/*.po; do
    msgmerge --quiet --update --backup=none --no-fuzzy-matching --sort-by-file "$po" "$POT"
    msgfmt --check --output-file="${po%.po}.mo" "$po"
    echo "$po: $(msgfmt --statistics --output-file=/dev/null "$po" 2>&1)"
done
