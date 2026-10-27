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
 * Logique des routes. Chaque méthode renvoie [code HTTP, corps JSON] ou
 * lève une HttpException.
 */
class SyncController
{
    /** Identifiant externe : UUID ou identifiant simple, 50 caractères max (champ texte 50). */
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
            throw new HttpException(403, 'Droit AddRecords requis');
        }
        $body = $this->requireObject($body);
        $extCode = $this->requireExternalIdCode($kind);

        $externalId = $body['externalId'] ?? null;
        unset($body['externalId']);
        if (!is_string($externalId) || preg_match(self::EXTERNAL_ID_PATTERN, $externalId) !== 1) {
            throw new HttpException(422, 'Données invalides', ['externalId' => 'obligatoire, 1 à 50 caractères parmi A-Z a-z 0-9 _ -']);
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
                // Comme les éditeurs de ChurchCRM : pays par défaut des réglages.
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
            throw new HttpException(403, 'Droit EditRecords requis');
        }
        $body = $this->requireObject($body);
        if (array_key_exists('externalId', $body)) {
            throw new HttpException(422, 'Données invalides', ['externalId' => 'non modifiable']);
        }
        if (empty($body)) {
            throw new HttpException(422, 'Aucun champ envoyé');
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

        // (object) : {} et non [] quand aucun champ n'est lisible.
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
            throw new HttpException(403, 'Droit EditRecords requis');
        }
        $body = $this->requireObject($body);
        $record = $this->findRecord($kind, $id);
        $extCode = $this->requireExternalIdCode($kind);
        $values = CustomFields::validate($kind, $body, $extCode, $user);

        $con = Propel::getWriteConnection(PersonTableMap::DATABASE_NAME);
        $con->beginTransaction();
        try {
            CustomFields::write($con, $kind, $id, $values);
            // Trace « modifié par » sur la fiche, comme les éditeurs de ChurchCRM.
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
     * Liste complète des personnes ou des familles, sans le groupe exclu.
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
     * Pose ou retire sur une personne les propriétés « Ne pas contacter »
     * réglées dans ChurchCRM (iDoNotEmailPropertyId, iDoNotSmsPropertyId).
     * L'API ChurchCRM équivalente exige le droit « Menu Options » ; ici,
     * EditRecords suffit, comme pour les autres modifications de fiche.
     *
     * @param mixed $body
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function updateProperties(int $id, $body): array
    {
        $user = $this->currentUser();
        if (!$user->isEditRecordsEnabled()) {
            throw new HttpException(403, 'Droit EditRecords requis');
        }
        $body = $this->requireObject($body);
        if (empty($body)) {
            throw new HttpException(422, 'Aucun champ envoyé');
        }
        $record = $this->findRecord('person', $id);

        $settings = ['doNotEmail' => 'iDoNotEmailPropertyId', 'doNotSms' => 'iDoNotSmsPropertyId'];
        $errors = [];
        $wanted = [];
        foreach ($body as $key => $value) {
            if (!isset($settings[$key])) {
                $errors[$key] = 'champ inconnu';
            } elseif (!is_bool($value)) {
                $errors[$key] = 'true ou false attendu';
            } else {
                $wanted[$key] = $value;
            }
        }
        if (!empty($errors)) {
            throw new HttpException(422, 'Données invalides', $errors);
        }

        $propertyIds = [];
        foreach ($wanted as $key => $value) {
            $propertyId = (int) SystemConfig::getValue($settings[$key]);
            $property = $propertyId > 0 ? PropertyQuery::create()->findPk($propertyId) : null;
            if ($property === null || $property->getProClass() !== 'p') {
                throw new HttpException(503, 'Propriété ' . $key . ' non réglée dans ChurchCRM (' . $settings[$key] . ')');
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
     * Options d'une liste ChurchCRM (1 classification, 2 rôle familial…),
     * pour que l'application externe vérifie ses correspondances.
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
            throw new HttpException(404, 'Liste introuvable ou vide');
        }

        return [200, ['success' => true, 'listId' => $listId, 'count' => count($items), 'items' => $items]];
    }

    /**
     * Crée un événement du calendrier ChurchCRM et renvoie son identifiant
     * (l'API de ChurchCRM 7.8.0 ne le renvoie pas). Droit « Add Event ».
     *
     * @param mixed $body
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function createEvent($body): array
    {
        $user = $this->currentUser();
        if (!$user->isAddEventEnabled()) {
            throw new HttpException(403, 'Droit AddEvent requis');
        }
        $values = $this->validateEvent($this->requireObject($body), true);
        $type = EventTypeQuery::create()->findOneById($values['typeId']);
        if ($type === null) {
            throw new HttpException(422, 'Données invalides', ['typeId' => 'type d\'événement inconnu']);
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
     * Modifie les champs envoyés d'un événement.
     *
     * @param mixed $body
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function updateEvent(int $eventId, $body): array
    {
        $user = $this->currentUser();
        if (!$user->isAddEventEnabled()) {
            throw new HttpException(403, 'Droit AddEvent requis');
        }
        $event = EventQuery::create()->findPk($eventId);
        if ($event === null) {
            throw new HttpException(404, 'Événement introuvable');
        }
        $values = $this->validateEvent($this->requireObject($body), false);
        if (empty($values)) {
            throw new HttpException(422, 'Aucun champ envoyé');
        }
        if (isset($values['typeId'])) {
            $type = EventTypeQuery::create()->findOneById($values['typeId']);
            if ($type === null) {
                throw new HttpException(422, 'Données invalides', ['typeId' => 'type d\'événement inconnu']);
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
     * Supprime un événement, sauf si des personnes y sont encore pointées
     * ou qu'un kiosque l'utilise (409, comme ChurchCRM).
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function deleteEvent(int $eventId): array
    {
        $user = $this->currentUser();
        if (!$user->isAddEventEnabled()) {
            throw new HttpException(403, 'Droit AddEvent requis');
        }
        $event = EventQuery::create()->findPk($eventId);
        if ($event === null) {
            throw new HttpException(404, 'Événement introuvable');
        }
        try {
            (new EventService())->deleteEvent($event);
        } catch (EventDeleteRefusedException $e) {
            throw new HttpException(409, $e->getMessage());
        }

        return [200, ['success' => true, 'id' => $eventId, 'deleted' => true]];
    }

    /**
     * Valide les champs d'un événement : title (255), typeId, start et end
     * (« AAAA-MM-JJ HH:MM:SS », fin après début), desc (255), text, active.
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
                $errors[$key] = 'champ inconnu';
            }
        }
        foreach (['title', 'typeId', 'start', 'end'] as $key) {
            if ($creating && !array_key_exists($key, $body)) {
                $errors[$key] = 'obligatoire';
            }
        }
        if (array_key_exists('title', $body)) {
            $title = is_string($body['title']) ? trim($body['title']) : '';
            if ($title === '' || mb_strlen($title) > 255) {
                $errors['title'] = 'texte non vide, 255 max';
            } elseif ($title !== strip_tags($title)) {
                $errors['title'] = 'balises HTML interdites';
            } else {
                $values['title'] = $title;
            }
        }
        if (array_key_exists('typeId', $body)) {
            if (!is_int($body['typeId']) || $body['typeId'] <= 0) {
                $errors['typeId'] = 'entier positif';
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
                $errors[$key] = 'AAAA-MM-JJ HH:MM:SS attendu';
            } else {
                $values[$key] = $body[$key];
            }
        }
        if (isset($values['start'], $values['end']) && $values['end'] <= $values['start']) {
            $errors['end'] = 'doit être après le début';
        }
        foreach (['desc', 'text'] as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            if ($body[$key] !== null && !is_string($body[$key])) {
                $errors[$key] = 'texte attendu';
            } elseif ($key === 'desc' && $body[$key] !== null && mb_strlen($body[$key]) > 255) {
                $errors[$key] = '255 max';
            } elseif ($body[$key] !== null && $body[$key] !== strip_tags($body[$key])) {
                $errors[$key] = 'balises HTML interdites';
            } else {
                $values[$key] = $body[$key];
            }
        }
        if (array_key_exists('active', $body)) {
            if (!is_bool($body['active'])) {
                $errors['active'] = 'true ou false attendu';
            } else {
                $values['active'] = $body['active'];
            }
        }
        if (!empty($errors)) {
            throw new HttpException(422, 'Données invalides', $errors);
        }

        return $values;
    }

    /**
     * Présences enregistrées sur un événement, hors groupe exclu. L'API
     * ChurchCRM ne liste que les membres des groupes liés à l'événement
     * (`/events/{id}/roster`) ; ici, toutes les personnes pointées.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function eventAttendance(int $eventId): array
    {
        $event = EventQuery::create()->findPk($eventId);
        if ($event === null) {
            throw new HttpException(404, 'Événement introuvable');
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
        // Le parseur JSON de Slim renvoie null sur un JSON illisible.
        if (!is_array($body) || (array_is_list($body) && !empty($body))) {
            throw new HttpException(400, 'Corps JSON objet attendu');
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
     * Une personne du groupe exclu est traitée comme introuvable.
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
            throw new HttpException(404, $kind === 'person' ? 'Personne introuvable' : 'Famille introuvable');
        }

        return $record;
    }
}
