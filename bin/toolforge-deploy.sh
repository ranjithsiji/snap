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

# Re-executes under bash when started with `sh script.sh`. Toolforge's
# /bin/sh is dash, which has no BASH_SOURCE — the path below would expand
# to nothing, the script would resolve its own location as /, and it would
# run against the wrong directory rather than stopping.
if [ -z "${BASH_VERSION:-}" ]; then
    exec bash "$0" "$@"
fi

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
#
# Without vendor/ the site serves nothing but a 500: bootstrap.php
# require_once's the autoloader before anything can report a friendlier
# error. So this is checked rather than assumed.
if command -v composer >/dev/null 2>&1; then
    composer install --no-dev --optimize-autoloader --no-interaction
elif [ -f composer.phar ]; then
    php composer.phar install --no-dev --optimize-autoloader --no-interaction
else
    echo "ERROR: composer is not installed." >&2
    echo "       Without it there is no vendor/autoload.php and every" >&2
    echo "       request fails with a 500. Install it into the tool:" >&2
    echo "         cd $REPO" >&2
    echo "         curl -sS https://getcomposer.org/installer | php" >&2
    echo "         php composer.phar install --no-dev --optimize-autoloader" >&2
    exit 1
fi

if [ ! -f vendor/autoload.php ]; then
    echo "ERROR: composer finished but vendor/autoload.php is still missing." >&2
    exit 1
fi

say "Checking the frontend build"
# The built SPA is committed, because Toolforge has no Node for the tool
# to build with. So the normal case here is that there is nothing to do
# and pnpm is absent — only its absence *and* a missing build is a
# problem.
if [ ! -f public/index.html ]; then
    echo "ERROR: public/index.html is missing — the SPA has not been built." >&2
    echo "       It is built on a developer machine and committed, since" >&2
    echo "       Toolforge has no Node. On your own machine run:" >&2
    echo "         cd frontend && pnpm install && pnpm run build" >&2
    echo "         git add public && git commit && git push" >&2
    echo "       then pull here and re-run this script." >&2
    exit 1
fi

if command -v pnpm >/dev/null 2>&1 && [ "${REBUILD_FRONTEND:-}" = "1" ]; then
    say "Rebuilding the frontend (REBUILD_FRONTEND=1)"
    (cd frontend && pnpm install --frozen-lockfile && pnpm run build)
else
    echo "  Using the committed build in public/ ($(find public/assets -name '*.js' 2>/dev/null | wc -l) chunks)."
fi

say "Updating the database schema"
# The schema is derived from the entity mappings rather than from a
# migration history: doctrine-migrations is a dependency, but no
# migrations have been written and there is no configuration file for it,
# so calling it here only fails.
#
# schema:update adds new tables and columns. It stops rather than apply a
# DROP, which ORM 3 will generate for anything in the database that the
# entity mappings do not describe — an unattended deploy is the last place
# a table should disappear without being asked about.
php bin/console schema:update

# Everything past here writes into the tool's home directory and drives
# the Toolforge webservice, so it is meaningless — and untidy — anywhere
# else. Running the script locally still exercises every step above.
if ! command -v toolforge >/dev/null 2>&1; then
    say "Not on Toolforge"
    echo "  Dependencies, frontend and schema are up to date."
    echo "  Skipping the docroot symlink, lighttpd config and restart,"
    echo "  which only apply to a Toolforge deployment."
    exit 0
fi

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
