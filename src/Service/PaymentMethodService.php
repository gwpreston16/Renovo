<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\DefaultPaymentMethods;
use App\Domain\Entity\PaymentMethod;
use App\Domain\Entity\User;
use App\Domain\NameKey;
use App\Persistence\Database;
use App\I18n\Locales;
use App\I18n\Translator;
use App\Repository\PaymentMethodRepository;
use App\Security\Scope;
use App\Security\ScopeFactory;
use App\Security\ScopeViolationException;
use PDOException;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The household's list of payment methods.
 *
 * Categories' rules, deliberately: the same name validation, the same
 * case- and accent-insensitive duplicate check, the same colour format. Where a decision
 * was already made for categories it is made the same way here rather than a
 * second time.
 *
 * The one addition is the picture. An uploaded logo goes through LogoStorage —
 * type from the bytes, size-capped, a name this application chose — and is
 * removed from disk when it is replaced, cleared, or its method is deleted.
 */
final class PaymentMethodService
{
    public function __construct(
        private readonly PaymentMethodRepository $methods,
        private readonly LogoStorage $logos,
        private readonly Translator $translator,
        private readonly Locales $locales,
        private readonly ScopeFactory $scopes,
    ) {
    }

    /**
     * @return list<PaymentMethod>
     */
    public function all(Scope $scope): array
    {
        return $this->methods->findAll($scope);
    }

    public function find(Scope $scope, int $id): ?PaymentMethod
    {
        return $this->methods->find($scope, $id);
    }

    /**
     * @throws ValidationException
     */
    public function create(
        Scope $scope,
        string $name,
        ?string $colour,
        ?UploadedFileInterface $logo = null,
        ?string $icon = null,
    ): int {
        $name = trim($name);
        $this->assertValid($scope, $name, $colour);

        // After validation, so a rejected name does not leave an orphaned file.
        $logoPath = $this->logos->store($logo);

        try {
            return $this->methods->create(
                $scope,
                $name,
                $this->normaliseColour($colour),
                $this->normaliseIcon($icon),
                $logoPath,
            );
        } catch (PDOException $exception) {
            $this->logos->delete($logoPath);

            throw $this->duplicateOrRethrow($exception);
        }
    }

    /**
     * Rename and recolour, and — when a file arrives — replace the logo.
     *
     * @throws ValidationException
     */
    public function update(
        Scope $scope,
        int $id,
        string $name,
        ?string $colour,
        ?UploadedFileInterface $logo = null,
    ): void {
        $existing = $this->methods->find($scope, $id) ?? throw ScopeViolationException::forRow('payment_methods', $id);

        $name = trim($name);
        $this->assertValid($scope, $name, $colour, $id);

        $logoPath = $this->logos->store($logo);

        try {
            $this->methods->update($scope, $id, $name, $this->normaliseColour($colour));
        } catch (PDOException $exception) {
            $this->logos->delete($logoPath);

            throw $this->duplicateOrRethrow($exception);
        }

        if ($logoPath !== null) {
            $this->methods->setLogo($scope, $id, $logoPath);
            $this->logos->release($existing->logoPath);
        }
    }

    /**
     * Replace the logo and nothing else — the API's path, which uploads a
     * file on its own rather than with the name and colour.
     *
     * @throws ValidationException when the file is not an image LogoStorage accepts.
     */
    public function replaceLogo(Scope $scope, int $id, UploadedFileInterface $logo): void
    {
        $existing = $this->methods->find($scope, $id) ?? throw ScopeViolationException::forRow('payment_methods', $id);

        // A part that arrived with no file in it: there is nothing to replace
        // the logo with, and answering success would say otherwise.
        $logoPath = $this->logos->store($logo)
            ?? throw ValidationException::field('logo', 'error.logo.type');

        $this->methods->setLogo($scope, $id, $logoPath);
        $this->logos->release($existing->logoPath);
    }

    /**
     * Put a restored logo on a method — the backup path, where the file is
     * already on disk and has already been through LogoStorage.
     */
    public function attachStoredLogo(Scope $scope, int $id, string $storedPath): void
    {
        $this->methods->setLogo($scope, $id, $storedPath);
    }

    public function clearLogo(Scope $scope, int $id): void
    {
        $existing = $this->methods->find($scope, $id) ?? throw ScopeViolationException::forRow('payment_methods', $id);

        $this->methods->setLogo($scope, $id, null);
        $this->logos->release($existing->logoPath);
    }

    /**
     * Remove a method. Its subscriptions are unassigned by the foreign key
     * (`ON DELETE SET NULL`); none of them is deleted.
     */
    public function delete(Scope $scope, int $id): void
    {
        $existing = $this->methods->find($scope, $id) ?? throw ScopeViolationException::forRow('payment_methods', $id);

        $this->methods->delete($scope, $id);
        $this->logos->release($existing->logoPath);
    }

    /**
     * Give a household the default list — once.
     *
     * Called when a household is created, and from the management screen's
     * offer when the list is empty. A household that already has any method at
     * all is left alone: this never tops a list up, never restores a default
     * somebody removed on purpose, and cannot seed twice.
     *
     * @param string|null $locale The language the names are written in; the
     *        instance default when null or no longer available.
     * @return array<string, int> What was created, catalogue key => id; empty
     *         when the household already had methods.
     */
    public function seedDefaults(Scope $scope, ?string $locale = null): array
    {
        if ($this->methods->findAll($scope) !== []) {
            return [];
        }

        $locale = $this->locales->resolve($locale);
        $created = [];

        foreach (DefaultPaymentMethods::all() as $default) {
            $created[$default['key']] = $this->methods->create(
                $scope,
                $this->translator->trans($default['key'], [], $locale),
                null,
                $default['icon'],
                null,
            );
        }

        return $created;
    }

    /**
     * The defaults for a household that has just been created, by the member
     * who created it and in their language.
     *
     * Registration and the setup wizard both make a household outside any
     * request scope — nobody is signed in yet — so the scope is built here
     * from the new owner, the same way a request would build it.
     */
    /**
     * @return array<string, int> Catalogue key => id, as `seedDefaults()`.
     */
    public function seedForNewHousehold(User $owner, int $householdId): array
    {
        return $this->seedDefaults($this->scopes->forUser($owner, $householdId), $owner->locale);
    }

    /**
     * @throws ValidationException
     */
    private function assertValid(Scope $scope, string $name, ?string $colour, ?int $ignoreId = null): void
    {
        if ($name === '') {
            throw ValidationException::field('name', 'error.payment_method.name_required');
        }
        if (mb_strlen($name) > 60) {
            throw ValidationException::field('name', 'error.name.too_long_60');
        }

        // Against the whole list rather than a name search: the repository's
        // search is a substring match, so asking it for "card" can answer with
        // "Credit Card" and never see the "Card" that is the real duplicate.
        // The list is a household's handful of labels, so reading it is cheap.
        foreach ($this->methods->findAll($scope) as $existing) {
            if ($existing->id !== $ignoreId && NameKey::same($existing->name, $name)) {
                throw ValidationException::field('name', 'error.payment_method.duplicate');
            }
        }

        if ($colour !== null && $colour !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $colour) !== 1) {
            throw ValidationException::field('colour', 'error.category.colour_required');
        }
    }

    private function duplicateOrRethrow(PDOException $exception): ValidationException|PDOException
    {
        return Database::isUniqueViolation($exception)
            ? ValidationException::field('name', 'error.payment_method.duplicate')
            : $exception;
    }

    private function normaliseColour(?string $colour): ?string
    {
        return $colour === null || $colour === '' ? null : strtolower($colour);
    }

    private function normaliseIcon(?string $icon): ?string
    {
        return $icon !== null && in_array($icon, DefaultPaymentMethods::ICONS, true) ? $icon : null;
    }
}
