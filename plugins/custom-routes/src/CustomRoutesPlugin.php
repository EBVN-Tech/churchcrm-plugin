<?php

namespace ChurchCRM\Plugins\CustomRoutes;

use ChurchCRM\Plugin\AbstractPlugin;

/**
 * Custom Routes plugin.
 *
 * Exposes under /plugins/custom-routes/ custom routes that are missing from
 * the ChurchCRM 7.8.0 API (persons, families, custom fields, events,
 * attendance), called by an external application with x-api-key.
 * A route is added when a need arises.
 * Specification: docs/SPEC.md.
 */
class CustomRoutesPlugin extends AbstractPlugin
{
    /** Default name of the custom fields that hold the external ID. */
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
        return 'Custom routes that the ChurchCRM API does not provide, for an external application.';
    }

    public function boot(): void
    {
    }

    /**
     * Code (c1, c2…) of the custom field that holds the external ID,
     * or null if it does not exist or if several fields have that name.
     */
    public function getExternalIdCode(string $kind): ?string
    {
        $setting = $kind === 'person' ? 'personExternalIdField' : 'familyExternalIdField';
        $name = trim($this->getConfigValue($setting)) ?: self::DEFAULT_EXTERNAL_ID_FIELD;

        return CustomFields::codeByName($kind, $name);
    }

    /** Name of the ChurchCRM group whose members are never returned (empty: none). */
    public function getExcludedGroupName(): string
    {
        return trim($this->getConfigValue('excludedGroupName'));
    }

    /**
     * Persons in the excluded group (technical accounts…), or null if a group
     * is set but not found: in that case, refusing to answer is better than
     * returning records that should have been hidden.
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
            $missing[] = 'person';
        }
        if ($this->getExternalIdCode('family') === null) {
            $missing[] = 'family';
        }
        if (!empty($missing)) {
            $errors[] = 'External ID custom field not found or duplicated ('
                . implode(', ', $missing) . ').';
        }
        if ($this->getExcludedPersonIds() === null) {
            $errors[] = 'Excluded group not found or duplicated.';
        }

        return empty($errors) ? null : implode(' ', $errors);
    }
}
