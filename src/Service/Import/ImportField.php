<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * The fields an import can fill in.
 *
 * A closed list rather than "whatever the file has", because a mapping screen
 * that offered every column of the subscriptions table would invite somebody to
 * map a household id. What is absent is as deliberate as what is present: no
 * owner, no household, no id. Imported rows belong to the importing user's
 * household and are owned according to the scope, exactly like rows created in
 * the form.
 */
enum ImportField: string
{
    case Name = 'name';
    case Price = 'price';
    // Its own field rather than a second spelling of Price, because the unit is
    // not something to guess at. A column of "1099" is either £10.99 or
    // £1,099.00 and nothing about the number says which — this application's own
    // export writes minor units, most spreadsheets write decimals, and reading
    // one as the other is off by a hundred rather than visibly wrong.
    case PriceMinor = 'price_minor';
    case Currency = 'currency';
    case SubscriptionType = 'subscription_type';
    case BillingCycle = 'billing_cycle';
    case CycleDays = 'cycle_days';
    // Every N of the cycle's unit, for a file that keeps the count in a
    // column of its own. Optional: "every 6 months" in the cycle column says
    // the same thing without it.
    case CycleInterval = 'cycle_interval';
    case NextPaymentDate = 'next_payment_date';
    case StartDate = 'start_date';
    case Category = 'category';
    case Tags = 'tags';
    case Notes = 'notes';
    case Plan = 'plan';
    case IsActive = 'is_active';
    case NoticePeriodAmount = 'notice_period_amount';
    case NoticePeriodUnit = 'notice_period_unit';
    case TrialEndDate = 'trial_end_date';
    case ConvertsToPrice = 'converts_to_price';
    case ConvertsToPriceMinor = 'converts_to_price_minor';
    // Phase 35's terms: how a row behaves, each optional.
    case ContractEndsOn = 'contract_ends_on';
    case PaymentsEndOn = 'payments_end_on';
    case AmountVaries = 'amount_varies';

    public function labelKey(): string
    {
        return 'import_field.' . $this->value . '.label';
    }

    /**
     * Without these two an imported row is not a subscription. Everything else
     * has a defensible default.
     */
    public function isRequired(): bool
    {
        return $this === self::Name || $this === self::Price;
    }

    /**
     * A key for the note beside the column chooser, or null where the field
     * needs no explaining.
     */
    public function hintKey(): ?string
    {
        return match ($this) {
            self::Price, self::PriceMinor, self::ConvertsToPriceMinor, self::BillingCycle, self::CycleInterval,
            self::NextPaymentDate, self::StartDate, self::TrialEndDate, self::Tags,
            self::IsActive, self::NoticePeriodUnit, self::ContractEndsOn, self::PaymentsEndOn,
            self::AmountVaries => 'import_field.' . $this->value . '.hint',
            default => null,
        };
    }
}
