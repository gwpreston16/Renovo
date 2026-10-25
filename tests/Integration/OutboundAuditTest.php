<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AuditAction;
use App\Domain\TrustedHostKind;
use App\Http\BlockedTargetException;
use App\Http\FixedOutboundHosts;
use App\Http\OutboundContext;
use App\Http\OutboundMode;
use App\Http\OutboundPolicy;
use App\Repository\AuditLogRepository;
use App\Repository\MembershipRepository;
use App\Repository\TrustedHostRepository;
use App\Repository\UserRepository;
use App\Security\RequestContext;
use App\Security\RequestContextHolder;
use App\Service\AuditLogService;
use App\Service\AuditRefusalRecorder;
use App\Service\TrustedHostService;
use App\Support\FrozenClock;
use App\Tests\Support\CapturingLogger;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\UriFactory;

/**
 * Refused outbound requests in the audit log (Phase 43): one entry per host,
 * reason and feature per hour with a count, the host and never the URL, the
 * user it was for, and the two kinds of trusted-host entry.
 */
final class OutboundAuditTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = FrozenClock::at('2026-10-25 12:00:00');
    }

    public function testTheFirstRefusalWritesAnEntryAndARepeatRaisesItsCount(): void
    {
        $audit = $this->audit();

        $audit->recordOutboundRefusal('hooks.example', 'outbound_off', 'webhook', null);
        $audit->recordOutboundRefusal('hooks.example', 'outbound_off', 'webhook', null);
        $audit->recordOutboundRefusal('hooks.example', 'outbound_off', 'webhook', null);

        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertSame(
            ['host' => 'hooks.example', 'reason' => 'outbound_off', 'feature' => 'webhook', 'count' => 3],
            $entries[0]['context'],
        );
        self::assertNull($entries[0]['household_id']);
        self::assertNull($entries[0]['actor_user_id']);
    }

    public function testAnotherHostReasonOrFeatureIsAnotherEntry(): void
    {
        $audit = $this->audit();

        $audit->recordOutboundRefusal('hooks.example', 'outbound_off', 'webhook', null);
        $audit->recordOutboundRefusal('other.example', 'outbound_off', 'webhook', null);
        $audit->recordOutboundRefusal('hooks.example', 'private_address', 'webhook', null);
        $audit->recordOutboundRefusal('hooks.example', 'outbound_off', 'logo', null);

        self::assertCount(4, $this->entries());
    }

    public function testARefusalAnHourLaterStartsANewEntry(): void
    {
        $audit = $this->audit();

        $audit->recordOutboundRefusal('hooks.example', 'outbound_off', 'rates', null);
        $this->clock->advanceTo(new \DateTimeImmutable('2026-10-25 13:00:01'));
        $audit->recordOutboundRefusal('hooks.example', 'outbound_off', 'rates', null);

        $entries = $this->entries();
        self::assertCount(2, $entries);
        self::assertSame(1, $entries[0]['context']['count']);
        self::assertSame(1, $entries[1]['context']['count']);
    }

    public function testTheRecorderAttributesTheRefusalToTheFeatureAndUserAndKeepsNoUrl(): void
    {
        $user = $this->user();
        $context = new OutboundContext();
        $recorder = new AuditRefusalRecorder(
            $this->audit(),
            $context,
            new UserRepository($this->db),
            new NullLogger(),
        );
        $policy = new OutboundPolicy(
            OutboundMode::Allowlist,
            new FixedOutboundHosts(),
            $this->trustedHosts(),
            $recorder,
        );

        $secretUrl = 'https://hooks.example/services/T000/B000/s3cr3t-token?key=also-secret';

        try {
            $context->within('telegram', $user->id, static function () use ($policy, $secretUrl): void {
                $policy->check((new UriFactory())->createUri($secretUrl));
            });
            self::fail('The request was admitted.');
        } catch (BlockedTargetException $refusal) {
            self::assertSame(BlockedTargetException::OUTBOUND_NOT_ALLOWLISTED, $refusal->reason);
        }

        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertSame('telegram', $entries[0]['context']['feature']);
        self::assertSame($user->id, (int) $entries[0]['actor_user_id']);
        self::assertSame('hooks.example', $entries[0]['target_label']);

        $stored = (string) json_encode($entries[0]);
        self::assertStringNotContainsString('s3cr3t', $stored);
        self::assertStringNotContainsString('also-secret', $stored);
        self::assertStringNotContainsString('/services', $stored);
    }

    public function testARefusalOutsideAnyFeatureOrUserIsAnonymous(): void
    {
        $recorder = new AuditRefusalRecorder(
            $this->audit(),
            new OutboundContext(),
            new UserRepository($this->db),
            new NullLogger(),
        );

        $recorder->refused('hooks.example', 'outbound_off');

        $entries = $this->entries();
        self::assertNull($entries[0]['actor_user_id']);
        self::assertNull($entries[0]['context']['feature']);
    }

    public function testOnlyAnInstanceAdministratorSeesRefusals(): void
    {
        $audit = $this->audit();
        $audit->recordOutboundRefusal('hooks.example', 'outbound_off', 'webhook', null);

        self::assertNotEmpty($this->entries());
        self::assertSame(
            0,
            (int) $this->db->fetchValue(
                'SELECT COUNT(*) FROM audit_log WHERE action = :a AND household_id IS NOT NULL',
                ['a' => AuditAction::OutboundRefused->value],
            ),
            'A refusal carries no household, so no household Owner is shown it.',
        );
    }

    public function testAContactEntryAllowsContactAndNothingElse(): void
    {
        $hosts = $this->trustedHosts();

        $hosts->add('private.lan', null, null);
        $hosts->add('hooks.example', 'a public webhook', null, TrustedHostKind::Contact);

        self::assertSame(['private.lan'], $hosts->entries(), 'The guard reads private entries only.');
        $everything = $hosts->allowlisted();
        sort($everything);
        self::assertSame(['hooks.example', 'private.lan'], $everything);

        $kinds = [];
        foreach ($hosts->all() as $row) {
            $kinds[$row['pattern']] = $row['kind'];
        }
        self::assertSame(TrustedHostKind::Contact, $kinds['hooks.example']);
        self::assertSame(TrustedHostKind::Private, $kinds['private.lan']);

        $hosts->remove($hosts->all()[0]['id'], null);
        self::assertCount(1, $hosts->allowlisted());
    }

    public function testEntriesAddedBeforeKindsExistedKeepTheirMeaning(): void
    {
        $this->db->execute("INSERT INTO trusted_hosts (pattern, created_at) VALUES ('old.lan', '2026-01-01 00:00:00')");

        self::assertSame(['old.lan'], $this->trustedHosts()->entries());
    }

    private function audit(): AuditLogService
    {
        return new AuditLogService(
            new AuditLogRepository($this->db),
            new MembershipRepository($this->db),
            new RequestContextHolder(RequestContext::of('127.0.0.1', 'PHPUnit')),
            $this->clock,
            new NullLogger(),
        );
    }

    private function trustedHosts(): TrustedHostService
    {
        return new TrustedHostService(
            new TrustedHostRepository($this->db, $this->clock),
            $this->audit(),
            new CapturingLogger(),
        );
    }

    private function user(): \App\Domain\Entity\User
    {
        $users = new UserRepository($this->db);
        $id = $users->create('owner@example.test', 'Owner', 'hash');
        $user = $users->findById($id);
        self::assertNotNull($user);

        return $user;
    }

    /**
     * @return list<array<string, mixed>> Oldest first, the context decoded.
     */
    private function entries(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM audit_log WHERE action = :action ORDER BY id ASC',
            ['action' => AuditAction::OutboundRefused->value],
        );

        return array_map(static function (array $row): array {
            $row['context'] = json_decode((string) $row['context'], true);

            return $row;
        }, $rows);
    }
}
