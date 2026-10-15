<?php

namespace ChurchCRM\Plugins\PeopleSync;

use ChurchCRM\model\ChurchCRM\FamilyCustomMasterQuery;
use ChurchCRM\model\ChurchCRM\ListOptionQuery;
use ChurchCRM\model\ChurchCRM\PersonCustomMasterQuery;
use ChurchCRM\model\ChurchCRM\User;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * Champs personnalisés des personnes et familles.
 *
 * Les valeurs vivent dans person_custom / family_custom, une colonne par
 * champ (c1, c2…) ajoutée dynamiquement par ChurchCRM : elles ne font pas
 * partie du schéma Propel, d'où les requêtes SQL préparées, comme dans
 * l'import CSV de ChurchCRM.
 */
class CustomFields
{
    /** Types refusés : saison (7) et personne d'un groupe (9). */
    private const UNSUPPORTED_TYPES = [7, 9];

    /** Liste ChurchCRM des niveaux de sécurité des champs personnalisés. */
    private const LIST_SECURITY = 5;

    private const TABLES = [
        'person' => ['values' => 'person_custom', 'key' => 'per_ID', 'records' => 'person_per'],
        'family' => ['values' => 'family_custom', 'key' => 'fam_ID', 'records' => 'family_fam'],
    ];

    /**
     * Définitions des champs : code => [name, typeId, security, special].
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

        // Les codes servent de noms de colonnes SQL : ne garder que c<chiffres>.
        return array_filter($defs, fn ($code) => preg_match('/^c\d+$/', $code) === 1, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Le compte peut-il lire et écrire ce champ ?
     *
     * custom_FieldSec est un numéro d'option de la liste 5 (1 = bAll,
     * 2 = bAdmin, 4 = bEditRecords…). Il faut le traduire en nom avant
     * User::isEnabledSecurity(), comme le font les pages de ChurchCRM.
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
     * Code du champ portant le nom donné, ou null s'il n'existe pas ou
     * n'est pas unique.
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
     * Identifiant de la fiche existante portant cet identifiant externe.
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
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Valide un corps {code: valeur} et renvoie {code: valeur SQL}.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, string|null>
     *
     * @throws HttpException 403 si un champ est protégé pour ce compte,
     *                       422 si un champ est inconnu ou invalide
     */
    public static function validate(string $kind, array $body, string $externalIdCode, User $user): array
    {
        if (empty($body)) {
            throw new HttpException(422, 'Aucun champ envoyé');
        }

        $defs = self::definitions($kind);
        $errors = [];
        $forbidden = [];
        $values = [];

        foreach ($body as $code => $value) {
            $code = (string) $code;
            if (!isset($defs[$code])) {
                $errors[$code] = 'champ inconnu';
                continue;
            }
            if ($code === $externalIdCode) {
                $errors[$code] = 'identifiant externe non modifiable';
                continue;
            }
            $def = $defs[$code];
            if (!self::isAllowed($user, $def['security'])) {
                $forbidden[] = $code;
                continue;
            }
            if (in_array($def['typeId'], self::UNSUPPORTED_TYPES, true)) {
                $errors[$code] = 'type de champ non géré';
                continue;
            }
            $error = null;
            $values[$code] = self::normalize($def, $value, $error);
            if ($error !== null) {
                $errors[$code] = $error;
            }
        }

        if (!empty($forbidden)) {
            throw new HttpException(403, 'Droit manquant sur ces champs', array_fill_keys($forbidden, 'accès refusé'));
        }
        if (!empty($errors)) {
            throw new HttpException(422, 'Données invalides', $errors);
        }

        return $values;
    }

    /**
     * Lit les champs que le compte a le droit de voir, sauf l'identifiant
     * externe : code => {name, value}.
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
     * Écrit les valeurs (INSERT … ON DUPLICATE KEY UPDATE).
     *
     * @param array<string, string|null> $values codes déjà validés
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
            case 1: // Vrai / faux : ENUM('false','true')
                if (!is_bool($value)) {
                    $error = 'true ou false attendu';

                    return null;
                }

                return $value ? 'true' : 'false';

            case 2: // Date
                if (!is_string($value) || !RecordSchema::isIsoDate($value)) {
                    $error = 'date AAAA-MM-JJ attendue';

                    return null;
                }

                return $value;

            case 3: // Texte 50
            case 4: // Texte 100
            case 5: // Texte long
            case 11: // Téléphone
                $max = [3 => 50, 4 => 100, 5 => 65535, 11 => 30][$def['typeId']];
                if (!is_string($value)) {
                    $error = 'texte attendu';

                    return null;
                }
                $value = trim($value);
                if ($value !== strip_tags($value)) {
                    $error = 'balises HTML interdites';

                    return null;
                }
                if (mb_strlen($value) > $max) {
                    $error = "{$max} caractères maximum";

                    return null;
                }

                return $value === '' ? null : $value;

            case 6: // Année
                if (!is_int($value) || $value < 1000 || $value > 9999) {
                    $error = 'année sur 4 chiffres attendue';

                    return null;
                }

                return (string) $value;

            case 8: // Nombre entier
                if (!is_int($value)) {
                    $error = 'entier attendu';

                    return null;
                }

                return (string) $value;

            case 10: // Montant
                if (!is_int($value) && !is_float($value)) {
                    $error = 'nombre attendu';

                    return null;
                }

                return number_format((float) $value, 2, '.', '');

            case 12: // Liste déroulante : option de la liste custom_Special
                if (!is_int($value) || !RecordSchema::listOptionExists($def['special'], $value)) {
                    $error = 'option introuvable';

                    return null;
                }

                return (string) $value;
        }

        $error = 'type de champ non géré';

        return null;
    }
}
