<?php

namespace App\Service\Organizer;

use App\Entity\OrganizerPaymentMethod;
use App\Enum\MobileMoneyProvider;
use App\Exception\OrganizerException;

/**
 * Transforme la liste `paymentMethods` d'un corps JSON en entités validées.
 *
 * Règles : au moins un compte, au plus un par opérateur, numéro cohérent avec
 * l'opérateur (voir `MobileMoneyProvider::normalizePhone()`), nom du titulaire
 * obligatoire. L'invariant « au moins un compte » n'existe qu'ici : la base ne
 * peut pas l'exprimer.
 */
final class PaymentMethodInputParser
{
    private const MAX_ACCOUNT_NAME = 100;

    /** @return OrganizerPaymentMethod[] */
    public function parse(mixed $raw): array
    {
        if ($raw === null || $raw === []) {
            throw OrganizerException::paiementRequis();
        }

        if (!is_array($raw) || !array_is_list($raw)) {
            throw OrganizerException::paiementInvalide();
        }

        $methods = [];

        foreach ($raw as $item) {
            if (!is_array($item)) {
                throw OrganizerException::paiementInvalide();
            }

            $provider = MobileMoneyProvider::fromInput($this->text($item, 'provider'));

            if (isset($methods[$provider->value])) {
                throw OrganizerException::operateurEnDouble($provider);
            }

            $methods[$provider->value] = (new OrganizerPaymentMethod())
                ->setProvider($provider)
                ->setPhoneNumber($provider->normalizePhone($this->text($item, 'phoneNumber')))
                ->setAccountName($this->accountName($item));
        }

        return array_values($methods);
    }

    private function accountName(array $item): string
    {
        $name = $this->text($item, 'accountName');

        if (mb_strlen($name) > self::MAX_ACCOUNT_NAME) {
            throw OrganizerException::champTropLong('accountName', self::MAX_ACCOUNT_NAME);
        }

        return $name;
    }

    private function text(array $item, string $field): string
    {
        $value = $item[$field] ?? null;
        $value = is_scalar($value) ? trim((string) $value) : '';

        if ($value === '') {
            throw OrganizerException::champRequis('paymentMethods[].' . $field);
        }

        return $value;
    }
}
