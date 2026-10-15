<?php

namespace ChurchCRM\Plugins\PeopleSync;

use ChurchCRM\Plugin\AbstractPlugin;

/**
 * Plugin People Sync.
 *
 * Expose sous /plugins/people-sync/ les routes d'écriture absentes de l'API
 * ChurchCRM 7.8.0 (création et modification de personnes, familles et
 * champs personnalisés), appelées par une application externe avec
 * x-api-key. Spécification : docs/SPEC.md.
 */
class PeopleSyncPlugin extends AbstractPlugin
{
    /** Nom par défaut des champs personnalisés qui portent l'identifiant externe. */
    private const DEFAULT_EXTERNAL_ID_FIELD = 'ext-id';

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
        return 'Routes d\'écriture des personnes et familles pour une application externe.';
    }

    public function getVersion(): string
    {
        return '0.3.1';
    }

    public function boot(): void
    {
    }

    /**
     * Code (c1, c2…) du champ personnalisé qui porte l'identifiant externe,
     * ou null s'il n'existe pas ou si plusieurs champs portent ce nom.
     */
    public function getExternalIdCode(string $kind): ?string
    {
        $setting = $kind === 'person' ? 'personExternalIdField' : 'familyExternalIdField';
        $name = trim($this->getConfigValue($setting)) ?: self::DEFAULT_EXTERNAL_ID_FIELD;

        return CustomFields::codeByName($kind, $name);
    }

    public function isConfigured(): bool
    {
        return $this->getConfigurationError() === null;
    }

    public function getConfigurationError(): ?string
    {
        $missing = [];
        if ($this->getExternalIdCode('person') === null) {
            $missing[] = 'personne';
        }
        if ($this->getExternalIdCode('family') === null) {
            $missing[] = 'famille';
        }
        if (empty($missing)) {
            return null;
        }

        return 'Champ personnalisé d\'identifiant externe introuvable ou en double ('
            . implode(', ', $missing) . ').';
    }
}
