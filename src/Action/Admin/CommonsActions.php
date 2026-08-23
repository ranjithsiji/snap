<?php

declare(strict_types=1);

namespace JuryTool\Action\Admin;

use JuryTool\Infrastructure\Commons\CommonsClient;
use JuryTool\Infrastructure\Commons\ReplicaClient;
use JuryTool\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Commons lookups used by the admin UI, chiefly the juror autocomplete.
 */
class CommonsActions
{
    public function __construct(
        private readonly CommonsClient $commons,
        private readonly ReplicaClient $replica,
    ) {
    }

    /**
     * Which Commons source imports will actually use.
     *
     * The fall back from replica to API is deliberately silent, so that a
     * misconfigured deployment still works — but it is a large speed
     * difference, and without this the only symptom is an import that
     * takes minutes. Credentials are never included, only whether they
     * were found and where.
     */
    public function status(Request $request, Response $response): Response
    {
        $available = $this->replica->isAvailable();

        return Json::write($response, [
            'source' => $available ? 'replica' : 'api',
            'replica' => [
                'available' => $available,
                'configured' => $this->replica->isConfigured(),
                'credentialsFrom' => $this->replica->credentialsSource(),
                'host' => $this->replica->host(),
            ],
        ]);
    }

    /** Usernames matching a prefix, for the jurors tag input. */
    public function searchUsers(Request $request, Response $response): Response
    {
        $prefix = trim((string) ($request->getQueryParams()['q'] ?? ''));

        if ($prefix === '') {
            return Json::write($response, ['users' => []]);
        }

        return Json::write($response, [
            'users' => $this->commons->searchUsers($prefix, 10),
        ]);
    }
}
