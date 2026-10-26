<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Entity\Subscription;
use InvalidArgumentException;

/**
 * A "what if": the subscriptions to cancel and the ones to move to another
 * price, by id. Nothing in it is stored; it lives in the query string —
 * `?cancel[]=12&change[18][price]=9.99&change[18][cycle]=yearly` — so it can
 * be bookmarked, works without script and survives a reload.
 *
 * Read only against the rows a scenario may name: the running, recurring
 * subscriptions the reader's scope can see. An id outside them is dropped
 * without a word, so a shared link says nothing about rows its reader cannot
 * see, and a row that has been cancelled since the link was made simply
 * falls out of it.
 *
 * The planner's own form sends a `choice[id]` of keep, cancel or change beside
 * the amount fields, so that choosing Keep on a row with an amount typed in
 * keeps it. `query()` writes the scenario back in the short form, without
 * them.
 */
final class Scenario
{
    public const KEEP = 'keep';
    public const CANCEL = 'cancel';
    public const CHANGE = 'change';

    /** The longest custom cycle the subscription form accepts. */
    private const MAX_CYCLE_DAYS = 3650;

    /**
     * @param array<int, true> $cancellations Keyed by subscription id.
     * @param array<int, ScenarioChange> $changes Keyed by subscription id.
     * @param array<int, array{price: string, cycle: string, days: string}> $input
     *        What was typed into each row set to Change price, valid or not.
     * @param array<int, array<string, string>> $errors Error keys by row, then field.
     */
    private function __construct(
        private readonly array $cancellations,
        private readonly array $changes,
        private readonly array $input,
        private readonly array $errors,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], [], []);
    }

    /**
     * @param array<mixed> $query The request's query parameters.
     * @param array<int, Subscription> $eligible The rows a scenario may name, by id.
     */
    public static function fromQuery(array $query, array $eligible): self
    {
        $choices = is_array($query['choice'] ?? null) ? $query['choice'] : [];
        $cancel = is_array($query['cancel'] ?? null) ? $query['cancel'] : [];
        $change = is_array($query['change'] ?? null) ? $query['change'] : [];

        $cancelIds = [];
        foreach ($cancel as $id) {
            $id = self::id($id);
            if ($id !== null) {
                $cancelIds[$id] = true;
            }
        }

        $cancellations = [];
        $changes = [];
        $input = [];
        $errors = [];

        foreach ($eligible as $id => $subscription) {
            $raw = is_array($change[$id] ?? null) ? $change[$id] : [];
            $entry = [
                'price' => self::text($raw['price'] ?? null),
                'cycle' => self::text($raw['cycle'] ?? null),
                'days' => self::text($raw['days'] ?? null),
            ];

            $choice = self::text($choices[$id] ?? null);
            if (!in_array($choice, [self::KEEP, self::CANCEL, self::CHANGE], true)) {
                $choice = match (true) {
                    isset($cancelIds[$id]) => self::CANCEL,
                    $entry['price'] !== '' => self::CHANGE,
                    default => self::KEEP,
                };
            }

            if ($choice === self::CANCEL) {
                $cancellations[$id] = true;
                continue;
            }

            if ($choice !== self::CHANGE) {
                continue;
            }

            $input[$id] = $entry;
            [$parsed, $rowErrors] = self::change($entry, $subscription);
            if ($parsed !== null) {
                $changes[$id] = $parsed;
            } else {
                $errors[$id] = $rowErrors;
            }
        }

        ksort($cancellations);
        ksort($changes);

        return new self($cancellations, $changes, $input, $errors);
    }

    public function isEmpty(): bool
    {
        return $this->cancellations === [] && $this->changes === [];
    }

    public function cancels(int $id): bool
    {
        return isset($this->cancellations[$id]);
    }

    public function changeFor(int $id): ?ScenarioChange
    {
        return $this->changes[$id] ?? null;
    }

    /**
     * What the row was set to. A change whose amount did not validate is
     * still a change, shown with its error, and counts as Keep in the figures.
     */
    public function choiceFor(int $id): string
    {
        return match (true) {
            $this->cancels($id) => self::CANCEL,
            isset($this->input[$id]) => self::CHANGE,
            default => self::KEEP,
        };
    }

    /**
     * The ids a figure is worked out for: every cancellation and every valid
     * change, in id order.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        $ids = array_keys($this->cancellations + $this->changes);
        sort($ids);

        return $ids;
    }

    /**
     * @return array{price: string, cycle: string, days: string}
     */
    public function inputFor(int $id): array
    {
        return $this->input[$id] ?? ['price' => '', 'cycle' => '', 'days' => ''];
    }

    /**
     * @return array<string, string>
     */
    public function errorsFor(int $id): array
    {
        return $this->errors[$id] ?? [];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * The scenario as query parameters, in the short form — the valid choices
     * only, so a bookmark never carries a mistake.
     *
     * @return array<string, mixed>
     */
    public function query(): array
    {
        $query = [];
        if ($this->cancellations !== []) {
            $query['cancel'] = array_keys($this->cancellations);
        }

        foreach ($this->changes as $id => $change) {
            $entry = ['price' => $change->price->toDecimalString()];
            if ($change->cycle !== null) {
                $entry['cycle'] = $change->cycle->value;
                if ($change->cycleDays !== null) {
                    $entry['days'] = (string) $change->cycleDays;
                }
            }
            $query['change'][$id] = $entry;
        }

        return $query;
    }

    /**
     * `query()` as the string the brief writes it as —
     * `cancel[]=12&change[18][price]=9.99` — so a bookmark reads as it means.
     *
     * @param array<string, string> $extra Other parameters to carry, such as `mine`.
     */
    public function queryString(array $extra = []): string
    {
        $parts = [];
        foreach (array_keys($this->cancellations) as $id) {
            $parts[] = 'cancel[]=' . $id;
        }

        foreach ($this->query()['change'] ?? [] as $id => $entry) {
            foreach ($entry as $field => $value) {
                $parts[] = sprintf('change[%d][%s]=%s', $id, $field, rawurlencode($value));
            }
        }

        foreach ($extra as $name => $value) {
            $parts[] = rawurlencode($name) . '=' . rawurlencode($value);
        }

        return implode('&', $parts);
    }

    /**
     * @param array{price: string, cycle: string, days: string} $entry
     * @return array{0: ScenarioChange|null, 1: array<string, string>}
     */
    private static function change(array $entry, Subscription $subscription): array
    {
        $errors = [];
        $currency = $subscription->isTrial
            ? $subscription->priceAfterConversion()->currency
            : $subscription->price->currency;

        $price = null;
        try {
            $price = Money::fromUserInput($entry['price'], $currency);
            if ($price->isNegative()) {
                $errors['price'] = 'error.price.negative';
            }
        } catch (AmountTooLargeException) {
            $errors['price'] = 'error.price.too_large';
        } catch (InvalidArgumentException) {
            $errors['price'] = 'error.price.invalid';
        }

        $cycle = null;
        $days = null;
        if ($entry['cycle'] !== '') {
            $cycle = BillingCycle::tryFromString($entry['cycle']);
            if ($cycle === null) {
                $errors['cycle'] = 'error.cycle.required';
            } elseif ($cycle->requiresCycleDays()) {
                $days = ctype_digit($entry['days']) ? (int) $entry['days'] : 0;
                if ($days < 1 || $days > self::MAX_CYCLE_DAYS) {
                    $errors['days'] = 'error.cycle_days.range';
                }
            }
        }

        if ($errors !== [] || $price === null) {
            return [null, $errors];
        }

        return [new ScenarioChange($price, $cycle, $days), []];
    }

    private static function id(mixed $value): ?int
    {
        $text = self::text($value);

        return ctype_digit($text) && (int) $text > 0 ? (int) $text : null;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
