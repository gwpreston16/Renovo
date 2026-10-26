<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Security\ScopeViolationException;
use App\Security\SessionInterface;
use App\Service\CategoryService;
use App\Service\SettingsScreenService;
use App\Service\TagService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The household's categories and tags, managed from two sections of the
 * Settings page's General tab (Phase 28). They had a screen of their own until
 * then; its path redirects to the section.
 *
 * A form that fails validation redraws the whole General tab with the error
 * beside the field that caused it, and what was typed still in it — `failed`
 * names the section and the row, so the tab opens that row's rename rather
 * than leaving the reader to find it.
 */
final class CategoryController extends Controller
{
    private const CATEGORIES = SettingsController::GENERAL . '#categories';
    private const TAGS = SettingsController::GENERAL . '#tags';

    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly CategoryService $categories,
        private readonly TagService $tags,
        private readonly SettingsScreenService $screen,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function moved(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->redirect($response, self::CATEGORIES);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $this->categories->create(
                $this->scope($request),
                $this->string($body, 'name'),
                $this->colour($body),
            );
        } catch (ValidationException $exception) {
            return $this->failed($request, $response, $exception, $body, 'categories');
        }

        $this->flash('success', 'flash.category_added');

        return $this->redirectAfterWrite($request, $response, self::CATEGORIES);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $this->categories->rename(
                $this->scope($request),
                (int) $id,
                $this->string($body, 'name'),
                $this->colour($body),
            );
        } catch (ValidationException $exception) {
            return $this->failed($request, $response, $exception, $body, 'categories', (int) $id);
        } catch (ScopeViolationException) {
            throw $this->notFound($request);
        }

        $this->flash('success', 'flash.category_renamed');

        return $this->redirectAfterWrite($request, $response, self::CATEGORIES);
    }

    /**
     * Deleting a category leaves its subscriptions uncategorised; the
     * foreign key sets them to null rather than taking them with it.
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        try {
            $this->categories->delete($this->scope($request), (int) $id);
        } catch (ScopeViolationException) {
            throw $this->notFound($request);
        }

        $this->flash('success', 'flash.category_deleted');

        return $this->redirectAfterWrite($request, $response, self::CATEGORIES);
    }

    public function createTag(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $this->tags->create($this->scope($request), $this->string($body, 'name'));
        } catch (ValidationException $exception) {
            return $this->failed($request, $response, $exception, $body, 'tags');
        }

        $this->flash('success', 'flash.tag_added');

        return $this->redirectAfterWrite($request, $response, self::TAGS);
    }

    public function renameTag(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $body = $this->body($request);

        try {
            $this->tags->rename($this->scope($request), (int) $id, $this->string($body, 'name'));
        } catch (ValidationException $exception) {
            return $this->failed($request, $response, $exception, $body, 'tags', (int) $id);
        } catch (ScopeViolationException) {
            throw $this->notFound($request);
        }

        $this->flash('success', 'flash.tag_renamed');

        return $this->redirectAfterWrite($request, $response, self::TAGS);
    }

    public function deleteTag(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        try {
            $this->tags->delete($this->scope($request), (int) $id);
        } catch (ScopeViolationException) {
            throw $this->notFound($request);
        }

        $this->flash('success', 'flash.tag_deleted');

        return $this->redirectAfterWrite($request, $response, self::TAGS);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function failed(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ValidationException $exception,
        array $body,
        string $section,
        ?int $id = null,
    ): ResponseInterface {
        return $this->render($request, $response->withStatus(422), 'settings/general.twig', [
            ...$this->screen->general($this->scope($request)),
            'errors' => $exception->errors(),
            'values' => $body,
            'failed' => ['section' => $section, 'id' => $id],
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function string(array $body, string $key): string
    {
        return is_scalar($body[$key] ?? null) ? (string) $body[$key] : '';
    }

    /**
     * The colour the form asked for, or null for "automatic" — a checkbox,
     * because a colour input always submits some colour.
     *
     * @param array<string, mixed> $body
     */
    private function colour(array $body): ?string
    {
        if (($body['colour_auto'] ?? null) === '1') {
            return null;
        }

        return is_scalar($body['colour'] ?? null) ? (string) $body['colour'] : null;
    }
}
