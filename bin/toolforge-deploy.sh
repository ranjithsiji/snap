#!/bin/bash
#
# Deploys Snap on Toolforge. Run from a bastion as the tool user:
#
#   become snap
#   cd ~/snap && ./bin/toolforge-deploy.sh
#
# Assumes the layout:
#   $HOME                       /data/project/snap
#   $HOME/replica.my.cnf        credentials, outside the repo
#   $HOME/snap                  this repository
#   $HOME/public_html    ->     snap/public   (symlink, the docroot)
#
# Safe to re-run: every step either is idempotent or is a no-op when
# already correct.

set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO"

# $HOME is where lighttpd looks for its config and where Toolforge puts
# the credentials — the repo sits one level below it.
TOOL_HOME="$(cd "$REPO/.." && pwd)"

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

say "Deploying from $REPO (tool home: $TOOL_HOME)"

if [ ! -r "$TOOL_HOME/replica.my.cnf" ]; then
    echo "WARNING: $TOOL_HOME/replica.my.cnf is missing or unreadable." >&2
    echo "         Both databases are reached with those credentials, so" >&2
    echo "         the tool will not start without it." >&2
fi

say "Installing PHP dependencies"
# Toolforge has no build step for PHP, so dependencies are installed in
# place. --no-dev keeps PHPUnit and friends out of a public docroot.
composer install --no-dev --optimize-autoloader --no-interaction

say "Building the frontend"
# Vite writes straight into public/, which is what public_html points at.
if command -v pnpm >/dev/null 2>&1; then
    (cd frontend && pnpm install --frozen-lockfile && pnpm run build)
else
    echo "pnpm not found. Build locally and commit public/, or install it:" >&2
    echo "  npm install -g pnpm" >&2
    exit 1
fi

say "Running database migrations"
php vendor/bin/doctrine-migrations migrations:migrate --no-interaction

say "Linking the document root"
# lighttpd serves $HOME/public_html; point it at the built SPA. Replaced
# only when it is not already the right symlink, so a real directory is
# never clobbered without being seen.
if [ -L "$TOOL_HOME/public_html" ]; then
    ln -sfn "$REPO/public" "$TOOL_HOME/public_html"
elif [ -e "$TOOL_HOME/public_html" ]; then
    echo "ERROR: $TOOL_HOME/public_html exists and is not a symlink." >&2
    echo "       Move it aside, then re-run:" >&2
    echo "         mv $TOOL_HOME/public_html $TOOL_HOME/public_html.bak" >&2
    exit 1
else
    ln -s "$REPO/public" "$TOOL_HOME/public_html"
fi

say "Installing the lighttpd configuration"
# The web server reads $HOME/.lighttpd.conf, which is outside the repo, so
# it is symlinked back to the copy that is version-controlled.
ln -sfn "$REPO/.lighttpd.conf" "$TOOL_HOME/.lighttpd.conf"

say "Restarting the web service"
# php8.4: the ORM uses native lazy objects on 8.4 and falls back to
# generated proxies below it, so this picks the faster path.
toolforge webservice php8.4 restart

say "Done"
echo "  https://snap.toolforge.org/"
echo "  Check which Commons source is live at /api/commons/status"
echo "  Errors are logged to $TOOL_HOME/error.log"
