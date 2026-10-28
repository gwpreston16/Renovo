<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AlertType;

/**
 * Routing: what an empty or partial grid means (K14).
 */
final class NotificationSettingsServiceTest extends NotificationTestCase
{
    public function testAnEmptyGridMutesEveryChannel(): void
    {
        $phone = $this->addChannel($this->alice, 'Phone');
        $desktop = $this->addChannel($this->alice, 'Desktop');

        $this->notificationSettings->saveRoutes($this->alice, []);

        self::assertSame([], $this->notificationSettings->channelsFor($this->alice, AlertType::Renewal));
        // The grid reads back unticked, but saved: a muted channel is present, with no types.
        self::assertEqualsCanonicalizing([$phone => [], $desktop => []], $this->notificationSettings->routes($this->alice));
    }

    public function testDeletingARoutedChannelKeepsAMutedOneMuted(): void
    {
        $this->addChannel($this->alice, 'Muted');
        $routed = $this->addChannel($this->alice, 'Routed');

        $this->notificationSettings->saveRoutes($this->alice, [$routed => [AlertType::Renewal->value]]);
        self::assertSame([$routed], array_map(
            static fn ($c) => $c->id,
            $this->notificationSettings->channelsFor($this->alice, AlertType::Renewal),
        ));

        // Deleting the only routed channel cascades its rows away.
        $this->channels->delete($this->alice, $routed);

        self::assertSame([], $this->notificationSettings->channelsFor($this->alice, AlertType::Renewal));
    }

    public function testAUserWithNoRowsStillGetsEverythingEverywhere(): void
    {
        $this->addChannel($this->alice, 'Phone');

        self::assertCount(1, $this->notificationSettings->channelsFor($this->alice, AlertType::Renewal));
    }
}
