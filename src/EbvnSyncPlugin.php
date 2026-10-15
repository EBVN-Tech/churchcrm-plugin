<?php

namespace ChurchCRM\Plugins\EbvnSync;

use ChurchCRM\Plugin\AbstractPlugin;

/**
 * Plugin EBVN Sync.
 *
 * Expose sous /plugins/ebvn-sync/ les routes d'écriture absentes de l'API
 * ChurchCRM 7.8.0 (création et modification de personnes, familles et
 * champs personnalisés), appelées par l'intranet avec x-api-key.
 *
 * Version 0.1.0 : routes de test seulement, aucune écriture en base.
 */
class EbvnSyncPlugin extends AbstractPlugin
{
    private static ?EbvnSyncPlugin $instance = null;

    public function __construct(string $basePath = '')
    {
        parent::__construct($basePath);
        self::$instance = $this;
    }

    public static function getInstance(): ?EbvnSyncPlugin
    {
        return self::$instance;
    }

    public function getId(): string
    {
        return 'ebvn-sync';
    }

    public function getName(): string
    {
        return 'EBVN Sync';
    }

    public function getDescription(): string
    {
        return 'Routes d\'écriture des personnes et familles pour l\'intranet EBVN.';
    }

    public function getVersion(): string
    {
        return '0.1.0';
    }

    public function boot(): void
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
