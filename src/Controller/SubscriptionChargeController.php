<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\ChargeExportService;
use App\Service\ChargeService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The Payments card's actions: record what a charge really was, mark it as
 * one that did not happen, undo either, and add a charge that fell off the
 * cycle.
 *
 * Thin, as every controller is. `ChargeService` decides whether the scope may
 * change the charge and whether it belongs to the subscription the URL names;
 * this class turns the answer into a redirect back to the card.
 */
final class SubscriptionChargeController extends Controller
{
    /**
     * Where a refused payment form waits for the Payments card to reopen it
     * with what was typed (K78): the page is reached by a redirect, so what
     * was posted cannot ride along any other way.
     */
    public const FAILED_KEY = 'payments.failed';

    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly ChargeService $charges,
        private readonly ChargeExportService $export,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function add(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $write = fn () => $this->charges->addManual($this->scope($request), (int) $id, $this->body($request));

        return $this->act($request, $response, (int) $id, 'flash.charge_added', $write, ['form' => 'add']);
    }

    public function recordActual(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
        string $charge,
    ): ResponseInterface {
        $write = fn () => $this->charges->recordActual(
            $this->scope($request),
            (int) $charge,
            $this->body($request),
            (int) $id,
        );

        return $this->act(
            $request,
            $response,
            (int) $id,
            'flash.charge_recorded',
            $write,
            ['form' => 'actual', 'charge' => (int) $charge],
        );
    }

    public function skip(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
        string $charge,
    ): ResponseInterface {
        $write = fn () => $this->charges->skip($this->scope($request), (int) $charge, $this->body($request), (int) $id);

        return $this->act($request, $response, (int) $id, 'flash.charge_skipped', $write);
    }

    public function undo(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
        string $charge,
    ): ResponseInterface {
        $write = fn () => $this->charges->undo($this->scope($request), (int) $charge, (int) $id);

        // A charge put back says so; a payment added by hand is removed.
        $said = static fn (mixed $result): string => $result === null ? 'flash.charge_removed' : 'flash.charge_undone';

        return $this->act($request, $response, (int) $id, $said, $write);
    }

    /**
     * Every payment in scope, as CSV.
     */
    public function export(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->export->csv($this->scope($request)));

        return $this->download($response, 'text/csv; charset=utf-8', 'csv');
    }

    /**
     * The same rows as `export()`, as JSON.
     */
    public function exportJson(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->export->json($this->scope($request)));

        return $this->download($response, 'application/json; charset=utf-8', 'json');
    }

    /**
     * @param 'csv'|'json' $format
     */
    private function download(ResponseInterface $response, string $type, string $format): ResponseInterface
    {
        return $response
            ->withHeader('Content-Type', $type)
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . $this->export->filename($this->charges->today(), $format) . '"',
            )
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Run one write and go back to the Payments card, with its message or its
     * errors.
     *
     * @param string|\Closure(mixed): string $success The message, or how to
     *                                            pick it from what the write returned.
     * @param callable(): mixed $write
     * @param array{form: string, charge?: int}|null $form The form to reopen
     *                                            with what was typed, when it is refused.
     */
    private function act(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id,
        string|\Closure $success,
        callable $write,
        ?array $form = null,
    ): ResponseInterface {
        try {
            $result = $write();
            $this->flash('success', is_string($success) ? $success : $success($result));
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);

            if ($form !== null) {
                $body = $this->body($request);
                $this->session->set(self::FAILED_KEY, [
                    'subscription' => $id,
                    'form' => $form['form'],
                    'charge' => $form['charge'] ?? null,
                    'values' => array_intersect_key($body, array_flip(['date', 'amount', 'note', 'actual'])),
                ]);
            }
        }

        return $this->redirectAfterWrite($request, $response, '/subscriptions/' . $id . '/money#payments');
    }
}
