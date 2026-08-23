<?php

declare(strict_types=1);

namespace JuryTool\Action\Admin;

use JuryTool\Infrastructure\Commons\CommonsClient;
use JuryTool\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Commons lookups used by the admin UI, chiefly the juror autocomplete.
 */
class CommonsActions
{
    public function __construct(private readonly CommonsClient $commons)
    {
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
