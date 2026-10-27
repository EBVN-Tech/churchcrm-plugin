<?php

namespace ChurchCRM\Plugins\CustomRoutes;

use ChurchCRM\model\ChurchCRM\FamilyCustomMasterQuery;
use ChurchCRM\model\ChurchCRM\ListOptionQuery;
use ChurchCRM\model\ChurchCRM\PersonCustomMasterQuery;
use ChurchCRM\model\ChurchCRM\User;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * Custom fields of persons and families.
 *
 * Values live in person_custom / family_custom, one column per field
 * (c1, c2…) added dynamically by ChurchCRM: they are not part of the
 * Propel schema, hence the prepared SQL queries, as in ChurchCRM's
 * CSV import.
 */
class CustomFields
{
    /** Rejected types: season (7) and person from a group (9). */
    private const UNSUPPORTED_TYPES = [7, 9];

    /** ChurchCRM list of custom field security levels. */
    private const LIST_SECURITY = 5;

    private const TABLES = [
        'person' => ['values' => 'person_custom', 'key' => 'per_ID', 'records' => 'person_per'],
        'family' => ['values' => 'family_custom', 'key' => 'fam_ID', 'records' => 'family_fam'],
    ];

    /**
     * Field definitions: code => [name, typeId, security, special].
     *
     * @return array<string, array{name: string, typeId: int, security: int, special: int}>
     */
    public static function definitions(string $kind): array
    {
        $defs = [];
        if ($kind === 'person') {
            foreach (PersonCustomMasterQuery::create()->find() as $field) {
                $defs[(string) $field->getId()] = [
                    'name' => (string) $field->getName(),
                    'typeId' => (int) $field->getTypeId(),
                    'security' => (int) $field->getFieldSecurity(),
                    'special' => (int) $field->getSpecial(),
                ];
            }
        } else {
            foreach (FamilyCustomMasterQuery::create()->find() as $field) {
                $defs[(string) $field->getField()] = [
                    'name' => (string) $field->getName(),
                    'typeId' => (int) $field->getTypeId(),
                    'security' => (int) $field->getFieldSecurity(),
                    'special' => (int) $field->getCustomSpecial(),
                ];
            }
        }

        // Codes are used as SQL column names: keep only c<digits>.
        return array_filter($defs, fn ($code) => preg_match('/^c\d+$/', $code) === 1, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Can the account read and write this field?
     *
     * custom_FieldSec is an option number from list 5 (1 = bAll,
     * 2 = bAdmin, 4 = bEditRecords…). It must be translated into a name
     * before User::isEnabledSecurity(), as ChurchCRM's pages do.
     */
    public static function isAllowed(User $user, int $security): bool
    {
        $option = ListOptionQuery::create()
            ->filterById(self::LIST_SECURITY)
            ->filterByOptionId($security)
            ->findOne();

        return $option !== null && $user->isEnabledSecurity((string) $option->getOptionName());
    }

    /**
     * Code of the field with the given name, or null if it does not exist
     * or is not unique.
     */
    public static function codeByName(string $kind, string $name): ?string
    {
        $codes = [];
        foreach (self::definitions($kind) as $code => $def) {
            if ($def['name'] === $name) {
                $codes[] = $code;
            }
        }

        return count($codes) === 1 ? $codes[0] : null;
    }

    /**
     * ID of the existing record that holds this external ID.
     */
    public static function findByExternalId(ConnectionInterface $con, string $kind, string $code, string $externalId): ?int
    {
        $t = self::TABLES[$kind];
        $sql = sprintf(
            'SELECT v.`%2$s` FROM `%1$s` v INNER JOIN `%3$s` r ON r.`%2$s` = v.`%2$s` WHERE v.`%4$s` = ? ORDER BY v.`%2$s` DESC LIMIT 1',
            $t['values'],
            $t['key'],
            $t['records'],
            $code
        );
        $stmt = $con->prepare($sql);
        $stmt->execute([$externalId]);
        // fetch() rather than fetchColumn(): depending on the driver, an
        // empty lookup returns false or null, and (int) null would be 0.
        $row = $stmt->fetch(\PDO::FETCH_NUM);
        $id = is_array($row) ? (int) $row[0] : 0;

        return $id > 0 ? $id : null;
    }

    /**
     * Validates a {code: value} body and returns {code: SQL value}.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, string|null>
     *
     * @throws HttpException 403 if a field is protected for this account,
     *                       422 if a field is unknown or invalid
     */
    public static function validate(string $kind, array $body, string $externalIdCode, User $user): array
    {
        if (empty($body)) {
            throw new HttpException(422, 'No field sent');
        }

        $defs = self::definitions($kind);
        $errors = [];
        $forbidden = [];
        $values = [];

        foreach ($body as $code => $value) {
            $code = (string) $code;
            if (!isset($defs[$code])) {
                $errors[$code] = 'unknown field';
                continue;
            }
            if ($code === $externalIdCode) {
                $errors[$code] = 'external ID cannot be changed';
                continue;
            }
            $def = $defs[$code];
            if (!self::isAllowed($user, $def['security'])) {
                $forbidden[] = $code;
                continue;
            }
            if (in_array($def['typeId'], self::UNSUPPORTED_TYPES, true)) {
                $errors[$code] = 'unsupported field type';
                continue;
            }
            $error = null;
            $values[$code] = self::normalize($def, $value, $error);
            if ($error !== null) {
                $errors[$code] = $error;
            }
        }

        if (!empty($forbidden)) {
            throw new HttpException(403, 'Missing permission on these fields', array_fill_keys($forbidden, 'access denied'));
        }
        if (!empty($errors)) {
            throw new HttpException(422, 'Invalid data', $errors);
        }

        return $values;
    }

    /**
     * Reads the fields the account is allowed to see, except the external
     * ID: code => {name, value}.
     *
     * @return array<string, array{name: string, value: string|null}>
     */
    public static function read(ConnectionInterface $con, string $kind, int $recordId, string $externalIdCode, User $user): array
    {
        $defs = array_filter(
            self::definitions($kind),
            fn ($def, $code) => $code !== $externalIdCode && self::isAllowed($user, $def['security']),
            ARRAY_FILTER_USE_BOTH
        );
        if (empty($defs)) {
            return [];
        }

        $t = self::TABLES[$kind];
        $sql = sprintf(
            'SELECT %s FROM `%s` WHERE `%s` = ?',
            implode(', ', array_map(fn ($c) => "`{$c}`", array_keys($defs))),
            $t['values'],
            $t['key']
        );
        $stmt = $con->prepare($sql);
        $stmt->execute([$recordId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $result = [];
        foreach ($defs as $code => $def) {
            $value = $row[$code] ?? null;
            $result[$code] = ['name' => $def['name'], 'value' => $value === null ? null : (string) $value];
        }

        return $result;
    }

    /**
     * Writes the values (INSERT … ON DUPLICATE KEY UPDATE).
     *
     * @param array<string, string|null> $values already validated codes
     */
    public static function write(ConnectionInterface $con, string $kind, int $recordId, array $values): void
    {
        if (empty($values)) {
            return;
        }
        $t = self::TABLES[$kind];
        $columns = array_keys($values);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`, %s) VALUES (?%s) ON DUPLICATE KEY UPDATE %s',
            $t['values'],
            $t['key'],
            implode(', ', array_map(fn ($c) => "`{$c}`", $columns)),
            str_repeat(', ?', count($columns)),
            implode(', ', array_map(fn ($c) => "`{$c}` = VALUES(`{$c}`)", $columns))
        );
        $stmt = $con->prepare($sql);
        $stmt->execute(array_merge([$recordId], array_values($values)));
    }

    /**
     * @param array{typeId: int, special: int} $def
     * @param mixed                            $value
     */
    private static function normalize(array $def, $value, ?string &$error): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        switch ($def['typeId']) {
            case 1: // True / false: ENUM('false','true')
                if (!is_bool($value)) {
                    $error = 'true or false expected';

                    return null;
                }

                return $value ? 'true' : 'false';

            case 2: // Date
                if (!is_string($value) || !RecordSchema::isIsoDate($value)) {
                    $error = 'YYYY-MM-DD date expected';

                    return null;
                }

                return $value;

            case 3: // Text 50
            case 4: // Text 100
            case 5: // Long text
            case 11: // Phone
                $max = [3 => 50, 4 => 100, 5 => 65535, 11 => 30][$def['typeId']];
                if (!is_string($value)) {
                    $error = 'text expected';

                    return null;
                }
                $value = trim($value);
                if ($value !== strip_tags($value)) {
                    $error = 'HTML tags not allowed';

                    return null;
                }
                if (mb_strlen($value) > $max) {
                    $error = "{$max} characters maximum";

                    return null;
                }

                return $value === '' ? null : $value;

            case 6: // Year
                if (!is_int($value) || $value < 1000 || $value > 9999) {
                    $error = '4-digit year expected';

                    return null;
                }

                return (string) $value;

            case 8: // Integer
                if (!is_int($value)) {
                    $error = 'integer expected';

                    return null;
                }

                return (string) $value;

            case 10: // Money
                if (!is_int($value) && !is_float($value)) {
                    $error = 'number expected';

                    return null;
                }

                return number_format((float) $value, 2, '.', '');

            case 12: // Drop-down list: option from the custom_Special list
                if (!is_int($value) || !RecordSchema::listOptionExists($def['special'], $value)) {
                    $error = 'option not found';

                    return null;
                }

                return (string) $value;
        }

        $error = 'unsupported field type';

        return null;
    }
}
