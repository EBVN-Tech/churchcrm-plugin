<?php

namespace ChurchCRM\Plugins\CustomRoutes;

use ChurchCRM\Authentication\AuthenticationManager;
use ChurchCRM\Exceptions\EventDeleteRefusedException;
use ChurchCRM\model\ChurchCRM\EventAttendQuery;
use ChurchCRM\model\ChurchCRM\EventQuery;
use ChurchCRM\model\ChurchCRM\EventTypeQuery;
use ChurchCRM\Service\EventService;
use ChurchCRM\model\ChurchCRM\Family;
use ChurchCRM\dto\SystemConfig;
use ChurchCRM\model\ChurchCRM\FamilyQuery;
use ChurchCRM\model\ChurchCRM\ListOptionQuery;
use ChurchCRM\model\ChurchCRM\Map\PersonTableMap;
use ChurchCRM\model\ChurchCRM\Person;
use ChurchCRM\model\ChurchCRM\PersonQuery;
use ChurchCRM\model\ChurchCRM\PropertyQuery;
use ChurchCRM\model\ChurchCRM\RecordProperty;
use ChurchCRM\model\ChurchCRM\RecordPropertyQuery;
use ChurchCRM\model\ChurchCRM\User;
use ChurchCRM\Utils\AddressDefaults;
use Propel\Runtime\Propel;

/**
 * Route logic. Each method returns [HTTP code, JSON body] or
 * throws an HttpException.
 */
class SyncController
{
    /** External ID: UUID or simple identifier, 50 characters max (text field 50). */
    private const EXTERNAL_ID_PATTERN = '/^[A-Za-z0-9_-]{1,50}$/';

    public function __construct(private CustomRoutesPlugin $plugin)
    {
    }

    /**
     * @param mixed $body
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function create(string $kind, $body): array
    {
        $user = $this->currentUser();
        if (!$user->isAddRecordsEnabled()) {
            throw new HttpException(403, 'AddRecords permission required');
        }
        $body = $this->requireObject($body);
        $extCode = $this->requireExternalIdCode($kind);

        $externalId = $body['externalId'] ?? null;
        unset($body['externalId']);
        if (!is_string($externalId) || preg_match(self::EXTERNAL_ID_PATTERN, $externalId) !== 1) {
            throw new HttpException(422, 'Invalid data', ['externalId' => 'required, 1 to 50 characters from A-Z a-z 0-9 _ -']);
        }
        $values = RecordSchema::validate($kind, $body, true);

        $con = Propel::getWriteConnection(PersonTableMap::DATABASE_NAME);
        $con->beginTransaction();
        try {
            $existingId = CustomFields::findByExternalId($con, $kind, $extCode, $externalId);
            if ($existingId !== null) {
                $con->commit();

                return [200, ['success' => true, 'id' => $existingId, 'created' => false]];
            }

            $record = $kind === 'person' ? new Person() : new Family();
            if (!array_key_exists('setCountry', $values)) {
                // Like ChurchCRM's editors: default country from the settings.
                $values['setCountry'] = AddressDefaults::country();
            }
            foreach ($values as $setter => $value) {
                $record->$setter($value);
            }
            $record->setDateEntered(date('YmdHis'));
            $record->setEnteredBy($user->getId());
            $record->save($con);

            CustomFields::write($con, $kind, (int) $record->getId(), [$extCode => $externalId]);
            $con->commit();
        } catch (\Throwable $e) {
            $con->rollBack();
            throw $e;
        }

        return [201, ['success' => true, 'id' => (int) $record->getId(), 'created' => true]];
    }

    /**
     * @param mixed $body
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function update(string $kind, int $id, $body): array
    {
        $user = $this->currentUser();
        if (!$user->isEditRecordsEnabled()) {
            throw new HttpException(403, 'EditRecords permission required');
        }
        $body = $this->requireObject($body);
        if (array_key_exists('externalId', $body)) {
            throw new HttpException(422, 'Invalid data', ['externalId' => 'cannot be changed']);
        }
        if (empty($body)) {
            throw new HttpException(422, 'No field sent');
        }
        $record = $this->findRecord($kind, $id);
        $values = RecordSchema::validate($kind, $body, false);

        foreach ($values as $setter => $value) {
            $record->$setter($value);
        }
        $record->setDateLastEdited(date('YmdHis'));
        $record->setEditedBy($user->getId());
        $record->save();

        return [200, ['success' => true, 'id' => $id]];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function readCustom(string $kind, int $id): array
    {
        $user = $this->currentUser();
        $this->findRecord($kind, $id);
        $extCode = $this->requireExternalIdCode($kind);
        $con = Propel::getReadConnection(PersonTableMap::DATABASE_NAME);

        // (object): {} and not [] when no field is readable.
        return [200, ['success' => true, 'id' => $id, 'fields' => (object) CustomFields::read($con, $kind, $id, $extCode, $user)]];
    }

    /**
     * @param mixed $body
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function updateCustom(string $kind, int $id, $body): array
    {
        $user = $this->currentUser();
        if (!$user->isEditRecordsEnabled()) {
            throw new HttpException(403, 'EditRecords permission required');
        }
        $body = $this->requireObject($body);
        $record = $this->findRecord($kind, $id);
        $extCode = $this->requireExternalIdCode($kind);
        $values = CustomFields::validate($kind, $body, $extCode, $user);

        $con = Propel::getWriteConnection(PersonTableMap::DATABASE_NAME);
        $con->beginTransaction();
        try {
            CustomFields::write($con, $kind, $id, $values);
            // Records "edited by" on the record, like ChurchCRM's editors.
            $record->setDateLastEdited(date('YmdHis'));
            $record->setEditedBy($user->getId());
            $record->save($con);
            $con->commit();
        } catch (\Throwable $e) {
            $con->rollBack();
            throw $e;
        }

        return [200, ['success' => true, 'id' => $id, 'updated' => array_keys($values)]];
    }

    /**
     * Full list of persons or families, without the excluded group.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function list(string $kind): array
    {
        $excludedIds = $this->requireExcludedPersonIds();
        $items = $kind === 'person' ? RecordList::persons($excludedIds) : RecordList::families($excludedIds);

        return [200, ['success' => true, 'count' => count($items), 'items' => $items]];
    }

    /**
     * Sets or removes on a person the "Do not contact" properties
     * set in ChurchCRM (iDoNotEmailPropertyId, iDoNotSmsPropertyId).
     * The equivalent ChurchCRM API requires the "Menu Options" permission;
     * here, EditRecords is enough, as for other record changes.
     *
     * @param mixed $body
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function updateProperties(int $id, $body): array
    {
        $user = $this->currentUser();
        if (!$user->isEditRecordsEnabled()) {
            throw new HttpException(403, 'EditRecords permission required');
        }
        $body = $this->requireObject($body);
        if (empty($body)) {
            throw new HttpException(422, 'No field sent');
        }
        $record = $this->findRecord('person', $id);

        $settings = ['doNotEmail' => 'iDoNotEmailPropertyId', 'doNotSms' => 'iDoNotSmsPropertyId'];
        $errors = [];
        $wanted = [];
        foreach ($body as $key => $value) {
            if (!isset($settings[$key])) {
                $errors[$key] = 'unknown field';
            } elseif (!is_bool($value)) {
                $errors[$key] = 'true or false expected';
            } else {
                $wanted[$key] = $value;
            }
        }
        if (!empty($errors)) {
            throw new HttpException(422, 'Invalid data', $errors);
        }

        $propertyIds = [];
        foreach ($wanted as $key => $value) {
            $propertyId = (int) SystemConfig::getValue($settings[$key]);
            $property = $propertyId > 0 ? PropertyQuery::create()->findPk($propertyId) : null;
            if ($property === null || $property->getProClass() !== 'p') {
                throw new HttpException(503, 'Property ' . $key . ' not set in ChurchCRM (' . $settings[$key] . ')');
            }
            $propertyIds[$key] = $propertyId;
        }

        $con = Propel::getWriteConnection(PersonTableMap::DATABASE_NAME);
        $con->beginTransaction();
        try {
            foreach ($wanted as $key => $value) {
                $existing = RecordPropertyQuery::create()
                    ->filterByRecordId($id)
                    ->filterByPropertyId($propertyIds[$key])
                    ->findOne($con);
                if ($value && $existing === null) {
                    $assignment = new RecordProperty();
                    $assignment->setPropertyId($propertyIds[$key]);
                    $assignment->setRecordId($id);
                    $assignment->setPropertyValue('');
                    $assignment->save($con);
                } elseif (!$value && $existing !== null) {
                    $existing->delete($con);
                }
            }
            $record->setDateLastEdited(date('YmdHis'));
            $record->setEditedBy($user->getId());
            $record->save($con);
            $con->commit();
        } catch (\Throwable $e) {
            $con->rollBack();
            throw $e;
        }

        return [200, ['success' => true, 'id' => $id, 'updated' => array_keys($wanted)]];
    }

    /**
     * Options of a ChurchCRM list (1 classification, 2 family role…),
     * so the external application can check its mappings.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function listOptions(int $listId): array
    {
        $items = [];
        foreach (ListOptionQuery::create()->filterById($listId)->orderByOptionSequence()->find() as $option) {
            $items[] = [
                'id' => (int) $option->getOptionId(),
                'name' => $option->getOptionName(),
                'sequence' => (int) $option->getOptionSequence(),
            ];
        }
        if (empty($items)) {
            throw new HttpException(404, 'List not found or empty');
        }

        return [200, ['success' => true, 'listId' => $listId, 'count' => count($items), 'items' => $items]];
    }

    /**
     * Creates a ChurchCRM calendar event and returns its ID
     * (the ChurchCRM 7.8.0 API does not return it). "Add Event" permission.
     *
     * @param mixed $body
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function createEvent($body): array
    {
        $user = $this->currentUser();
        if (!$user->isAddEventEnabled()) {
            throw new HttpException(403, 'AddEvent permission required');
        }
        $values = $this->validateEvent($this->requireObject($body), true);
        $type = EventTypeQuery::create()->findOneById($values['typeId']);
        if ($type === null) {
            throw new HttpException(422, 'Invalid data', ['typeId' => 'unknown event type']);
        }

        $event = (new EventService())->createEvent($type, [
            'title' => $values['title'],
            'desc' => $values['desc'] ?? '',
            'text' => $values['text'] ?? '',
            'start' => $values['start'],
            'end' => $values['end'],
            'inactive' => isset($values['active']) ? ($values['active'] ? 0 : 1) : 0,
        ]);

        return [201, ['success' => true, 'id' => (int) $event->getId()]];
    }

    /**
     * Updates the sent fields of an event.
     *
     * @param mixed $body
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function updateEvent(int $eventId, $body): array
    {
        $user = $this->currentUser();
        if (!$user->isAddEventEnabled()) {
            throw new HttpException(403, 'AddEvent permission required');
        }
        $event = EventQuery::create()->findPk($eventId);
        if ($event === null) {
            throw new HttpException(404, 'Event not found');
        }
        $values = $this->validateEvent($this->requireObject($body), false);
        if (empty($values)) {
            throw new HttpException(422, 'No field sent');
        }
        if (isset($values['typeId'])) {
            $type = EventTypeQuery::create()->findOneById($values['typeId']);
            if ($type === null) {
                throw new HttpException(422, 'Invalid data', ['typeId' => 'unknown event type']);
            }
            $event->setEventType($type);
        }
        if (isset($values['title'])) {
            $event->setTitle($values['title']);
        }
        if (array_key_exists('desc', $values)) {
            $event->setDesc($values['desc'] ?? '');
        }
        if (array_key_exists('text', $values)) {
            $event->setText($values['text'] ?? '');
        }
        if (isset($values['start'])) {
            $event->setStart($values['start']);
        }
        if (isset($values['end'])) {
            $event->setEnd($values['end']);
        }
        if (isset($values['active'])) {
            $event->setInActive($values['active'] ? 0 : 1);
        }
        $event->save();

        return [200, ['success' => true, 'id' => $eventId]];
    }

    /**
     * Deletes an event, unless persons are still checked in to it
     * or a kiosk uses it (409, like ChurchCRM).
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function deleteEvent(int $eventId): array
    {
        $user = $this->currentUser();
        if (!$user->isAddEventEnabled()) {
            throw new HttpException(403, 'AddEvent permission required');
        }
        $event = EventQuery::create()->findPk($eventId);
        if ($event === null) {
            throw new HttpException(404, 'Event not found');
        }
        try {
            (new EventService())->deleteEvent($event);
        } catch (EventDeleteRefusedException $e) {
            throw new HttpException(409, $e->getMessage());
        }

        return [200, ['success' => true, 'id' => $eventId, 'deleted' => true]];
    }

    /**
     * Validates the fields of an event: title (255), typeId, start and end
     * ("YYYY-MM-DD HH:MM:SS", end after start), desc (255), text, active.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function validateEvent(array $body, bool $creating): array
    {
        $errors = [];
        $values = [];
        $known = ['title', 'typeId', 'start', 'end', 'desc', 'text', 'active'];
        foreach (array_keys($body) as $key) {
            if (!in_array($key, $known, true)) {
                $errors[$key] = 'unknown field';
            }
        }
        foreach (['title', 'typeId', 'start', 'end'] as $key) {
            if ($creating && !array_key_exists($key, $body)) {
                $errors[$key] = 'required';
            }
        }
        if (array_key_exists('title', $body)) {
            $title = is_string($body['title']) ? trim($body['title']) : '';
            if ($title === '' || mb_strlen($title) > 255) {
                $errors['title'] = 'non-empty text, 255 max';
            } elseif ($title !== strip_tags($title)) {
                $errors['title'] = 'HTML tags not allowed';
            } else {
                $values['title'] = $title;
            }
        }
        if (array_key_exists('typeId', $body)) {
            if (!is_int($body['typeId']) || $body['typeId'] <= 0) {
                $errors['typeId'] = 'positive integer';
            } else {
                $values['typeId'] = $body['typeId'];
            }
        }
        foreach (['start', 'end'] as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $dt = is_string($body[$key]) ? \DateTime::createFromFormat('Y-m-d H:i:s', $body[$key]) : false;
            if ($dt === false || $dt->format('Y-m-d H:i:s') !== $body[$key]) {
                $errors[$key] = 'YYYY-MM-DD HH:MM:SS expected';
            } else {
                $values[$key] = $body[$key];
            }
        }
        if (isset($values['start'], $values['end']) && $values['end'] <= $values['start']) {
            $errors['end'] = 'must be after start';
        }
        foreach (['desc', 'text'] as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            if ($body[$key] !== null && !is_string($body[$key])) {
                $errors[$key] = 'text expected';
            } elseif ($key === 'desc' && $body[$key] !== null && mb_strlen($body[$key]) > 255) {
                $errors[$key] = '255 max';
            } elseif ($body[$key] !== null && $body[$key] !== strip_tags($body[$key])) {
                $errors[$key] = 'HTML tags not allowed';
            } else {
                $values[$key] = $body[$key];
            }
        }
        if (array_key_exists('active', $body)) {
            if (!is_bool($body['active'])) {
                $errors['active'] = 'true or false expected';
            } else {
                $values['active'] = $body['active'];
            }
        }
        if (!empty($errors)) {
            throw new HttpException(422, 'Invalid data', $errors);
        }

        return $values;
    }

    /**
     * Attendance recorded on an event, excluding the excluded group. The
     * ChurchCRM API only lists members of the groups linked to the event
     * (`/events/{id}/roster`); here, all checked-in persons.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function eventAttendance(int $eventId): array
    {
        $event = EventQuery::create()->findPk($eventId);
        if ($event === null) {
            throw new HttpException(404, 'Event not found');
        }
        $excluded = array_flip($this->requireExcludedPersonIds());

        $items = [];
        foreach (EventAttendQuery::create()->filterByEventId($eventId)->find() as $attendance) {
            $personId = (int) $attendance->getPersonId();
            if (isset($excluded[$personId])) {
                continue;
            }
            $checkin = $attendance->getCheckinDate();
            $checkout = $attendance->getCheckoutDate();
            $items[] = [
                'personId' => $personId,
                'checkinDate' => $checkin === null ? null : $checkin->format('Y-m-d H:i:s'),
                'checkoutDate' => $checkout === null ? null : $checkout->format('Y-m-d H:i:s'),
            ];
        }

        return [200, [
            'success' => true,
            'event' => [
                'id' => (int) $event->getId(),
                'title' => $event->getTitle(),
                'start' => $event->getStart('Y-m-d H:i:s'),
                'end' => $event->getEnd('Y-m-d H:i:s'),
                'active' => (int) $event->getInActive() !== 1,
            ],
            'count' => count($items),
            'items' => $items,
        ]];
    }

    private function currentUser(): User
    {
        return AuthenticationManager::getCurrentUser();
    }

    /**
     * @param mixed $body
     *
     * @return array<string, mixed>
     */
    private function requireObject($body): array
    {
        // Slim's JSON parser returns null on unreadable JSON.
        if (!is_array($body) || (array_is_list($body) && !empty($body))) {
            throw new HttpException(400, 'JSON object body expected');
        }

        return $body;
    }

    private function requireExternalIdCode(string $kind): string
    {
        $code = $this->plugin->getExternalIdCode($kind);
        if ($code === null) {
            throw new HttpException(503, (string) $this->plugin->getConfigurationError());
        }

        return $code;
    }

    /**
     * @return int[]
     */
    private function requireExcludedPersonIds(): array
    {
        $ids = $this->plugin->getExcludedPersonIds();
        if ($ids === null) {
            throw new HttpException(503, (string) $this->plugin->getConfigurationError());
        }

        return $ids;
    }

    /**
     * A person in the excluded group is treated as not found.
     *
     * @return Person|Family
     */
    private function findRecord(string $kind, int $id)
    {
        $record = $kind === 'person'
            ? PersonQuery::create()->findPk($id)
            : FamilyQuery::create()->findPk($id);
        if ($record !== null && $kind === 'person' && in_array($id, $this->requireExcludedPersonIds(), true)) {
            $record = null;
        }
        if ($record === null) {
            throw new HttpException(404, $kind === 'person' ? 'Person not found' : 'Family not found');
        }

        return $record;
    }
}
