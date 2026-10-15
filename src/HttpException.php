<?php

namespace ChurchCRM\Plugins\PeopleSync;

/**
 * Erreur renvoyée au client avec un code HTTP.
 *
 * Le message et les erreurs par champ ne contiennent jamais une valeur
 * reçue : seulement des noms de champs et des règles.
 */
class HttpException extends \RuntimeException
{
    /** @param array<string, string> $fields */
    public function __construct(
        private int $status,
        string $message,
        private array $fields = []
    ) {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function getFields(): array
    {
        return $this->fields;
    }
}
