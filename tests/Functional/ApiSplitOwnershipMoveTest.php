<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Domain\SplitMode;
use App\Security\Scope;
use App\Service\SplitService;

/**
 * An API PUT that gives a split subscription to another member moves its
 * split rows with it (K156), as the form and Bulk → owner do.
 */
final class ApiSplitOwnershipMoveTest extends ApiTestCase
{
    public function testAPutOfOwnerUserIdMovesTheSplitRows(): void
    {
        $body = [
            'name' => 'Broadband',
            'price_minor' => 3000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ];
        $id = $this->createSubscription($body);

        $this->container()->get(SplitService::class)->update(
            Scope::forMember($this->ownerId, false, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared),
            $id,
            ['split_mode' => SplitMode::Equal->value, 'shares' => [$this->ownerId => 1, $this->editorId => 1]],
        );

        $response = $this->api(
            'PUT',
            '/api/v1/subscriptions/' . $id,
            $this->ownerToken,
            $body + ['owner_user_id' => $this->editorId],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($this->editorId, $this->decode($response)['data']['owner_user_id']);

        $owners = array_map(
            static fn (array $row): int => (int) $row['owner_user_id'],
            $this->db->fetchAll(
                'SELECT owner_user_id FROM subscription_splits WHERE subscription_id = :id',
                ['id' => $id],
            ),
        );
        self::assertSame([$this->editorId, $this->editorId], $owners);
    }
}
