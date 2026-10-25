<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Domain\AuditAction;
use App\Persistence\Criteria;
use App\Security\SessionInterface;
use App\Service\AuditLogService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The audit log, as far as the reader is allowed to see it.
 *
 * The controller does not choose the rows: it hands the scope to the service,
 * and the repository decides. An instance administrator gets the instance-wide
 * view; a household Owner gets their household's events. That distinction lives
 * one layer down precisely so that this route cannot get it wrong.
 */
final class AuditLogController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly AuditLogService $audit,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $params = $request->getQueryParams();

        $page = Criteria::clampPage((int) ($params['page'] ?? 1));
        $action = AuditAction::tryFrom(is_string($params['action'] ?? null) ? $params['action'] : '');
        $filter = $action === null ? [] : [$action];
        $selected = $action === null ? '' : $action->value;

        $total = $this->audit->count($scope, $filter);

        return $this->renderMaybeFragment($request, $response, 'audit/index.twig', 'audit/_entries.twig', [
            'entries' => $this->audit->page($scope, $filter, $page, self::PER_PAGE),
            'actions' => AuditAction::cases(),
            'selected_action' => $selected,
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'is_instance_view' => $scope->isInstanceAdmin,
        ]);
    }
}
