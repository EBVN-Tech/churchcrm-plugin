<?php

// Included by PluginManager::registerPluginRoutes() with $app in scope.
// The application basePath is /plugins: '/custom-routes/ping' therefore
// answers on /plugins/custom-routes/ping. AuthMiddleware (global)
// authenticates by x-api-key before reaching here. Specification: docs/SPEC.md.

use ChurchCRM\Authentication\AuthenticationManager;
use ChurchCRM\dto\SystemConfig;
use ChurchCRM\Plugins\CustomRoutes\HttpException;
use ChurchCRM\Plugins\CustomRoutes\CustomRoutesPlugin;
use ChurchCRM\Plugins\CustomRoutes\SyncController;
use ChurchCRM\Slim\SlimUtils;
use ChurchCRM\Utils\LoggerUtils;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** @var \Slim\App<\Psr\Container\ContainerInterface|null> $app */

$plugin = CustomRoutesPlugin::getInstance();
if ($plugin === null) {
    return;
}
$controller = new SyncController($plugin);

/**
 * Runs an action and formats the JSON response. Logs only contain the
 * route, the method and the error type: never a received value nor the
 * message of an exception (an SQL error may quote a value).
 */
$respond = function (Request $request, Response $response, callable $action): Response {
    try {
        [$status, $body] = $action();

        return SlimUtils::renderJSON($response, $body, $status);
    } catch (HttpException $e) {
        $body = ['success' => false, 'error' => $e->getMessage()];
        if (!empty($e->getFields())) {
            $body['fields'] = $e->getFields();
        }

        return SlimUtils::renderJSON($response, $body, $e->getStatus());
    } catch (\Throwable $e) {
        LoggerUtils::getAppLogger()->error('custom-routes: internal error', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod(),
            'exception' => get_class($e),
            'at' => basename($e->getFile()) . ':' . $e->getLine(),
        ]);

        return SlimUtils::renderJSON($response, ['success' => false, 'error' => 'Internal error'], 500);
    }
};

// Checks the installation: plugin loaded, account recognized, valid settings.
$app->get('/custom-routes/ping', function (Request $request, Response $response) use ($plugin): Response {
    $user = AuthenticationManager::getCurrentUser();

    return SlimUtils::renderJSON($response, [
        'success' => true,
        'plugin' => $plugin->getId(),
        'version' => $plugin->getVersion(),
        'userId' => (int) $user->getId(),
        'addRecords' => $user->isAddRecordsEnabled(),
        'editRecords' => $user->isEditRecordsEnabled(),
        'addEvent' => $user->isAddEventEnabled(),
        // Capabilities, so a calling application can enable a feature as soon as the plugin
        // is installed, without comparing version numbers or probing a route for a 404.
        'noteEdit' => true,
        'configured' => $plugin->isConfigured(),
        'configurationError' => $plugin->getConfigurationError(),
        'excludedGroup' => $plugin->getExcludedGroupName(),
        'excludedPersons' => count($plugin->getExcludedPersonIds() ?? []),
        'doNotEmailPropertyId' => (int) SystemConfig::getValue('iDoNotEmailPropertyId') ?: null,
        'doNotSmsPropertyId' => (int) SystemConfig::getValue('iDoNotSmsPropertyId') ?: null,
    ]);
});

// Options of a ChurchCRM list (1 classification, 2 family role…).
$app->get('/custom-routes/lists/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($controller, $respond): Response {
    return $respond($request, $response, fn () => $controller->listOptions((int) $args['id']));
});

// Calendar events: creation (returning the ID), update, deletion.
$app->post('/custom-routes/events', function (Request $request, Response $response) use ($controller, $respond): Response {
    return $respond($request, $response, fn () => $controller->createEvent($request->getParsedBody()));
});
$app->patch('/custom-routes/events/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($controller, $respond): Response {
    return $respond($request, $response, fn () => $controller->updateEvent((int) $args['id'], $request->getParsedBody()));
});
$app->delete('/custom-routes/events/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($controller, $respond): Response {
    return $respond($request, $response, fn () => $controller->deleteEvent((int) $args['id']));
});

// Attendance of an event (all checked-in persons, not only linked groups).
$app->get('/custom-routes/events/{id:[0-9]+}/attendance', function (Request $request, Response $response, array $args) use ($controller, $respond): Response {
    return $respond($request, $response, fn () => $controller->eventAttendance((int) $args['id']));
});

// Replaces the text of a public note, whoever wrote it (the ChurchCRM API
// reserves this to the author of the note or to an administrator).
$app->patch('/custom-routes/person/{id:[0-9]+}/note/{noteId:[0-9]+}', function (Request $request, Response $response, array $args) use ($controller, $respond): Response {
    return $respond($request, $response, fn () => $controller->updateNote((int) $args['id'], (int) $args['noteId'], $request->getParsedBody()));
});

// "Do not contact by email / SMS" properties of a person.
$app->patch('/custom-routes/person/{id:[0-9]+}/properties', function (Request $request, Response $response, array $args) use ($controller, $respond): Response {
    return $respond($request, $response, fn () => $controller->updateProperties((int) $args['id'], $request->getParsedBody()));
});

// Full lists, without the members of the excluded group.
$app->get('/custom-routes/persons', function (Request $request, Response $response) use ($controller, $respond): Response {
    return $respond($request, $response, fn () => $controller->list('person'));
});

$app->get('/custom-routes/families', function (Request $request, Response $response) use ($controller, $respond): Response {
    return $respond($request, $response, fn () => $controller->list('family'));
});

foreach (['person', 'family'] as $kind) {
    $app->post("/custom-routes/{$kind}", function (Request $request, Response $response) use ($controller, $respond, $kind): Response {
        return $respond($request, $response, fn () => $controller->create($kind, $request->getParsedBody()));
    });

    $app->patch("/custom-routes/{$kind}/{id:[0-9]+}", function (Request $request, Response $response, array $args) use ($controller, $respond, $kind): Response {
        return $respond($request, $response, fn () => $controller->update($kind, (int) $args['id'], $request->getParsedBody()));
    });

    $app->get("/custom-routes/{$kind}/{id:[0-9]+}/custom", function (Request $request, Response $response, array $args) use ($controller, $respond, $kind): Response {
        return $respond($request, $response, fn () => $controller->readCustom($kind, (int) $args['id']));
    });

    $app->patch("/custom-routes/{$kind}/{id:[0-9]+}/custom", function (Request $request, Response $response, array $args) use ($controller, $respond, $kind): Response {
        return $respond($request, $response, fn () => $controller->updateCustom($kind, (int) $args['id'], $request->getParsedBody()));
    });
}
