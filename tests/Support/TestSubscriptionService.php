<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Persistence\Database;
use App\Repository\CategoryRepository;
use App\Repository\ChargeRepository;
use App\Repository\MembershipRepository;
use App\Repository\PaymentMethodRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\ChargeSchedule;
use App\Service\ChargeService;
use App\Service\PriceHistoryService;
use App\Service\SubscriptionService;
use App\Support\Clock;

/**
 * A SubscriptionService wired to real repositories, for a test that builds
 * its services by hand and needs one only as a collaborator — so a new
 * constructor parameter is one change here rather than one per test.
 */
final class TestSubscriptionService
{
    public static function build(
        Database $db,
        Clock $clock,
        ?PriceHistoryService $priceHistory = null,
    ): SubscriptionService {
        $subscriptions = new SubscriptionRepository($db);
        $priceHistory ??= new PriceHistoryService(new PriceHistoryRepository($db), $subscriptions, $db, $clock);

        return new SubscriptionService(
            $subscriptions,
            new CategoryRepository($db),
            new PaymentMethodRepository($db),
            new TagRepository($db),
            TestLogoFetcher::silent($db, $clock),
            new MembershipRepository($db),
            $priceHistory,
            new ChargeService(new ChargeRepository($db), $subscriptions, $priceHistory, new ChargeSchedule(), $clock),
            $db,
            $clock,
        );
    }
}
