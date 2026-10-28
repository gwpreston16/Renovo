<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\MemberDataRepository;
use App\Security\Scope;

/**
 * A subscription whose payer has left the household (K49): the member is
 * cleared as payer on removal, so the row reads back without them and saving
 * it as it was read succeeds rather than failing with 422.
 */
final class ApiPayerRemovalTest extends ApiTestCase
{
    public function testSavingARowWhosePayerLeftSucceeds(): void
    {
        $id = $this->createSubscription(['payer_user_id' => $this->editorId]);
        self::assertSame($this->editorId, $this->fetch($id)['payer_user_id']);

        $this->container()->get(MemberDataRepository::class)->removeFromHousehold(
            Scope::forMember($this->ownerId, false, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared),
            $this->editorId,
            $this->ownerId,
            false,
        );

        $read = $this->fetch($id);
        self::assertNull($read['payer_user_id'], 'A member who left is no longer shown as payer.');

        $response = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, [
            'name' => $read['name'],
            'price_minor' => $read['price_minor'],
            'currency' => $read['currency'],
            'subscription_type' => $read['subscription_type'],
            'billing_cycle' => $read['billing_cycle'],
            'next_payment_date' => $read['next_payment_date'],
            'payer_user_id' => $read['payer_user_id'],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull($this->fetch($id)['payer_user_id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(int $id): array
    {
        return $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken))['data'];
    }
}
