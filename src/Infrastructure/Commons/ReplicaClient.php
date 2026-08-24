<?php

declare(strict_types=1);

namespace JuryTool\Infrastructure\Commons;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;

/**
 * Reads Commons metadata straight from the Wikimedia replica database.
 *
 * Available on Toolforge, where `commonswiki.analytics.db.svc.wikimedia.cloud`
 * exposes a read-only copy of the wiki. A category that takes minutes to
 * page through the web API comes back from one SQL query in seconds, so
 * this is strongly preferred when the tool runs there.
 *
 * The replica has no thumbnail URLs, so they are built from Special:FilePath,
 * which redirects to the current file regardless of which hash bucket it
 * lives in. An earlier version computed that bucket by hand from the file
 * name's MD5 and got it wrong — MediaWiki's actual rule is the MD5 of the
 * DB-form title, not the display title — so every image 404'd. Redirecting
 * through Special:FilePath is what mist does for the same reason: it costs
 * one redirect instead of one bug per naming edge case.
 */
class ReplicaClient
{
    private ?Connection $connection = null;

    /** @param array<string, mixed> $settings */
    public function __construct(
        private readonly array $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Whether credentials were found, regardless of whether the host can
     * actually be reached. Distinguishes "not on Toolforge" from "on
     * Toolforge but the replica is refusing connections".
     */
    public function isConfigured(): bool
    {
        return !empty($this->settings['user']) && !empty($this->settings['password']);
    }

    /** Where the credentials came from, for diagnostics. Never their value. */
    public function credentialsSource(): ?string
    {
        $source = $this->settings['credentials_source'] ?? null;

        return $source === null ? null : (string) $source;
    }

    public function host(): ?string
    {
        return isset($this->settings['host']) ? (string) $this->settings['host'] : null;
    }

    /** Whether a replica connection is configured for this deployment. */
    public function isAvailable(): bool
    {
        // `enabled` already accounts for whether credentials were found; it
        // is also how REPLICA_ENABLED=0 forces the API path back on.
        if (empty($this->settings['enabled'])) {
            return false;
        }

        if (empty($this->settings['host']) || empty($this->settings['user'])) {
            return false;
        }

        try {
            $this->connection()->executeQuery('SELECT 1');

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Replica unavailable, falling back to the API', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Every file in a category, as CommonsFile objects.
     *
     * Collects the whole category into one array. Prefer
     * streamFilesInCategory() for imports: this exists for callers that
     * genuinely need the list at once, and its memory grows with the
     * category.
     *
     * @throws CategoryTooLargeException when the category exceeds the limit
     * @return list<CommonsFile>
     */
    public function filesInCategory(string $category): array
    {
        return iterator_to_array($this->streamFilesInCategory($category), false);
    }

    /**
     * The same files, yielded one at a time as each page is read.
     *
     * A category of a hundred thousand files is too large to hold in
     * memory alongside the entities being written for it, so an import
     * consumes this and flushes as it goes. Only one page of rows is ever
     * resident.
     *
     * Each file is keyed by the `cl_from` it was read at. Recording that
     * as the import writes lets a later attempt pass it back as $after and
     * resume, rather than re-reading a category from the beginning to
     * establish that most of it is already stored.
     *
     * @param int $after Resume point; rows at or before it are not read.
     * @throws CategoryTooLargeException when the category exceeds the limit
     * @return \Generator<int, CommonsFile>
     */
    public function streamFilesInCategory(string $category, int $after = 0): \Generator
    {
        $category = str_replace(' ', '_', preg_replace('/^Category:/i', '', trim($category)) ?? '');

        // Asked before reading anything. Truncating an import silently is
        // the one outcome worth avoiding outright: a photograph missing
        // from a round is invisible until the results are wrong, whereas a
        // refusal names the problem while it can still be fixed.
        $max = (int) ($this->settings['max_files'] ?? 120000);
        $total = $this->countCategory($category);

        if ($total > $max) {
            throw new CategoryTooLargeException($category, $total, $max);
        }

        // Commons has normalised categorylinks: cl_to no longer exists on
        // the real Toolforge replica (confirmed live — a direct cl_to
        // comparison fails with "Unknown column 'cl.cl_to'"). cl_target_id
        // joined to linktarget is what the current schema actually has.
        //
        // Paging is by cl_from rather than OFFSET, because MariaDB re-scans
        // and discards every skipped row for a large OFFSET — the last
        // batches of a big category would cost far more than the first.
        $sql = <<<'SQL'
            SELECT
                cl.cl_from           AS cursor_id,
                p.page_id            AS page_id,
                p.page_title         AS page_title,
                img.img_width        AS width,
                img.img_height       AS height,
                img.img_major_mime   AS major_mime,
                img.img_minor_mime   AS minor_mime,
                img.img_timestamp    AS uploaded_at,
                actor.actor_name     AS uploader
            FROM categorylinks cl
            INNER JOIN linktarget lt ON lt.lt_id = cl.cl_target_id
            INNER JOIN page p        ON p.page_id = cl.cl_from
            INNER JOIN image img     ON img.img_name = p.page_title
            LEFT JOIN actor          ON actor.actor_id = img.img_actor
            WHERE lt.lt_title = :category
              AND lt.lt_namespace = 14
              AND p.page_namespace = 6
              AND cl.cl_from > :after
            ORDER BY cl.cl_from ASC
            LIMIT :batch
            SQL;

        // $after arrives as the caller's resume point and advances from
        // there; it is deliberately not reset.
        $batch = (int) ($this->settings['batch_size'] ?? 10000);
        $seen = 0;
        $yielded = 0;
        $resumedFrom = $after;

        do {
            $rows = $this->connection()->executeQuery(
                $sql,
                ['category' => $category, 'after' => $after, 'batch' => $batch],
                [
                    // DBAL 4 takes ParameterType enum cases here. The old
                    // \PDO::PARAM_* constants are plain ints and are
                    // rejected outright, which aborted every import before
                    // its first file was read.
                    'category' => ParameterType::STRING,
                    'after' => ParameterType::INTEGER,
                    'batch' => ParameterType::INTEGER,
                ],
            )->fetchAllAssociative();

            foreach ($rows as $row) {
                $cursor = (int) $row['cursor_id'];
                $after = max($after, $cursor);
                $file = $this->toFile($row);

                if ($file !== null) {
                    $yielded++;

                    // The cursor travels on the file itself rather than as
                    // the generator key: the API path yields auto-numbered
                    // keys that look identical but mean a position, not a
                    // page id, and resuming from one would silently skip
                    // files. A file that carries its own cursor cannot be
                    // confused with one that does not.
                    yield $file->withResumeCursor($cursor);
                }
            }

            $seen += count($rows);

            // The count above is checked before any reading, so passing
            // this means the category grew mid-import. Stopping bounds the
            // work; counted on rows read rather than files kept, so a
            // category of non-images cannot loop forever either.
            if ($seen >= $max) {
                $this->logger->warning('Category truncated at the file limit', [
                    'category' => $category,
                    'limit' => $max,
                ]);

                break;
            }
        } while (count($rows) === $batch);

        $this->logger->info('Category read from replica', [
            'category' => $category,
            'files' => $yielded,
            'rows' => $seen,
            'resumed_from' => $resumedFrom ?: null,
        ]);
    }

    /**
     * Resolves file titles in one query.
     *
     * @param list<string> $titles
     * @return list<CommonsFile>
     */
    public function filesByTitle(array $titles): array
    {
        $normalised = [];

        foreach ($titles as $title) {
            $title = trim(preg_replace('/^File:/i', '', trim($title)) ?? '');

            if ($title !== '') {
                $normalised[] = str_replace(' ', '_', $title);
            }
        }

        if ($normalised === []) {
            return [];
        }

        $sql = <<<'SQL'
            SELECT
                p.page_id            AS page_id,
                p.page_title         AS page_title,
                img.img_width        AS width,
                img.img_height       AS height,
                img.img_major_mime   AS major_mime,
                img.img_minor_mime   AS minor_mime,
                img.img_timestamp    AS uploaded_at,
                actor.actor_name     AS uploader
            FROM page p
            JOIN image img     ON img.img_name = p.page_title
            LEFT JOIN actor    ON actor.actor_id = img.img_actor
            WHERE p.page_namespace = 6 AND p.page_title IN (:titles)
            SQL;

        $rows = $this->connection()->executeQuery(
            $sql,
            ['titles' => $normalised],
            ['titles' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return array_values(array_filter(array_map(
            fn (array $row): ?CommonsFile => $this->toFile($row),
            $rows,
        )));
    }

    /** How many files a category holds, without fetching them. */
    public function countCategory(string $category): int
    {
        $category = str_replace(' ', '_', preg_replace('/^Category:/i', '', trim($category)) ?? '');

        return (int) $this->connection()->executeQuery(
            'SELECT COUNT(*)
             FROM categorylinks cl
             INNER JOIN linktarget lt ON lt.lt_id = cl.cl_target_id
             INNER JOIN page p ON p.page_id = cl.cl_from
             WHERE lt.lt_title = :category
               AND lt.lt_namespace = 14
               AND p.page_namespace = 6',
            ['category' => $category],
        )->fetchOne();
    }

    /**
     * Builds a CommonsFile from a replica row.
     *
     * @param array<string, mixed> $row
     */
    private function toFile(array $row): ?CommonsFile
    {
        $title = (string) $row['page_title'];
        $mime = trim(sprintf('%s/%s', $row['major_mime'] ?? '', $row['minor_mime'] ?? ''), '/');

        // Video and audio live in the File namespace too; only bitmaps are
        // judged here.
        if ($mime !== '' && !str_starts_with($mime, 'image/')) {
            return null;
        }

        $timestamp = null;

        if (!empty($row['uploaded_at'])) {
            $parsed = DateTimeImmutable::createFromFormat('YmdHis', (string) $row['uploaded_at']);
            $timestamp = $parsed !== false ? $parsed : null;
        }

        $width = (int) ($row['width'] ?? 0);

        return new CommonsFile(
            pageId: (int) $row['page_id'],
            title: 'File:' . str_replace('_', ' ', $title),
            fileUrl: $this->fileUrl($title),
            descriptionUrl: 'https://commons.wikimedia.org/wiki/File:' . rawurlencode($title),
            thumbUrl: $this->thumbUrl($title, min($width ?: 1024, (int) $this->settings['thumb_width'])),
            width: $width,
            height: (int) ($row['height'] ?? 0),
            mimeType: $mime !== '' ? $mime : null,
            uploader: isset($row['uploader']) ? str_replace('_', ' ', (string) $row['uploader']) : null,
            uploadedAt: $timestamp,
        );
    }

    /**
     * The original file, via the redirect MediaWiki itself exposes for
     * this. This used to compute the upload path's hash-bucket directory
     * by hand from the file name's MD5 — every file 404'd, because
     * MediaWiki's rule is the MD5 of the DB-form title, and getting a
     * derivation like that byte-exact is a standing invitation to drift.
     * Special:FilePath resolves to wherever the file actually lives, so
     * there is no bucket to get wrong.
     */
    private function fileUrl(string $title): string
    {
        return 'https://commons.wikimedia.org/wiki/Special:FilePath/' . rawurlencode($title);
    }

    private function thumbUrl(string $title, int $width): string
    {
        return $this->fileUrl($title) . '?width=' . $width;
    }

    private function connection(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $params = [
            'driver' => 'pdo_mysql',
            'host' => (string) $this->settings['host'],
            'port' => (int) ($this->settings['port'] ?? 3306),
            'dbname' => (string) $this->settings['dbname'],
            'user' => (string) $this->settings['user'],
            'password' => (string) ($this->settings['password'] ?? ''),
            'charset' => 'utf8mb4',
        ];

        // replica.my.cnf carries `disable-ssl = true`; honouring it is what
        // lets the connection open at all, since the driver would otherwise
        // insist on TLS the replica does not offer.
        if (!empty($this->settings['disable_ssl'])) {
            $params['driverOptions'] = [
                \PDO::MYSQL_ATTR_SSL_CA => null,
                \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
            ];
        }

        return $this->connection = DriverManager::getConnection($params);
    }
}
