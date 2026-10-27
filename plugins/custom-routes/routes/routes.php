<?php

// Inclus par PluginManager::registerPluginRoutes() avec $app dans la portée.
// Le basePath de l'application est /plugins : '/custom-routes/ping' répond
// donc sur /plugins/custom-routes/ping. AuthMiddleware (global) authentifie
// par x-api-key avant d'arriver ici. Spécification : docs/SPEC.md.

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
 * Exécute une action et met en forme la réponse JSON. Les logs ne
 * contiennent que la route, la méthode et le type d'erreur : jamais une
 * valeur reçue ni le message d'une exception (une erreur SQL peut citer
 * une valeur).
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
        LoggerUtils::getAppLogger()->error('custom-routes : erreur interne', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod(),
            'exception' => get_class($e),
            'at' => basename($e->getFile()) . ':' . $e->getLine(),
        ]);

        return SlimUtils::renderJSON($response, ['success' => false, 'error' => 'Erreur interne'], 500);
    }
};

// Vérifie l'installation : plugin chargé, compte reconnu, réglages valides.
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
        'configured' => $plugin->isConfigured(),
        'configurationError' => $plugin->getConfigurationError(),
        'excludedGroup' => $plugin->getExcludedGroupName(),
        'excludedPersons' => count($plugin->getExcludedPersonIds() ?? []),
        'doNotEmailPropertyId' => (int) SystemConfig::getValue('iDoNotEmailPropertyId') ?: null,
        'doNotSmsPropertyId' => (int) SystemConfig::getValue('iDoNotSmsPropertyId') ?: null,
    ]);
});

// Options d'une liste ChurchCRM (1 classification, 2 rôle familial…).
$app->get('/custom-routes/lists/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($controller, $respond): Response {
    return $respond($request, $response, fn () => $controller->listOptions((int) $args['id']));
});

// Événements du calendrier : création (avec identifiant en retour), modification, suppression.
$app->post('/custom-routes/events', function (Request $request, Response $response) use ($controller, $respond): Response {
    return $respond($request, $response, fn () => $controller->createEvent($request->getParsedBody()));
});
$app->patch('/custom-routes/events/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($controller, $respond): Response {
    return $respond($request, $response, fn () => $controller->updateEvent((int) $args['id'], $request->getParsedBody()));
});
$app->delete('/custom-routes/events/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($controller, $respond): Response {
    return $respond($request, $response, fn () => $controller->deleteEvent((int) $args['id']));
});

// Présences d'un événement (toutes les personnes pointées, pas seulement les groupes liés).
$app->get('/custom-routes/events/{id:[0-9]+}/attendance', function (Request $request, Response $response, array $args) use ($controller, $respond): Response {
    return $respond($request, $response, fn () => $controller->eventAttendance((int) $args['id']));
});

// Propriétés « Ne pas contacter par e-mail / SMS » d'une personne.
$app->patch('/custom-routes/person/{id:[0-9]+}/properties', function (Request $request, Response $response, array $args) use ($controller, $respond): Response {
    return $respond($request, $response, fn () => $controller->updateProperties((int) $args['id'], $request->getParsedBody()));
});

// Listes complètes, sans les membres du groupe exclu.
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
