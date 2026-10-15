<?php

namespace ChurchCRM\Plugins\PeopleSync;

use ChurchCRM\Plugin\AbstractPlugin;

/**
 * Plugin People Sync.
 *
 * Expose sous /plugins/people-sync/ les routes d'écriture absentes de l'API
 * ChurchCRM 7.8.0 (création et modification de personnes, familles et
 * champs personnalisés), appelées par l'intranet avec x-api-key.
 *
 * Version 0.2.0 : routes de test seulement, aucune écriture en base.
 */
class PeopleSyncPlugin extends AbstractPlugin
{
    private static ?PeopleSyncPlugin $instance = null;

    public function __construct(string $basePath = '')
    {
        parent::__construct($basePath);
        self::$instance = $this;
    }

    public static function getInstance(): ?PeopleSyncPlugin
    {
        return self::$instance;
    }

    public function getId(): string
    {
        return 'people-sync';
    }

    public function getName(): string
    {
        return 'People Sync';
    }

    public function getDescription(): string
    {
        return 'Routes d\'écriture des personnes et familles pour l\'intranet EBVN.';
    }

    public function getVersion(): string
    {
        return '0.2.0';
    }

    public function boot(): void
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
