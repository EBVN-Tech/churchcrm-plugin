<?php

namespace ChurchCRM\Plugins\CustomRoutes;

use ChurchCRM\model\ChurchCRM\FamilyQuery;
use ChurchCRM\model\ChurchCRM\ListOptionQuery;

/**
 * JSON fields accepted for persons and families, their rules and the
 * matching Propel setter.
 */
class RecordSchema
{
    /** ChurchCRM list of classifications (member, visitor…). */
    private const LIST_CLASSIFICATION = 1;

    /** ChurchCRM list of family roles. */
    private const LIST_FAMILY_ROLE = 2;

    /**
     * Rules: [setter, type, parameter, required].
     * Types: text (parameter = max length), email (max length),
     * int (parameter = [min, max]), date, year, familyId, listOption
     * (parameter = list id).
     */
    private const PERSON = [
        'title'            => ['setTitle', 'text', 50, false],
        'firstName'        => ['setFirstName', 'text', 50, true],
        'middleName'       => ['setMiddleName', 'text', 50, false],
        'lastName'         => ['setLastName', 'text', 50, true],
        'suffix'           => ['setSuffix', 'text', 50, false],
        'gender'           => ['setGender', 'int', [0, 2], false],
        'birthDay'         => ['setBirthDay', 'int', [0, 31], false],
        'birthMonth'       => ['setBirthMonth', 'int', [0, 12], false],
        'birthYear'        => ['setBirthYear', 'year', null, false],
        'email'            => ['setEmail', 'email', 50, false],
        'workEmail'        => ['setWorkEmail', 'email', 50, false],
        'cellPhone'        => ['setCellPhone', 'text', 30, false],
        'homePhone'        => ['setHomePhone', 'text', 30, false],
        'workPhone'        => ['setWorkPhone', 'text', 30, false],
        'address1'         => ['setAddress1', 'text', 50, false],
        'address2'         => ['setAddress2', 'text', 50, false],
        'city'             => ['setCity', 'text', 50, false],
        'state'            => ['setState', 'text', 50, false],
        'zip'              => ['setZip', 'text', 50, false],
        'country'          => ['setCountry', 'text', 50, false],
        'familyId'         => ['setFamId', 'familyId', null, false],
        'familyRoleId'     => ['setFmrId', 'listOption', self::LIST_FAMILY_ROLE, false],
        'classificationId' => ['setClsId', 'listOption', self::LIST_CLASSIFICATION, false],
        'membershipDate'   => ['setMembershipDate', 'date', null, false],
        'friendDate'       => ['setFriendDate', 'date', null, false],
    ];

    private const FAMILY = [
        'name'        => ['setName', 'text', 50, true],
        'address1'    => ['setAddress1', 'text', 255, false],
        'address2'    => ['setAddress2', 'text', 255, false],
        'city'        => ['setCity', 'text', 50, false],
        'state'       => ['setState', 'text', 50, false],
        'zip'         => ['setZip', 'text', 50, false],
        'country'     => ['setCountry', 'text', 50, false],
        'homePhone'   => ['setHomePhone', 'text', 30, false],
        'email'       => ['setEmail', 'email', 100, false],
        'weddingDate' => ['setWeddingdate', 'date', null, false],
    ];

    /**
     * Validates a JSON body and returns [setter => normalized value].
     *
     * @param array<string, mixed> $body without the externalId key
     *
     * @return array<string, mixed>
     *
     * @throws HttpException 422 if a field is unknown or invalid
     */
    public static function validate(string $kind, array $body, bool $isCreate): array
    {
        $rules = $kind === 'person' ? self::PERSON : self::FAMILY;
        $errors = [];
        $values = [];

        foreach ($body as $key => $value) {
            if (!isset($rules[$key])) {
                $errors[$key] = 'unknown field';
                continue;
            }
            [$setter, $type, $param, $required] = $rules[$key];
            $error = null;
            $normalized = self::normalize($type, $param, $required, $value, $error);
            if ($error !== null) {
                $errors[$key] = $error;
                continue;
            }
            $values[$setter] = $normalized;
        }

        if ($isCreate) {
            foreach ($rules as $key => [, , , $required]) {
                if ($required && !array_key_exists($key, $body)) {
                    $errors[$key] = 'required';
                }
            }
        }

        if ($kind === 'person' && empty($errors)) {
            self::checkBirthDate($body, $errors);
        }

        if (!empty($errors)) {
            throw new HttpException(422, 'Invalid data', $errors);
        }

        return $values;
    }

    /**
     * @param mixed $param
     * @param mixed $value
     *
     * @return mixed
     */
    private static function normalize(string $type, $param, bool $required, $value, ?string &$error)
    {
        if ($value === null) {
            if ($required) {
                $error = 'cannot be empty';

                return null;
            }

            // NULL clears the field; NOT NULL integer columns get 0.
            return in_array($type, ['int', 'familyId', 'listOption'], true) ? 0 : null;
        }

        switch ($type) {
            case 'text':
            case 'email':
                if (!is_string($value)) {
                    $error = 'text expected';

                    return null;
                }
                $value = trim($value);
                if ($value !== strip_tags($value)) {
                    $error = 'HTML tags not allowed';

                    return null;
                }
                if ($value === '') {
                    if ($required) {
                        $error = 'cannot be empty';
                    }

                    return null;
                }
                if (mb_strlen($value) > $param) {
                    $error = "{$param} characters maximum";

                    return null;
                }
                if ($type === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $error = 'invalid format';

                    return null;
                }

                return $value;

            case 'int':
                if (!is_int($value) || $value < $param[0] || $value > $param[1]) {
                    $error = "integer from {$param[0]} to {$param[1]} expected";

                    return null;
                }

                return $value;

            case 'year':
                $max = (int) date('Y');
                if (!is_int($value) || $value < 1900 || $value > $max) {
                    $error = "year from 1900 to {$max} expected";

                    return null;
                }

                return $value;

            case 'date':
                if (!is_string($value) || !self::isIsoDate($value)) {
                    $error = 'YYYY-MM-DD date expected';

                    return null;
                }

                return $value;

            case 'familyId':
                if (!is_int($value) || $value < 0) {
                    $error = 'family ID expected';

                    return null;
                }
                if ($value > 0 && FamilyQuery::create()->findPk($value) === null) {
                    $error = 'family not found';

                    return null;
                }

                return $value;

            case 'listOption':
                if (!is_int($value) || $value < 0) {
                    $error = 'option ID expected';

                    return null;
                }
                if ($value > 0 && !self::listOptionExists($param, $value)) {
                    $error = 'option not found';

                    return null;
                }

                return $value;
        }

        $error = 'unsupported type';

        return null;
    }

    public static function isIsoDate(string $value): bool
    {
        $date = \DateTime::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    public static function listOptionExists(int $listId, int $optionId): bool
    {
        return ListOptionQuery::create()
            ->filterById($listId)
            ->filterByOptionId($optionId)
            ->count() > 0;
    }

    /**
     * Rejects an impossible day (February 31…) when day, month and year
     * are all sent.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $errors
     */
    private static function checkBirthDate(array $body, array &$errors): void
    {
        $day = $body['birthDay'] ?? 0;
        $month = $body['birthMonth'] ?? 0;
        $year = $body['birthYear'] ?? null;
        if ($day > 0 && $month > 0 && !checkdate($month, $day, $year ?? 2000)) {
            $errors['birthDay'] = 'impossible birth date';
        }
    }
}
