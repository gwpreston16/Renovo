<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\I18n\Translator;
use App\Application\Api\Resource;
use App\Domain\Entity\Category;
use App\Domain\Entity\PaymentMethod;
use App\Domain\Entity\Tag;
use App\Service\CategoryService;
use App\Service\PaymentMethodService;
use App\Service\TagService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

/**
 * Categories, payment methods and tags.
 *
 * Payment methods sit here for the reason categories do: a subscription refers
 * to one by id, and a client needs to be able to resolve it. Their logos are
 * uploaded and cleared on a path of their own, as a subscription's are,
 * because a file does not travel in a JSON body.
 *
 * They travel together because a subscription refers to all three and a client
 * that cannot resolve a `category_id` or a tag has only half an API. A tag can
 * be made here or by naming it on a subscription; both go through TagService,
 * so there is one rule for what counts as the same tag.
 */
final class TaxonomyApiController extends ApiController
{
    public function __construct(
        Translator $translator,
        private readonly CategoryService $categories,
        private readonly TagService $tags,
        private readonly PaymentMethodService $paymentMethods,
    ) {
        parent::__construct($translator);
    }

    public function categories(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, [
            'data' => array_map(
                static fn (Category $category): array => Resource::category($category),
                $this->categories->all($this->scope($request)),
            ),
        ]);
    }

    public function createCategory(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $body = $this->payload($request);

        $id = $this->categories->create(
            $scope,
            $this->string($body, 'name'),
            $this->nullableString($body, 'colour'),
        );

        return $this->json($response, ['data' => $this->find($request, $id)], 201);
    }

    public function updateCategory(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $body = $this->payload($request);

        $this->categories->rename(
            $scope,
            (int) $id,
            $this->string($body, 'name'),
            $this->nullableString($body, 'colour'),
        );

        return $this->json($response, ['data' => $this->find($request, (int) $id)]);
    }

    public function deleteCategory(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $this->categories->delete($this->scope($request), (int) $id);

        return $this->noContent($response);
    }

    public function paymentMethods(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, [
            'data' => array_map(
                static fn (PaymentMethod $method): array => Resource::paymentMethod($method),
                $this->paymentMethods->all($this->scope($request)),
            ),
        ]);
    }

    public function createPaymentMethod(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $body = $this->payload($request);

        $id = $this->paymentMethods->create(
            $scope,
            $this->string($body, 'name'),
            $this->nullableString($body, 'colour'),
        );

        return $this->json($response, ['data' => $this->findPaymentMethod($request, $id)], 201);
    }

    public function updatePaymentMethod(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $body = $this->payload($request);

        $this->paymentMethods->update(
            $scope,
            (int) $id,
            $this->string($body, 'name'),
            $this->nullableString($body, 'colour'),
        );

        return $this->json($response, ['data' => $this->findPaymentMethod($request, (int) $id)]);
    }

    /**
     * Replace the method's logo: multipart form data with a `logo` part, read
     * by LogoStorage from its contents exactly as the web form's upload is.
     */
    public function uploadPaymentMethodLogo(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $file = $request->getUploadedFiles()['logo'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            throw new HttpBadRequestException($request, $this->translator->trans('error.api.logo_part_missing'));
        }

        $this->paymentMethods->replaceLogo($this->scope($request), (int) $id, $file);

        return $this->json($response, ['data' => $this->findPaymentMethod($request, (int) $id)]);
    }

    public function deletePaymentMethodLogo(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $this->paymentMethods->clearLogo($this->scope($request), (int) $id);

        return $this->noContent($response);
    }

    public function deletePaymentMethod(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $this->paymentMethods->delete($this->scope($request), (int) $id);

        return $this->noContent($response);
    }

    public function tags(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, [
            'data' => array_map(
                static fn (Tag $tag): array => Resource::tag($tag),
                $this->tags->all($this->scope($request)),
            ),
        ]);
    }

    public function createTag(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $id = $this->tags->create($this->scope($request), $this->string($this->payload($request), 'name'));

        return $this->json($response, ['data' => $this->findTag($request, $id)], 201);
    }

    /**
     * Rename. Every subscription carrying the tag carries it by id, so each
     * of them shows the new name.
     */
    public function updateTag(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $this->tags->rename($this->scope($request), (int) $id, $this->string($this->payload($request), 'name'));

        return $this->json($response, ['data' => $this->findTag($request, (int) $id)]);
    }

    public function deleteTag(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $this->tags->delete($this->scope($request), (int) $id);

        return $this->noContent($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function find(ServerRequestInterface $request, int $id): array
    {
        foreach ($this->categories->all($this->scope($request)) as $category) {
            if ($category->id === $id) {
                return Resource::category($category);
            }
        }

        throw new HttpNotFoundException($request, $this->translator->trans('error.api.category_not_found'));
    }

    /**
     * @return array<string, mixed>
     */
    private function findTag(ServerRequestInterface $request, int $id): array
    {
        foreach ($this->tags->all($this->scope($request)) as $tag) {
            if ($tag->id === $id) {
                return Resource::tag($tag);
            }
        }

        throw new HttpNotFoundException($request, $this->translator->trans('error.api.tag_not_found'));
    }

    /**
     * @return array<string, mixed>
     */
    private function findPaymentMethod(ServerRequestInterface $request, int $id): array
    {
        $method = $this->paymentMethods->find($this->scope($request), $id);

        if ($method === null) {
            throw new HttpNotFoundException(
                $request,
                $this->translator->trans('error.api.payment_method_not_found'),
            );
        }

        return Resource::paymentMethod($method);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function string(array $body, string $key): string
    {
        $value = $body[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<string, mixed> $body
     */
    private function nullableString(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;

        if ($value === null || !is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
