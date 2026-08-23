# Wiki Loves Jury Tool

Jury tool for Wiki Loves campaigns on Wikimedia Commons. Administrators set
up a campaign and import its images from Commons; organizers configure
judging rounds; jurors rate, accept, reject or rank the photographs.

Built as a monolith: a Slim 4 JSON API and a Vue 3 single-page app that is
compiled into `public/` and served by the same PHP process.

## Requirements

- PHP 8.2 or newer, with `curl`, `json`, `pdo_mysql` and `mbstring`
- MariaDB 10.6+ (or MySQL 8)
- Composer 2
- Node 18+ and [pnpm](https://pnpm.io/), to build the frontend

## Installation

```bash
composer install

# Create the database and a user for it
sudo mysql -e "
CREATE DATABASE jurytool CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'jurytool'@'localhost' IDENTIFIED BY 'change-me';
GRANT ALL PRIVILEGES ON jurytool.* TO 'jurytool'@'localhost';
FLUSH PRIVILEGES;"

cp .env.example .env
# Edit .env: set DB_PASSWORD, and generate a JWT_SECRET with
#   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"

php bin/console schema:create

# Build the frontend into public/
cd frontend && pnpm install && pnpm run build && cd ..

# Create the first administrator
php bin/console user:create YourName 'a-strong-password' administrator
```

Serve `public/` with your web server, or for development:

```bash
composer serve      # php -S localhost:8080 -t public
```

Then open <http://localhost:8080> and sign in.

### Frontend development

`pnpm run build` recompiles into `public/`. For hot reloading, run the PHP
server and `pnpm run dev` side by side — Vite proxies `/api` to port 8080:

```bash
composer serve                  # terminal 1
cd frontend && pnpm run dev     # terminal 2, then use Vite's URL
```

## Deploying to Toolforge

Snap runs at <https://snap.toolforge.org/> with this layout:

```
/data/project/snap/replica.my.cnf   credentials, outside the repo
/data/project/snap/snap/            this repository
/data/project/snap/snap/public/     built SPA + index.php
/data/project/snap/public_html   -> snap/public   (the docroot)
/data/project/snap/.lighttpd.conf -> snap/.lighttpd.conf
```

```bash
become snap
git clone https://github.com/ranjithsiji/snap.git ~/snap
cd ~/snap && ./bin/toolforge-deploy.sh
```

The script installs dependencies, builds the frontend, migrates, links
the docroot and the lighttpd config, and restarts the web service. It is
safe to re-run for each subsequent deploy.

**Database credentials are not configured.** Toolforge issues one
credential pair in `~/replica.my.cnf`, and it opens both the tool's own
database on the tools cluster and the Commons replicas. Snap reads that
file directly, so `DB_USER`, `DB_PASSWORD`, `REPLICA_USER` and
`REPLICA_PASSWORD` are all left unset. The database name defaults to
`<credential user>__snap`, but must be created once:

```bash
sql tools --execute "CREATE DATABASE ${USER}__snap;"
```

Only these need to be in `.env` on Toolforge:

```ini
APP_ENV=prod
APP_URL=https://snap.toolforge.org
JWT_SECRET=            # php -r "echo bin2hex(random_bytes(32));"
OAUTH_CLIENT_ID=
OAUTH_CLIENT_SECRET=
OAUTH_REDIRECT_URI=https://snap.toolforge.org/wikicallback
```

Because it runs on Toolforge, category imports read the Wikimedia replica
directly — one SQL query instead of paging the web API, seconds rather
than minutes. `GET /api/commons/status` reports which source is live; if
credentials are missing it falls back to the API silently, and that
endpoint is how you find out.

Errors are logged to `~/error.log`.

## Wikimedia OAuth

Local accounts work out of the box. To let people sign in with their
Commons account, register an OAuth 2.0 consumer at
[Special:OAuthConsumerRegistration](https://meta.wikimedia.org/wiki/Special:OAuthConsumerRegistration/propose/oauth2)
with callback `https://your-host/api/auth/callback` and only the *Basic
rights* grant. Put the client id and secret in `.env`.

Once OAuth works you can set `LOCAL_LOGIN_ENABLED=false` to require it.

## Access levels

| Role | Can do |
| --- | --- |
| **Administrator** | Everything: creates campaigns, grants roles, sees the admin dashboard |
| **Organizer** | Runs the contest: creates and configures rounds, manages jurors, reads results |
| **Juror** | Rates, accepts, rejects, ranks and comments on rounds they sit on |

Being a juror on a round is a *seat*, separate from these global roles. A
seat is invited by Wikimedia username and can be handed to a different
person later — see "Replacing a juror" below.

## Images are never copied here

The tool stores Commons **URLs only** — never image files. Browsers load
every photograph straight from `upload.wikimedia.org`, so the tool needs no
image storage and adds no bandwidth of its own. A campaign of 6,658 images
occupies a few megabytes of database rows.

Thumbnail widths are chosen per view (smaller in the grid, larger on the
voting stage, original for "show full-size"), and jurors on slow
connections can request reduced widths.

## How it works

### Campaigns own the images

A campaign defines the image source once — a Commons category, a file list
URL, or a pasted file list — and imports it into a master pool when it is
created. Rounds draw from that pool rather than querying Commons again.

Re-import at any time to pick up files added to the source since; existing
entries are matched on Commons page id, so renames are handled.

### Rounds

Each round configures:

- **Voting method** — Yes/No, Rating (with a configurable star ceiling), or
  Ranking
- **Voting deadline**, after which votes are refused
- **Quorum** — how many jurors must judge each image (`0` means all of them)
- **Jurors**, added by Wikimedia username with autocomplete against Commons
- **Show own statistics** — whether jurors see their own tallies
- **Round file settings** — the disqualification and display rules

Lifecycle: `draft → active ⇄ paused → finalized`. Activating a round deals
out the work (see below); finalizing freezes it so results can be exported
and a following round derived from it.

### Dividing images among jurors

When a round is activated, every qualified image is dealt to exactly
*quorum* jurors, so each image is guaranteed the required number of
independent opinions and every juror gets an equal, defined workload.

A juror who finishes their own list spills over onto images still short of
quorum — work left by someone who stopped participating — so a round can
still complete if a juror goes quiet. Use **Allocate** after adding jurors
or importing more images to spread the new work.

### Disqualification rules

Per round, images can be excluded automatically:

- **By resolution** — below a pixel floor, 2,000,000 (2 MP) by default
- **By upload date** — outside a date window
- **By uploader role** — jurors (across the whole campaign), organizers,
  coordinators or maintainers

Disqualified images are kept rather than deleted, with the reason recorded,
so exclusions can be audited. Editing the rules re-evaluates images that
have not yet been voted on; images already carrying votes are left alone.

Campaign participants (organizers, coordinators, maintainers) are listed on
the campaign page. Anyone holding a global organizer or administrator role
counts as an organizer even if nobody added them to that list.

### Judging

Yes/No and Rating rounds offer two modes:

- **Single image** — one photograph at a time with keyboard shortcuts
  (`↑`/`↓` or `1`–`N` to vote, `→` to skip), plus favourites and full-size
  view
- **Gallery** — a grid for fast passes, with tabs for Unrated, Selected,
  Rejected and Favorites; also how previous votes are revised

Ranking rounds are always a gallery: type a rank under each image, and
**Rearrange** re-sorts by those ranks. Entering a rank already in use
shifts the others down, so duplicates cannot be submitted.

### Replacing a juror

If a juror stops participating, an organizer can hand their seat to someone
else from the round page. **The votes already cast on that seat transfer to
the incoming juror**, so quorum counts stay intact and no work is lost. The
new juror is told plainly that they have inherited those votes and can
review and change any of them under *Edit previous votes*. The previous
holder's username is recorded on the seat.

### Deriving the next round

Finalize a round, then create the next one from its results by criteria —
a minimum number of accept votes, a minimum average score, and/or the top
N images. Preview the count before committing. The new round inherits the
source round's settings and jury panel, and starts in draft.

### The final jury meeting

Once the judging rounds are done, open a **final jury meeting** on a
finalized round's shortlist. This is where the panel settles the result
together.

It is deliberately **asynchronous** — no live session is required. Panels
typically talk on a video call and record the outcome here over hours or
days.

- **Every juror's ranking is kept separately.** When jurors disagree about
  a photograph, both positions stay on the record instead of one silently
  overwriting the other.
- **The agreed order is computed from all proposals** by mean rank, so it
  updates as jurors submit and is meaningful even when only some have.
- **Disagreements are flagged.** An image whose proposed positions are far
  apart is marked disputed and listed in a Conflicts tab, worst first. The
  threshold scales with the size of the shortlist.
- **Opinions settle disputes.** Any participant can argue for a position
  and say whose proposal they back, without changing their own ranking.
  Others agree or disagree with a click, and the best-supported argument
  rises to the top.
- **General discussion** runs alongside, and posts can reference images.
- **Finalize and reopen.** An organizer locks the result when the panel is
  content; it can be reopened if they need to revisit it.

Everyone who judged the source round takes part, plus organizers.

### Administration dashboard

Administrators get a dashboard at `/admin`:

- **Overview** — users by role, campaign, round, image and vote counts
- **Users** — search, change roles, block and unblock, reset passwords,
  create local accounts for people who cannot use Wikimedia OAuth
- **Activity** — an audit log of every campaign, round, meeting and user
  action, with who did it, when, and from where

Generated passwords are shown once and stored only as a hash. The last
active administrator cannot be demoted or blocked, so an installation can
never be locked out.

## Commands

```
schema:create              Create all tables
schema:update              Apply entity changes to the database
schema:drop --force        Drop all tables
schema:sql                 Print the CREATE statements without applying

user:create <name> <pass> [role]   Create a local account
user:role <name> <role>            Change a user's role
user:list                          List all users

campaign:list                      List campaigns
campaign:import <id>               Re-import a campaign source

round:import <id> [--populate]     Import a round's own source
```

### Importing large categories

Import from the command line rather than the browser once a category runs
to tens of thousands of files: the HTTP request would have to stay open
for the whole fetch, and PHP's execution limit ends it long before that.

```bash
php bin/console round:import 12 --populate
```

The files are read in pages and written as they arrive, so memory stays
flat however large the category is — measured at 16 MB while importing
6,658 files. Progress is reported as it goes, since a silent terminal is
indistinguishable from a hung process.

Without `--populate` the files land in the campaign pool but are not
added to the round, which lets a large import run ahead of time and the
round be filled separately.

### Resuming an interrupted import

Re-running continues the unfinished attempt rather than starting a new
one, and files are matched on their Commons page id, so nothing is
duplicated however many times an import is interrupted.

On Toolforge the replica reader also records how far it got, as the
`cl_from` it was paging on. A resumed import asks for rows after that
point instead of re-reading from the beginning:

```
Continuing import #7 (attempt 2) from cursor 48,127
```

The position is only saved alongside the flush that durably writes the
rows it covers, so a crash can never skip files that were not actually
stored. It is cleared once the source completes, so a later re-import of
the same category picks up files added since.

Reading through the Commons API cannot resume this way — it pages on an
opaque continue token that does not map onto individual files — so those
imports restart, and the page-id match keeps the repeated work to a
single `UPDATE` per file.

Because resuming skips everything before the cursor, a file added to the
category earlier in the ordering would not be seen. `--restart` drops the
saved position and reads the whole source again.

A category larger than `REPLICA_MAX_FILES` (120,000 by default) is
refused outright rather than imported partway, because a truncated
import is indistinguishable from a complete one until a photograph turns
out to have gone unjudged.

## Layout

```
bin/console        Maintenance commands
config/            Settings, DI container, routes, bootstrap
public/            Front controller and the built SPA
src/Domain/        Entities and enums
src/Service/       Import, disqualification, allocation, voting, statistics
src/Infrastructure/ Commons API client
src/Action/        HTTP handlers
src/Middleware/    Authentication, authorisation, JSON errors
frontend/          Vue 3 + Codex source
```

## Licence

GPL-3.0-or-later. Wiki Loves Monuments and Wiki Loves Earth are Wikimedia
community projects; this tool is an independent implementation.
