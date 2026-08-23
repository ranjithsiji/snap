<?php

declare(strict_types=1);

use JuryTool\Action\Admin\CampaignActions;
use JuryTool\Action\Admin\CommonsActions;
use JuryTool\Action\Admin\ProjectActions;
use JuryTool\Action\Admin\RoundActions;
use JuryTool\Action\Admin\UserActions;
use JuryTool\Action\Auth\AuthActions;
use JuryTool\Action\Jury\JuryActions;
use JuryTool\Action\Jury\MeetingActions;
use JuryTool\Middleware\RequireRole;
use JuryTool\Service\AccessControl;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    // Scoped authorisation needs the container; the role middleware is a
    // coarse gate and each action re-checks against the actual project or
    // campaign it is acting on.
    $access = $app->getContainer()->get(AccessControl::class);

    $app->group('/api', function (RouteCollectorProxy $api) use ($access): void {
        // --- Authentication -------------------------------------------
        $api->get('/auth/me', [AuthActions::class, 'me']);
        $api->post('/auth/login', [AuthActions::class, 'login']);
        $api->post('/auth/logout', [AuthActions::class, 'logout']);
        $api->get('/auth/wikimedia', [AuthActions::class, 'oauthStart']);
        $api->get('/auth/callback', [AuthActions::class, 'oauthCallback']);

        // --- Juror ----------------------------------------------------
        $api->group('', function (RouteCollectorProxy $jury) use ($access): void {
            $jury->get('/my/rounds', [JuryActions::class, 'myRounds']);
            $jury->get('/my/rounds/{id}', [JuryActions::class, 'round']);
            $jury->get('/my/rounds/{id}/queue', [JuryActions::class, 'queue']);
            $jury->get('/my/rounds/{id}/gallery', [JuryActions::class, 'gallery']);
            $jury->get('/my/rounds/{id}/votes', [JuryActions::class, 'previousVotes']);
            $jury->post('/my/rounds/{id}/rank', [JuryActions::class, 'rank']);
            $jury->post('/my/images/{imageId}/vote', [JuryActions::class, 'vote']);
            $jury->post('/my/images/{imageId}/skip', [JuryActions::class, 'skip']);
            $jury->post('/my/images/{imageId}/favorite', [JuryActions::class, 'favorite']);

            // Final jury meeting. Asynchronous by design: the panel usually
            // talks over a video call and records the outcome here.
            $jury->get('/meetings/{id}', [MeetingActions::class, 'show']);
            $jury->post('/meetings/{id}/order', [MeetingActions::class, 'reorder']);
            $jury->get('/meetings/{id}/proposals', [MeetingActions::class, 'proposals']);
            $jury->post('/meetings/{id}/proposals', [MeetingActions::class, 'propose']);
            $jury->get('/meetings/{id}/conflicts', [MeetingActions::class, 'conflicts']);
            $jury->post('/meetings/{id}/images/{imageId}/opinions', [MeetingActions::class, 'opine']);
            $jury->post('/meetings/opinions/{opinionId}/endorse', [MeetingActions::class, 'endorse']);
            $jury->get('/meetings/{id}/images/{imageId}/comments', [MeetingActions::class, 'imageComments']);
            $jury->post('/meetings/{id}/images/{imageId}/comments', [MeetingActions::class, 'comment']);
            $jury->post('/meetings/{id}/comments', [MeetingActions::class, 'comment']);
            $jury->patch('/meetings/comments/{commentId}', [MeetingActions::class, 'editComment']);
            $jury->post('/meetings/{id}/finalize', [MeetingActions::class, 'finalize']);
            $jury->post('/meetings/{id}/reopen', [MeetingActions::class, 'reopen']);
        })->add(RequireRole::jury($access));

        // --- Projects. Visibility is scoped inside the actions, since a
        // juror may see the project of a round they judge without holding
        // any role over it.
        $api->group('', function (RouteCollectorProxy $proj) use ($access): void {
            $proj->get('/projects', [ProjectActions::class, 'list']);
            $proj->get('/projects/{id}', [ProjectActions::class, 'show']);
            $proj->post('/projects', [ProjectActions::class, 'create']);
            $proj->patch('/projects/{id}', [ProjectActions::class, 'update']);
            $proj->delete('/projects/{id}', [ProjectActions::class, 'delete']);
            $proj->post('/projects/{id}/leads', [ProjectActions::class, 'appointLead']);
            $proj->delete('/projects/{id}/leads/{userId}', [ProjectActions::class, 'removeLead']);
        })->add(RequireRole::jury($access));

        // --- Organizer: runs the contest, including all round management
        $api->group('', function (RouteCollectorProxy $org) use ($access): void {
            $org->post('/campaigns/{id}/organizers', [CampaignActions::class, 'appointOrganizer']);
            $org->delete('/campaigns/{id}/organizers/{userId}', [CampaignActions::class, 'removeOrganizer']);
            $org->get('/commons/users', [CommonsActions::class, 'searchUsers']);

            $org->get('/campaigns', [CampaignActions::class, 'list']);
            $org->post('/campaigns', [CampaignActions::class, 'create']);
            $org->post('/campaigns/{id}/reimport', [CampaignActions::class, 'reimport']);
            $org->put('/campaigns/{id}/participants', [CampaignActions::class, 'setParticipants']);
            $org->get('/campaigns/{id}', [CampaignActions::class, 'show']);
            $org->patch('/campaigns/{id}', [CampaignActions::class, 'update']);

            $org->post('/campaigns/{campaignId}/rounds', [RoundActions::class, 'create']);
            $org->get('/rounds/{id}', [RoundActions::class, 'show']);
            $org->patch('/rounds/{id}', [RoundActions::class, 'update']);
            $org->delete('/rounds/{id}', [RoundActions::class, 'delete']);
            $org->post('/rounds/{id}/state/{state}', [RoundActions::class, 'transition']);
            $org->post('/rounds/{id}/allocate', [RoundActions::class, 'allocate']);
            $org->post('/rounds/{id}/import', [RoundActions::class, 'import']);
            $org->post('/rounds/{id}/import/{sourceId}/retry', [RoundActions::class, 'retryImport']);
            $org->get('/rounds/{id}/thresholds', [RoundActions::class, 'thresholds']);
            $org->post('/rounds/{id}/jurors/{jurorId}/replace', [RoundActions::class, 'replaceJuror']);
            $org->get('/rounds/{id}/images', [RoundActions::class, 'images']);
            $org->get('/rounds/{id}/results', [RoundActions::class, 'results']);
            $org->get('/rounds/{id}/export', [RoundActions::class, 'export']);
            $org->post('/rounds/{id}/derive/preview', [RoundActions::class, 'previewDerivation']);
            $org->post('/rounds/{id}/derive', [RoundActions::class, 'derive']);
            $org->post('/rounds/{id}/meeting', [RoundActions::class, 'createMeeting']);
        })->add(RequireRole::organizer($access));

        // --- Administrator: campaigns themselves, and the admin dashboard
        $api->group('', function (RouteCollectorProxy $admin) use ($access): void {
            $admin->delete('/campaigns/{id}', [CampaignActions::class, 'delete']);

            $admin->get('/admin/overview', [UserActions::class, 'overview']);
            $admin->get('/admin/activity', [UserActions::class, 'activity']);

            $admin->get('/admin/users', [UserActions::class, 'list']);
            $admin->post('/admin/users', [UserActions::class, 'create']);
            $admin->patch('/admin/users/{id}', [UserActions::class, 'update']);
            $admin->patch('/admin/users/{id}/role', [UserActions::class, 'setRole']);
            $admin->patch('/admin/users/{id}/active', [UserActions::class, 'setActive']);
            $admin->post('/admin/users/{id}/password', [UserActions::class, 'resetPassword']);
        })->add(RequireRole::admin($access));
    });

    // Anything that is not the API belongs to the SPA, which owns its own
    // client-side routing: serve the built index.html and let Vue Router
    // resolve the path. Static assets are served by the web server in
    // production and by PHP's built-in server during development.
    $app->get('/{path:.*}', function (Request $request, Response $response): Response {
        $index = dirname(__DIR__) . '/public/index.html';

        if (!is_file($index)) {
            $response->getBody()->write(
                'Frontend is not built yet. Run "npm install && npm run build" in frontend/.'
            );

            return $response->withStatus(503)->withHeader('Content-Type', 'text/plain');
        }

        $response->getBody()->write((string) file_get_contents($index));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    });
};
