<?php

declare(strict_types=1);

namespace JuryTool\Infrastructure\Commons;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Log\LoggerInterface;

/**
 * Reads Commons metadata straight from the Wikimedia replica database.
 *
 * Available on Toolforge, where `commonswiki.analytics.db.svc.wikimedia.cloud`
 * exposes a read-only copy of the wiki. A category that takes minutes to
 * page through the web API comes back from one SQL query in seconds, so
 * this is strongly preferred when the tool runs there.
 *
 * The replica has no thumbnail URLs — those are derived from the file name
 * and its MD5, exactly as MediaWiki does it.
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
     * @throws CategoryTooLargeException when the category exceeds the limit
     * @return \Generator<int, CommonsFile>
     */
    public function streamFilesInCategory(string $category): \Generator
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

        // Commons has normalised categorylinks: cl_to is being retired in
        // favour of cl_target_id joined to linktarget. Joining through
        // linktarget is what works on the current replica.
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

        $after = 0;
        $batch = (int) ($this->settings['batch_size'] ?? 10000);
        $seen = 0;
        $yielded = 0;

        do {
            $rows = $this->connection()->executeQuery(
                $sql,
                ['category' => $category, 'after' => $after, 'batch' => $batch],
                [
                    'category' => \PDO::PARAM_STR,
                    'after' => \PDO::PARAM_INT,
                    'batch' => \PDO::PARAM_INT,
                ],
            )->fetchAllAssociative();

            foreach ($rows as $row) {
                $after = max($after, (int) $row['cursor_id']);
                $file = $this->toFile($row);

                if ($file !== null) {
                    $yielded++;

                    yield $file;
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
            ['titles' => \Doctrine\DBAL\ArrayParameterType::STRING],
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
     * The upload path MediaWiki uses: two levels of directory taken from
     * the MD5 of the file name.
     */
    private function fileUrl(string $title): string
    {
        $hash = md5($title);

        return sprintf(
            'https://upload.wikimedia.org/wikipedia/commons/%s/%s/%s',
            $hash[0],
            substr($hash, 0, 2),
            rawurlencode($title),
        );
    }

    private function thumbUrl(string $title, int $width): string
    {
        $hash = md5($title);

        return sprintf(
            'https://upload.wikimedia.org/wikipedia/commons/thumb/%s/%s/%s/%dpx-%s',
            $hash[0],
            substr($hash, 0, 2),
            rawurlencode($title),
            $width,
            rawurlencode($title),
        );
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
