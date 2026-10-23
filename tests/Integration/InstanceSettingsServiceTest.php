<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\InstanceSettingsRepository;
use App\Service\InstanceSettingsService;

/**
 * The request-lifetime cache in front of the instance settings.
 */
final class InstanceSettingsServiceTest extends DatabaseTestCase
{
    public function testWritingBeforeAnyReadDoesNotHideTheOtherSettings(): void
    {
        $repository = new InstanceSettingsRepository($this->db);
        (new InstanceSettingsService($repository))->setUpdateCheckEnabled(true);

        // A fresh request: its first touch of the settings is a write.
        $settings = new InstanceSettingsService($repository);
        $settings->setDemoMode(false);

        self::assertTrue($settings->updateCheckEnabled(), 'A setting not written in this request is still read.');
        self::assertFalse($settings->isDemoMode());
    }

    public function testAWriteIsReadBackFromTheCache(): void
    {
        $settings = new InstanceSettingsService(new InstanceSettingsRepository($this->db));

        self::assertFalse($settings->updateCheckEnabled());
        $settings->setUpdateCheckEnabled(true);

        self::assertTrue($settings->updateCheckEnabled());
    }
}
