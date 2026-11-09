<?php

declare(strict_types=1);

namespace App\Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Assertions that a validation error on a rendered page is tied to its field
 * the way a screen reader needs (WCAG 1.3.1 / 3.3.1): the input says it is
 * invalid, and its `aria-describedby` names a real element that holds the
 * message. A `<p class="field-error">` merely sitting near an input proves
 * neither, which is the failure these exist to catch.
 *
 * Used by test cases, so `self::` here resolves to PHPUnit's assertions.
 */
trait FieldErrorAssertions
{
    /**
     * Assert that the input at `$inputXpath` is `aria-invalid="true"` and is
     * described by an element, present on the page, whose text contains
     * `$errorText`.
     *
     * @return string The id of the element that holds the error.
     */
    private function assertInputLinkedToError(string $html, string $inputXpath, string $errorText): string
    {
        $document = new DOMDocument();
        // The page is HTML5 and the old parser grumbles about its tags; the
        // markup is still read correctly, so the complaints are discarded.
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $inputs = $xpath->query($inputXpath);
        self::assertNotFalse($inputs);
        self::assertSame(1, $inputs->length, 'Expected exactly one input for ' . $inputXpath);

        $input = $inputs->item(0);
        self::assertInstanceOf(DOMElement::class, $input);
        self::assertSame('true', $input->getAttribute('aria-invalid'), 'The input is not marked invalid.');

        $ids = array_filter(explode(' ', $input->getAttribute('aria-describedby')));
        self::assertNotSame([], $ids, 'The input has no aria-describedby.');

        foreach ($ids as $id) {
            // A described-by id that matches nothing, or two things, is as
            // bad as none: the reference has to resolve to one element.
            $targets = $xpath->query('//*[@id="' . $id . '"]');
            self::assertNotFalse($targets);
            self::assertSame(1, $targets->length, 'aria-describedby "' . $id . '" is not a unique element.');

            if (str_contains((string) $targets->item(0)?->textContent, $errorText)) {
                return $id;
            }
        }

        self::fail('No element named by aria-describedby ("' . implode(' ', $ids) . '") contains: ' . $errorText);
    }
}
