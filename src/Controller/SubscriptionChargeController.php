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

        return $this->act($request, $response, (int) $id, 'flash.charge_added', $write);
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

        return $this->act($request, $response, (int) $id, 'flash.charge_recorded', $write);
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

        return $this->act($request, $response, (int) $id, 'flash.charge_undone', $write);
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
     * @param callable(): mixed $write
     */
    private function act(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id,
        string $success,
        callable $write,
    ): ResponseInterface {
        try {
            $write();
            $this->flash('success', $success);
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);
        }

        return $this->redirectAfterWrite($request, $response, '/subscriptions/' . $id . '/money#payments');
    }
}
