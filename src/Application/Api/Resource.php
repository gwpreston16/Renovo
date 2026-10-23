<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Domain\Entity\ApiToken;
use App\Domain\Entity\Attachment;
use App\Domain\Entity\Charge;
use App\Domain\Entity\Category;
use App\Domain\Entity\PaymentMethod;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\Tag;
use App\Domain\Money;
use App\Service\SavingsService;
use DateTimeImmutable;

/**
 * Entities as the API represents them.
 *
 * One class, so that the backup exporter, the importer's round trip and the
 * endpoints all describe a subscription the same way. If the representation
 * lived in each controller, an export would drift from what the API serves and
 * "restore what you exported" would quietly stop being true.
 *
 * Money is always an integer in minor units beside its currency code, never a
 * decimal and never a float. A JSON number with a fractional part is a binary
 * float by the time most clients have parsed it, and 19.99 is not representable
 * in one — which is the whole reason this application stores integers.
 *
 * @phpstan-import-type Savings from SavingsService
 */
final class Resource
{
    /**
     * `$promotion` is the intro offer the current price is on, from
     * `PriceHistoryService::promotionFor()` — null for an ordinary price.
     *
     * @param array{ends_on: DateTimeImmutable|null, then: Money|null}|null $promotion
     * @return array<string, mixed>
     */
    public static function subscription(
        Subscription $subscription,
        DateTimeImmutable $today,
        ?array $promotion = null,
    ): array {
        return [
            'id' => $subscription->id,
            'name' => $subscription->name,
            'notes' => $subscription->notes,
            'price_minor' => $subscription->price->amountMinor,
            'currency' => $subscription->price->currency,
            'subscription_type' => $subscription->type->value,
            'billing_cycle' => $subscription->billingCycle?->value,
            'cycle_days' => $subscription->cycleDays,
            'cycle_interval' => $subscription->cycleInterval,
            'next_payment_date' => self::date($subscription->nextPaymentDate),
            'start_date' => self::date($subscription->startDate),
            'anchor_day' => $subscription->anchorDay,
            'notice_period_amount' => $subscription->noticePeriod->amount,
            'notice_period_unit' => $subscription->noticePeriod->isSet()
                ? $subscription->noticePeriod->unit
                : null,
            'reminder_days' => $subscription->reminderDaysList(),
            'is_trial' => $subscription->isTrial,
            'trial_end_date' => self::date($subscription->trialEndDate),
            'converts_to_price_minor' => $subscription->convertsToPrice?->amountMinor,
            'converts_to_billing_cycle' => $subscription->convertsToBillingCycle?->value,
            'converts_to_cycle_days' => $subscription->convertsToCycleDays,
            'converts_to_cycle_interval' => $subscription->convertsToCycleInterval,
            // Read-only: an offer is entered through the web interface, where
            // its end is written as the scheduled change it is.
            'price_is_promotional' => $promotion !== null,
            'promo_ends_on' => self::date($promotion['ends_on'] ?? null),
            'promo_then_price_minor' => ($promotion['then'] ?? null)?->amountMinor,
            'is_active' => $subscription->isActive,
            'plan' => $subscription->plan,
            'contract_ends_on' => self::date($subscription->contractEndsOn),
            'payments_end_on' => self::date($subscription->paymentsEndOn),
            'amount_varies' => $subscription->amountVaries,
            'visibility' => $subscription->visibility->value,
            // Read-only: moved by the cancel and uncancel endpoints, and by the
            // catch-up when a fixed term's last payment has passed.
            'cancelled_at' => self::date($subscription->cancelledAt),
            'category_id' => $subscription->categoryId,
            'category_name' => $subscription->categoryName,
            'payment_method_id' => $subscription->paymentMethodId,
            'payment_method_name' => $subscription->paymentMethodName,
            'owner_user_id' => $subscription->ownerUserId,
            'payer_user_id' => $subscription->payerUserId,
            'tags' => array_map(static fn (Tag $tag): string => $tag->name, $subscription->tags),
            'split_mode' => $subscription->splitMode->value,
            'usage_count' => $subscription->usageCount,
            'usage_rating' => $subscription->usageRating,
            'logo_path' => $subscription->logoPath,
            'website_url' => $subscription->websiteUrl,
            // Derived, and read-only. A client that recomputed these would have
            // to reimplement the billing-cycle normalisation to get them right.
            'status' => $subscription->status()->value,
            'monthly_minor' => $subscription->monthlyMinor(),
            'yearly_minor' => $subscription->yearlyMinor(),
            'next_charge_date' => self::date($subscription->nextChargeDate()),
            'cancellation_deadline' => self::date($subscription->cancellationDeadline()),
            'days_until_next_payment' => $subscription->daysUntilNextPayment($today),
            'created_at' => self::timestamp($subscription->createdAt),
            'updated_at' => self::timestamp($subscription->updatedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function category(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'colour' => $category->colour,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function paymentMethod(PaymentMethod $method): array
    {
        return [
            'id' => $method->id,
            'name' => $method->name,
            'colour' => $method->colour,
            'icon' => $method->icon,
            'logo_path' => $method->logoPath,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function tag(Tag $tag): array
    {
        return [
            'id' => $tag->id,
            'name' => $tag->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function attachment(Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'subscription_id' => $attachment->subscriptionId,
            'filename' => $attachment->originalFilename,
            'mime_type' => $attachment->mimeType,
            'size_bytes' => $attachment->sizeBytes,
            'period_date' => self::date($attachment->periodDate),
            'charge_id' => $attachment->chargeId,
            'created_at' => self::timestamp($attachment->createdAt),
        ];
    }

    /**
     * One charge in the payment ledger. `amount_minor` is what it counts as:
     * the actual when recorded, the expected otherwise, zero when skipped.
     * `reference_minor` is what it would have cost without its offer, when
     * known; `saving_minor` what the offer took off; `is_discount` whether
     * the reference is a member's discount rather than an offer's.
     *
     * @return array<string, mixed>
     */
    public static function charge(Charge $charge): array
    {
        return [
            'id' => $charge->id,
            'subscription_id' => $charge->subscriptionId,
            'due_date' => self::date($charge->dueDate),
            'currency' => $charge->expected->currency,
            'expected_minor' => $charge->expected->amountMinor,
            'actual_minor' => $charge->actualMinor,
            'amount_minor' => $charge->amount()->amountMinor,
            'reference_minor' => $charge->referenceMinor,
            'saving_minor' => $charge->saving()->amountMinor,
            'is_discount' => $charge->isDiscount(),
            'status' => $charge->status->value,
            'source' => $charge->source->value,
            'note' => $charge->note,
            'created_at' => self::timestamp($charge->createdAt),
            'updated_at' => self::timestamp($charge->updatedAt),
        ];
    }

    /**
     * What offers and cancellations have saved, as `SavingsService` works it
     * out. Every figure is `{totals, combined}`: each currency's amount, and
     * their sum in the base currency — `amount_minor` null, with the
     * currencies that have no rate named, when one does not convert.
     *
     * @param Savings $savings
     * @return array<string, mixed>
     */
    public static function savings(array $savings): array
    {
        $offers = $savings['offers'];
        $cancellations = $savings['cancellations'];

        return [
            'offers' => [
                'total' => $offers['total'],
                'by_year' => $offers['by_year'],
                'by_subscription' => array_map(
                    static fn (array $row): array => [
                        'subscription_id' => $row['subscription']->id,
                        'name' => $row['subscription']->name,
                        'totals' => $row['totals'],
                        'charges' => $row['charges'],
                    ],
                    $offers['by_subscription'],
                ),
            ],
            'cancellations' => [
                'realised' => $cancellations['realised'],
                'run_rate' => $cancellations['run_rate'],
                'by_subscription' => array_map(
                    static fn (array $row): array => [
                        'subscription_id' => $row['subscription']->id,
                        'name' => $row['subscription']->name,
                        'cancelled_on' => self::date($row['cancelled_on']),
                        'totals' => $row['totals'],
                        'charges' => $row['charges'],
                        'committed' => $row['committed'],
                        'run_rate' => $row['run_rate'] === null ? null : [
                            'currency' => $row['run_rate']->currency,
                            'amount_minor' => $row['run_rate']->amountMinor,
                        ],
                    ],
                    $cancellations['by_subscription'],
                ),
                'trials' => $cancellations['trials'],
                'cap_months' => $savings['cap_months'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function token(ApiToken $token): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'public_id' => $token->publicId,
            'abilities' => $token->abilities->value,
            'last_used_at' => self::timestamp($token->lastUsedAt),
            'expires_at' => self::timestamp($token->expiresAt),
            'revoked_at' => self::timestamp($token->revokedAt),
            'created_at' => self::timestamp($token->createdAt),
        ];
    }

    private static function date(?DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d');
    }

    private static function timestamp(?DateTimeImmutable $moment): ?string
    {
        return $moment?->format(DATE_ATOM);
    }
}
