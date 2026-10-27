<?php

namespace ChurchCRM\Plugins\CustomRoutes;

use ChurchCRM\Plugin\AbstractPlugin;

/**
 * Plugin Custom Routes.
 *
 * Expose sous /plugins/custom-routes/ des routes sur mesure, absentes de
 * l'API ChurchCRM 7.8.0 (personnes, familles, champs personnalisés,
 * événements, présences), appelées par une application externe avec
 * x-api-key. On y ajoute une route quand un besoin apparaît.
 * Spécification : docs/SPEC.md.
 */
class CustomRoutesPlugin extends AbstractPlugin
{
    /** Nom par défaut des champs personnalisés qui portent l'identifiant externe. */
    private const DEFAULT_EXTERNAL_ID_FIELD = 'ext-id';

    private static ?CustomRoutesPlugin $instance = null;

    public function __construct(string $basePath = '')
    {
        parent::__construct($basePath);
        self::$instance = $this;
    }

    public static function getInstance(): ?CustomRoutesPlugin
    {
        return self::$instance;
    }

    public function getId(): string
    {
        return 'custom-routes';
    }

    public function getName(): string
    {
        return 'Custom Routes';
    }

    public function getDescription(): string
    {
        return 'Routes sur mesure que l\'API ChurchCRM ne fournit pas, pour une application externe.';
    }

    public function getVersion(): string
    {
        return '0.8.0';
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

    /** Nom du groupe ChurchCRM dont les membres ne sont jamais renvoyés (vide : aucun). */
    public function getExcludedGroupName(): string
    {
        return trim($this->getConfigValue('excludedGroupName'));
    }

    /**
     * Personnes du groupe exclu (comptes techniques…), ou null si un groupe
     * est réglé mais introuvable : dans ce cas, mieux vaut refuser de
     * répondre que renvoyer des fiches qui auraient dû être masquées.
     *
     * @return int[]|null
     */
    public function getExcludedPersonIds(): ?array
    {
        return RecordList::groupMemberIds($this->getExcludedGroupName());
    }

    public function isConfigured(): bool
    {
        return $this->getConfigurationError() === null;
    }

    public function getConfigurationError(): ?string
    {
        $errors = [];
        $missing = [];
        if ($this->getExternalIdCode('person') === null) {
            $missing[] = 'personne';
        }
        if ($this->getExternalIdCode('family') === null) {
            $missing[] = 'famille';
        }
        if (!empty($missing)) {
            $errors[] = 'Champ personnalisé d\'identifiant externe introuvable ou en double ('
                . implode(', ', $missing) . ').';
        }
        if ($this->getExcludedPersonIds() === null) {
            $errors[] = 'Groupe exclu introuvable ou en double.';
        }

        return empty($errors) ? null : implode(' ', $errors);
    }
}
