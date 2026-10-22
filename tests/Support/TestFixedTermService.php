<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\RequestContextHolder;
use App\Persistence\Database;
use App\Repository\AuditLogRepository;
use App\Repository\ChargeRepository;
use App\Repository\MembershipRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\SubscriptionRepository;
use App\Service\AuditLogService;
use App\Service\ChargeSchedule;
use App\Service\ChargeService;
use App\Service\FixedTermService;
use App\Service\PriceHistoryService;
use App\Support\Clock;
use Psr\Log\NullLogger;

/**
 * A FixedTermService wired to real repositories, for a test that builds its
 * catch-up by hand — so a new constructor parameter is one change here.
 */
final class TestFixedTermService
{
    public static function build(
        Database $db,
        Clock $clock,
        ?PriceHistoryService $priceHistory = null,
    ): FixedTermService {
        $subscriptions = new SubscriptionRepository($db);
        $priceHistory ??= new PriceHistoryService(new PriceHistoryRepository($db), $subscriptions, $db, $clock);

        return new FixedTermService(
            $subscriptions,
            new ChargeRepository($db),
            new ChargeService(new ChargeRepository($db), $subscriptions, $priceHistory, new ChargeSchedule(), $clock),
            new AuditLogService(
                new AuditLogRepository($db),
                new MembershipRepository($db),
                new RequestContextHolder(),
                $clock,
                new NullLogger(),
            ),
            $db,
            $clock,
        );
    }
}
