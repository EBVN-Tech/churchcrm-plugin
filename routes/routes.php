<?php

// Inclus par PluginManager::registerPluginRoutes() avec $app dans la portée.
// Le basePath de l'application est /plugins : '/ebvn-sync/ping' répond
// donc sur /plugins/ebvn-sync/ping. AuthMiddleware (global) authentifie
// par x-api-key avant d'arriver ici.

use ChurchCRM\Authentication\AuthenticationManager;
use ChurchCRM\Plugins\EbvnSync\EbvnSyncPlugin;
use ChurchCRM\Slim\Middleware\Request\Auth\EditRecordsRoleAuthMiddleware;
use ChurchCRM\Slim\SlimUtils;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$plugin = EbvnSyncPlugin::getInstance();
if ($plugin === null) {
    return;
}

// Test 1 : une route GET de plugin répond et voit l'utilisateur authentifié.
$app->get('/ebvn-sync/ping', function (Request $request, Response $response) use ($plugin): Response {
    $user = AuthenticationManager::getCurrentUser();

    return SlimUtils::renderJSON($response, [
        'success' => true,
        'plugin' => $plugin->getId(),
        'version' => $plugin->getVersion(),
        'userId' => (int) $user->getId(),
        'editRecords' => $user->isEditRecordsEnabled(),
    ]);
});

// Test 2 : une route POST de plugin reçoit un corps JSON et applique le
// contrôle de rôle EditRecords. Ne renvoie que les noms des clés reçues,
// jamais les valeurs, et n'écrit rien en base.
$app->post('/ebvn-sync/echo', function (Request $request, Response $response): Response {
    $body = $request->getParsedBody();

    return SlimUtils::renderJSON($response, [
        'success' => true,
        'receivedKeys' => is_array($body) ? array_keys($body) : [],
    ]);
})->add(EditRecordsRoleAuthMiddleware::class);
