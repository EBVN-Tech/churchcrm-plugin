<?php

namespace ChurchCRM\Plugins\CustomRoutes;

/**
 * Error returned to the client with an HTTP code.
 *
 * The message and the per-field errors never contain a received value:
 * only field names and rules.
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
