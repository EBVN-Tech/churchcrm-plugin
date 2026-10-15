<?php

namespace ChurchCRM\Plugins\PeopleSync;

use ChurchCRM\Authentication\AuthenticationManager;
use ChurchCRM\model\ChurchCRM\Family;
use ChurchCRM\model\ChurchCRM\FamilyQuery;
use ChurchCRM\model\ChurchCRM\Map\PersonTableMap;
use ChurchCRM\model\ChurchCRM\Person;
use ChurchCRM\model\ChurchCRM\PersonQuery;
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

    public function __construct(private PeopleSyncPlugin $plugin)
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

        return [200, ['success' => true, 'id' => $id, 'fields' => CustomFields::read($con, $kind, $id, $extCode, $user)]];
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
     * @return Person|Family
     */
    private function findRecord(string $kind, int $id)
    {
        $record = $kind === 'person'
            ? PersonQuery::create()->findPk($id)
            : FamilyQuery::create()->findPk($id);
        if ($record === null) {
            throw new HttpException(404, $kind === 'person' ? 'Personne introuvable' : 'Famille introuvable');
        }

        return $record;
    }
}
