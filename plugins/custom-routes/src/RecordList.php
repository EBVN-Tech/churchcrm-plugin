<?php

namespace ChurchCRM\Plugins\CustomRoutes;

use ChurchCRM\dto\SystemConfig;
use ChurchCRM\model\ChurchCRM\FamilyQuery;
use ChurchCRM\model\ChurchCRM\GroupQuery;
use ChurchCRM\model\ChurchCRM\Person2group2roleP2g2rQuery;
use ChurchCRM\model\ChurchCRM\PersonQuery;
use ChurchCRM\model\ChurchCRM\RecordPropertyQuery;

/**
 * Full lists of persons and families, with only the fields useful to a
 * list screen. No pagination: a few hundred records fit in one response.
 * No custom field (IBAN…): those are read record by record via …/custom.
 */
class RecordList
{
    /**
     * @param int[] $excludedIds persons never to return
     *
     * @return array<int, array<string, mixed>>
     */
    public static function persons(array $excludedIds): array
    {
        $excluded = array_flip($excludedIds);
        $doNotEmail = self::recordsWithProperty('iDoNotEmailPropertyId');
        $doNotSms = self::recordsWithProperty('iDoNotSmsPropertyId');

        $rows = [];
        foreach (PersonQuery::create()->orderByLastName()->orderByFirstName()->find() as $person) {
            $id = (int) $person->getId();
            if (isset($excluded[$id])) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'firstName' => $person->getFirstName(),
                'lastName' => $person->getLastName(),
                'familyId' => (int) $person->getFamId(),
                'familyRoleId' => (int) $person->getFmrId(),
                'classificationId' => (int) $person->getClsId(),
                'active' => $person->getDateDeactivated() === null,
                'email' => $person->getEmail(),
                'cellPhone' => $person->getCellPhone(),
                'birthDay' => (int) $person->getBirthDay(),
                'birthMonth' => (int) $person->getBirthMonth(),
                'birthYear' => $person->getBirthYear() === null ? null : (int) $person->getBirthYear(),
                'doNotEmail' => isset($doNotEmail[$id]),
                'doNotSms' => isset($doNotSms[$id]),
            ];
        }

        return $rows;
    }

    /**
     * Families with their member count (excluded persons not counted). A
     * family whose members are all excluded is not returned.
     *
     * @param int[] $excludedIds
     *
     * @return array<int, array<string, mixed>>
     */
    public static function families(array $excludedIds): array
    {
        $excluded = array_flip($excludedIds);
        $counts = [];
        $excludedCounts = [];
        foreach (PersonQuery::create()->select(['Id', 'FamId'])->find() as $row) {
            $famId = (int) $row['FamId'];
            if ($famId === 0) {
                continue;
            }
            if (isset($excluded[(int) $row['Id']])) {
                $excludedCounts[$famId] = ($excludedCounts[$famId] ?? 0) + 1;
            } else {
                $counts[$famId] = ($counts[$famId] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach (FamilyQuery::create()->orderByName()->find() as $family) {
            $id = (int) $family->getId();
            if (!isset($counts[$id]) && isset($excludedCounts[$id])) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'name' => $family->getName(),
                'address1' => $family->getAddress1(),
                'address2' => $family->getAddress2(),
                'zip' => $family->getZip(),
                'city' => $family->getCity(),
                'homePhone' => $family->getHomePhone(),
                'email' => $family->getEmail(),
                'active' => $family->getDateDeactivated() === null,
                'memberCount' => $counts[$id] ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * IDs of the members of the named group, or [] if the name is empty.
     *
     * @return int[]|null null if a name is set but no group has it
     */
    public static function groupMemberIds(string $groupName): ?array
    {
        if ($groupName === '') {
            return [];
        }
        $groups = GroupQuery::create()->filterByName($groupName)->find();
        if (count($groups) !== 1) {
            return null;
        }
        $ids = [];
        foreach (Person2group2roleP2g2rQuery::create()->filterByGroupId($groups[0]->getId())->find() as $membership) {
            $ids[] = (int) $membership->getPersonId();
        }

        return $ids;
    }

    /**
     * Persons that have the property designated by a ChurchCRM setting
     * (iDoNotEmailPropertyId, iDoNotSmsPropertyId): id => true.
     *
     * @return array<int, true>
     */
    private static function recordsWithProperty(string $configName): array
    {
        $propertyId = (int) SystemConfig::getValue($configName);
        if ($propertyId <= 0) {
            return [];
        }
        $set = [];
        foreach (RecordPropertyQuery::create()->filterByPropertyId($propertyId)->find() as $record) {
            $set[(int) $record->getRecordId()] = true;
        }

        return $set;
    }
}
