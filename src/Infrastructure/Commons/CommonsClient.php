<?php

declare(strict_types=1);

namespace JuryTool\Infrastructure\Commons;

use Generator;
use JsonException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Read-only client for the Wikimedia Commons API.
 *
 * Uses the modern `continue` protocol rather than the long-removed
 * `query-continue`, and always sends a descriptive User-Agent as the
 * Wikimedia API etiquette policy requires.
 */
class CommonsClient
{
    private const MAX_TITLES_PER_QUERY = 50;

    /**
     * Titles the most recent filesByTitle() call could not resolve.
     *
     * @var list<string>
     */
    private array $missingTitles = [];

    /** @param array<string, mixed> $settings */
    public function __construct(
        private readonly array $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Streams every file in a category, following continuation until
     * exhausted. Yields rather than returning an array so that categories
     * with tens of thousands of files do not have to fit in memory.
     *
     * @return Generator<CommonsFile>
     */
    public function filesInCategory(string $category): Generator
    {
        $category = preg_replace('/^Category:/i', '', trim($category)) ?? $category;
        $continue = [];
        $seen = 0;

        do {
            $response = $this->request(array_merge([
                'action' => 'query',
                'generator' => 'categorymembers',
                'gcmtitle' => 'Category:' . $category,
                // Files only; categories nested inside are ignored.
                'gcmtype' => 'file',
                'gcmlimit' => (string) $this->settings['batch_size'],
                'prop' => 'imageinfo',
                'iiprop' => 'url|size|mime|user|timestamp',
                'iiurlwidth' => (string) $this->settings['thumb_width'],
            ], $continue));

            foreach ($this->extractFiles($response) as $file) {
                $seen++;
                yield $file;
            }

            $continue = $response['continue'] ?? [];
        } while ($continue !== []);

        $this->logger->info('Category enumerated', ['category' => $category, 'files' => $seen]);
    }

    /**
     * Resolves a list of file titles, in batches of 50 (the API ceiling for
     * multi-title queries).
     *
     * @param list<string> $titles Titles with or without the "File:" prefix.
     * @return Generator<CommonsFile>
     */
    public function filesByTitle(array $titles): Generator
    {
        $this->missingTitles = [];

        $normalised = [];
        foreach ($titles as $title) {
            $title = trim($title);
            if ($title === '') {
                continue;
            }
            if (!preg_match('/^File:/i', $title)) {
                $title = 'File:' . $title;
            }
            $normalised[$title] = true;
        }

        foreach (array_chunk(array_keys($normalised), self::MAX_TITLES_PER_QUERY) as $batch) {
            $response = $this->request([
                'action' => 'query',
                'titles' => implode('|', $batch),
                'prop' => 'imageinfo',
                'iiprop' => 'url|size|mime|user|timestamp',
                'iiurlwidth' => (string) $this->settings['thumb_width'],
            ]);

            // Titles the API could not resolve are collected rather than
            // dropped: a misspelled or since-renamed filename means a real
            // entry would go unjudged, and the coordinator needs to know.
            foreach ($response['query']['pages'] ?? [] as $page) {
                if (isset($page['missing']) || isset($page['invalid'])) {
                    $this->missingTitles[] = (string) ($page['title'] ?? 'unknown');
                }
            }

            yield from $this->extractFiles($response);
        }
    }

    /**
     * Human-readable warnings from the last filesByTitle() call.
     *
     * @return list<string>
     */
    public function lastWarnings(): array
    {
        return array_map(
            static fn (string $title): string => sprintf(
                'File "%s" does not exist. Check the spelling, and whether it has been renamed '
                . 'or deleted since the list was written.',
                preg_replace('/^File:/i', '', $title),
            ),
            $this->missingTitles,
        );
    }

    /**
     * Commons usernames starting with the given prefix, for the juror
     * autocomplete field.
     *
     * @return list<string>
     */
    public function searchUsers(string $prefix, int $limit = 10): array
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return [];
        }

        $response = $this->request([
            'action' => 'query',
            'list' => 'allusers',
            'auprefix' => $prefix,
            'aulimit' => (string) max(1, min($limit, 50)),
        ]);

        $users = [];
        foreach ($response['query']['allusers'] ?? [] as $user) {
            if (isset($user['name'])) {
                $users[] = (string) $user['name'];
            }
        }

        return $users;
    }

    /**
     * Commons categories matching a prefix, with how many files each holds.
     *
     * Typing a category by hand is how an import silently returns nothing:
     * "Images from Wiki Loves Earth 2026" and "Images from Wiki Loves Earth
     * 2026 in India" differ by four words, and the wrong one is a valid
     * category that simply has no files. The count is returned alongside
     * the name so the choice can be made on evidence.
     *
     * @return list<array{name: string, files: int}>
     */
    public function searchCategories(string $prefix, int $limit = 10): array
    {
        $prefix = trim(preg_replace('/^Category:/i', '', trim($prefix)) ?? '');

        if ($prefix === '') {
            return [];
        }

        // allcategories enumerates category pages by prefix; acprop=size
        // carries the file count, so no second request is needed.
        $response = $this->request([
            'action' => 'query',
            'list' => 'allcategories',
            'acprefix' => $prefix,
            'aclimit' => (string) max(1, min($limit, 50)),
            'acprop' => 'size',
        ]);

        $categories = [];

        foreach ($response['query']['allcategories'] ?? [] as $category) {
            // formatversion=2 returns the title in 'category'; older
            // shapes put it in '*'.
            $name = $category['category'] ?? $category['*'] ?? null;

            if ($name === null) {
                continue;
            }

            $categories[] = [
                'name' => str_replace('_', ' ', (string) $name),
                'files' => (int) ($category['files'] ?? 0),
            ];
        }

        return $categories;
    }

    /** Whether a Commons account with this exact name exists. */
    public function userExists(string $username): bool
    {
        $username = trim($username);
        if ($username === '') {
            return false;
        }

        $response = $this->request([
            'action' => 'query',
            'list' => 'users',
            'ususers' => $username,
        ]);

        $user = $response['query']['users'][0] ?? null;

        return is_array($user) && !isset($user['missing'], $user['invalid']);
    }

    /**
     * Fetches a newline-separated file list from an arbitrary URL.
     *
     * Only http/https is accepted, so a crafted "file://" or "gopher://"
     * source cannot be used to read local files through this path.
     *
     * @return list<string>
     */
    public function fetchFileList(string $url): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('File list URL must be http or https.');
        }

        $body = $this->httpGet($url);

        $titles = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $titles[] = $line;
        }

        return $titles;
    }

    /**
     * Turns an API response into CommonsFile objects, skipping pages that
     * are missing, are not files, or are not bitmap images.
     *
     * @param array<string, mixed> $response
     * @return list<CommonsFile>
     */
    private function extractFiles(array $response): array
    {
        $files = [];

        foreach ($response['query']['pages'] ?? [] as $page) {
            if (!is_array($page)) {
                continue;
            }

            $file = CommonsFile::fromApiPage($page);

            if ($file === null || !$file->isImage()) {
                continue;
            }

            $files[] = $file;
        }

        return $files;
    }

    /**
     * Issues a GET against the Commons API and decodes the JSON body.
     *
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function request(array $params): array
    {
        $params['format'] = 'json';
        $params['formatversion'] = '2';

        $url = $this->settings['api_url'] . '?' . http_build_query($params);
        $body = $this->httpGet($url);

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Commons API returned invalid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Commons API returned an unexpected payload.');
        }

        if (isset($decoded['error'])) {
            $code = $decoded['error']['code'] ?? 'unknown';
            $info = $decoded['error']['info'] ?? 'no detail';

            throw new RuntimeException("Commons API error [$code]: $info");
        }

        return $decoded;
    }

    private function httpGet(string $url): string
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('Unable to initialise HTTP client.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => (int) $this->settings['timeout'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => (string) $this->settings['user_agent'],
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            // Redirects must not be allowed to hop to a non-HTTP scheme.
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("HTTP request failed: $error");
        }

        if ($status >= 400) {
            throw new RuntimeException("HTTP request failed with status $status.");
        }

        return (string) $body;
    }
}
