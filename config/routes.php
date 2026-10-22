<?php

/**
 * Routes.
 *
 * Note the shape of the authenticated group: every mutating route names the
 * permission it needs, and that permission is checked by middleware before the
 * controller runs. A route added without one would still be behind
 * authentication, but the convention here is that a write always declares what
 * it requires — a Viewer reaching any of these gets a 403.
 */

declare(strict_types=1);

use App\Application\Api\ApiPath;
use App\Application\Middleware\ApiAuthenticationMiddleware;
use App\Application\Middleware\AuthenticationMiddleware;
use App\Application\Middleware\FeedAuthenticationMiddleware;
use App\Application\Middleware\PasswordChangeRequiredMiddleware;
use App\Application\Middleware\RequirePermissionMiddleware;
use App\Application\Ops\OpsPath;
use App\Controller\Api\AttachmentApiController;
use App\Controller\Api\ChargeApiController;
use App\Controller\Api\CalendarApiController;
use App\Controller\Api\MeApiController;
use App\Controller\Api\OpenApiController;
use App\Controller\Api\SubscriptionApiController;
use App\Controller\Api\TaxonomyApiController;
use App\Controller\AccountController;
use App\Controller\ApiTokenController;
use App\Controller\AttachmentController;
use App\Controller\AuditLogController;
use App\Controller\BackupController;
use App\Controller\Auth\LoginController;
use App\Controller\Auth\PasskeyLoginController;
use App\Controller\Auth\EmailChangeController;
use App\Controller\Auth\InviteController;
use App\Controller\Auth\PasswordResetController;
use App\Controller\Auth\RegisterController;
use App\Controller\Auth\TwoFactorController;
use App\Controller\Auth\VerifyEmailController;
use App\Controller\BudgetController;
use App\Controller\CalendarController;
use App\Controller\CancellationController;
use App\Controller\CategoryController;
use App\Controller\PaymentMethodController;
use App\Controller\DashboardController;
use App\Controller\ForecastController;
use App\Controller\ImportController;
use App\Controller\MemberController;
use App\Controller\NotificationController;
use App\Controller\Ops\HealthController;
use App\Controller\Ops\MetricsController;
use App\Controller\ProfileController;
use App\Controller\SavedViewController;
use App\Controller\SecurityController;
use App\Controller\SettingsController;
use App\Controller\SetupController;
use App\Controller\StatsController;
use App\Controller\SubscriptionController;
use App\Controller\SubscriptionChargeController;
use App\Controller\SubscriptionMoneyController;
use App\Domain\Permission;
// Imported by name: `App\Security\...` would resolve against the `Slim\App`
// import below rather than the application's root namespace.
use App\Security\PermissionService;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $container = $app->getContainer();
    if (!$container instanceof ContainerInterface) {
        throw new RuntimeException('The application has no container.');
    }

    $requires = static fn (Permission $permission): RequirePermissionMiddleware
        => new RequirePermissionMiddleware($container->get(PermissionService::class), $permission);

    // ----------------------------------------------------------------------
    // First-run wizard (closed once the instance has an administrator)
    // ----------------------------------------------------------------------
    $app->get('/setup', [SetupController::class, 'showForm'])->setName('setup');
    $app->post('/setup', [SetupController::class, 'submit']);

    // ----------------------------------------------------------------------
    // Operations: liveness, readiness and metrics
    //
    // Outside every group. The caller is an orchestrator or a scrape job with
    // no session, no token in a cookie and nothing to be redirected with — and
    // SetupGuardMiddleware lets these three through explicitly, so a container
    // that has never been configured still reports honestly instead of
    // answering "302 to /setup".
    //
    // Liveness and readiness are open and say only whether this instance is
    // serving. /metrics has numbers on it and is not: see MetricsController.
    // ----------------------------------------------------------------------
    $app->get(OpsPath::LIVENESS, [HealthController::class, 'live'])->setName('healthz');
    $app->get(OpsPath::READINESS, [HealthController::class, 'ready'])->setName('readyz');
    $app->get(OpsPath::METRICS, [MetricsController::class, 'show'])->setName('metrics');

    // ----------------------------------------------------------------------
    // Public authentication routes
    // ----------------------------------------------------------------------
    $app->get('/login', [LoginController::class, 'showForm'])->setName('login');
    $app->post('/login', [LoginController::class, 'submit']);

    // The second-factor step and the usernameless passkey login are public by
    // necessity: nobody is signed in yet. What identifies the account is the
    // short-lived pending challenge in the session (for the second factor) or
    // the credential itself (for a passkey), never a parameter on the request.
    $app->get('/login/two-factor', [TwoFactorController::class, 'showChallenge'])->setName('two-factor');
    $app->post('/login/two-factor', [TwoFactorController::class, 'submit']);
    $app->post('/login/two-factor/cancel', [TwoFactorController::class, 'cancel']);
    $app->post('/login/two-factor/passkey/options', [TwoFactorController::class, 'passkeyOptions']);
    $app->post('/login/two-factor/passkey', [TwoFactorController::class, 'passkeyVerify']);

    $app->post('/login/passkey/options', [PasskeyLoginController::class, 'options']);
    $app->post('/login/passkey', [PasskeyLoginController::class, 'verify']);

    $app->get('/register', [RegisterController::class, 'showForm'])->setName('register');
    $app->post('/register', [RegisterController::class, 'submit']);

    $app->get('/verify-email', [VerifyEmailController::class, 'verify'])->setName('verify-email');
    // "Send the confirmation again", from the page registration ends on. Shaped
    // like the reset request: the same answer whether or not the address has
    // an account waiting, under a rate limit of its own.
    $app->post('/verify-email/resend', [VerifyEmailController::class, 'resend']);

    $app->get('/forgot-password', [PasswordResetController::class, 'showRequestForm'])->setName('forgot-password');
    $app->post('/forgot-password', [PasswordResetController::class, 'submitRequest']);
    $app->get('/reset-password', [PasswordResetController::class, 'showResetForm'])->setName('reset-password');
    $app->post('/reset-password', [PasswordResetController::class, 'submitReset']);

    // Phase 15. Both are followed by somebody who is not signed in — an invited
    // member who has no password yet, and a member reading a confirmation on
    // whichever device their mail is on. In each case the token in the link is
    // the entire credential, which is why it is single-use and short-lived.
    $app->get('/accept-invite', [InviteController::class, 'showForm'])->setName('accept-invite');
    $app->post('/accept-invite', [InviteController::class, 'submit']);

    $app->get('/confirm-email-change', [EmailChangeController::class, 'confirm'])
        ->setName('confirm-email-change');

    // ----------------------------------------------------------------------
    // Everything below requires a signed-in user
    // ----------------------------------------------------------------------
    // Not a static closure: Slim binds the group callable to the container,
    // and a static closure cannot be bound.
    $app->group('', function (RouteCollectorProxy $group) use ($requires): void {
        $group->get('/', [DashboardController::class, 'index'])->setName('dashboard');

        // Which dashboard the account opens on. A personal preference, like the
        // theme and palette under /profile, so it needs no permission.
        $group->post('/dashboard/view', [DashboardController::class, 'updateView']);
        // Which cards that dashboard shows and in what order, arranged on the
        // dashboard itself. Personal in the same way, so no permission either.
        $group->post('/dashboard/layout', [DashboardController::class, 'updateLayout']);

        // Inside the authenticated group, unlike the login routes: signing out
        // is something a signed-in user does, and the audit entry needs to know
        // who did it.
        $group->post('/logout', [LoginController::class, 'logout'])->setName('logout');

        $group->get('/subscriptions', [SubscriptionController::class, 'index'])
            ->setName('subscriptions')
            ->add($requires(Permission::ViewSubscriptions));

        // The list as a CSV file: the same filter and the same scoped query, so
        // it holds no row the list itself would not show.
        $group->get('/subscriptions/export.csv', [SubscriptionController::class, 'export'])
            ->setName('subscriptions-export')
            ->add($requires(Permission::ViewSubscriptions));

        // The same rows as JSON, under the same headings, for Settings' Export
        // section (Phase 28).
        $group->get('/subscriptions/export.json', [SubscriptionController::class, 'exportJson'])
            ->setName('subscriptions-export-json')
            ->add($requires(Permission::ViewSubscriptions));

        // Phase 34: the payment ledger, as a file, for Settings' Export card.
        $group->get('/payments/export.csv', [SubscriptionChargeController::class, 'export'])
            ->setName('payments-export')
            ->add($requires(Permission::ViewSubscriptions));

        $group->get('/payments/export.json', [SubscriptionChargeController::class, 'exportJson'])
            ->setName('payments-export-json')
            ->add($requires(Permission::ViewSubscriptions));

        // The form's "≈ base at today's rate" note. It discloses an exchange
        // rate and nothing about any subscription.
        $group->get('/subscriptions/conversion-note', [SubscriptionController::class, 'conversionNote'])
            ->add($requires(Permission::ViewSubscriptions));

        // The list's density toggle — a personal preference, like the theme,
        // so it needs no permission.
        $group->post('/subscriptions/density', [SubscriptionController::class, 'density']);

        $group->get('/subscriptions/new', [SubscriptionController::class, 'createForm'])
            ->setName('subscription-new')
            ->add($requires(Permission::CreateSubscription));

        $group->post('/subscriptions', [SubscriptionController::class, 'create'])
            ->add($requires(Permission::CreateSubscription));

        $group->get('/subscriptions/{id:[0-9]+}/edit', [SubscriptionController::class, 'editForm'])
            ->setName('subscription-edit')
            ->add($requires(Permission::UpdateSubscription));

        $group->post('/subscriptions/{id:[0-9]+}', [SubscriptionController::class, 'update'])
            ->add($requires(Permission::UpdateSubscription));

        $group->post('/subscriptions/{id:[0-9]+}/toggle', [SubscriptionController::class, 'toggle'])
            ->add($requires(Permission::UpdateSubscription));

        $group->post('/subscriptions/{id:[0-9]+}/cancel', [SubscriptionController::class, 'cancel'])
            ->add($requires(Permission::UpdateSubscription));

        $group->post('/subscriptions/{id:[0-9]+}/uncancel', [SubscriptionController::class, 'uncancel'])
            ->add($requires(Permission::UpdateSubscription));

        $group->post('/subscriptions/{id:[0-9]+}/delete', [SubscriptionController::class, 'delete'])
            ->add($requires(Permission::DeleteSubscription));

        // ------------------------------------------------------------------
        // Phase 2: money
        //
        // Reading a budget, a forecast or a statistic needs no permission
        // beyond ViewSubscriptions — every figure is derived from data the
        // scope can already see, so none of them discloses anything new. Each
        // write names the permission it needs, as everywhere else.
        // ------------------------------------------------------------------
        $group->post('/subscriptions/bulk', [SubscriptionController::class, 'bulk'])
            ->setName('subscriptions-bulk')
            ->add($requires(Permission::BulkEdit));

        $group->get('/subscriptions/{id:[0-9]+}/money', [SubscriptionMoneyController::class, 'show'])
            ->setName('subscription-money')
            ->add($requires(Permission::ViewSubscriptions));

        $group->post(
            '/subscriptions/{id:[0-9]+}/price-changes',
            [SubscriptionMoneyController::class, 'schedulePriceChange'],
        )->add($requires(Permission::ManagePrices));

        // Phase 34: the payment ledger. Recording, skipping, undoing and
        // adding a charge are all writes to a row's money.
        $group->post('/subscriptions/{id:[0-9]+}/charges', [SubscriptionChargeController::class, 'add'])
            ->add($requires(Permission::ManageCharges));

        $group->post(
            '/subscriptions/{id:[0-9]+}/charges/{charge:[0-9]+}/actual',
            [SubscriptionChargeController::class, 'recordActual'],
        )->add($requires(Permission::ManageCharges));

        $group->post(
            '/subscriptions/{id:[0-9]+}/charges/{charge:[0-9]+}/skip',
            [SubscriptionChargeController::class, 'skip'],
        )->add($requires(Permission::ManageCharges));

        $group->post(
            '/subscriptions/{id:[0-9]+}/charges/{charge:[0-9]+}/undo',
            [SubscriptionChargeController::class, 'undo'],
        )->add($requires(Permission::ManageCharges));

        $group->post('/subscriptions/{id:[0-9]+}/split', [SubscriptionMoneyController::class, 'updateSplit'])
            ->add($requires(Permission::ManageSplits));

        $group->post('/subscriptions/{id:[0-9]+}/usage', [SubscriptionMoneyController::class, 'recordUse'])
            ->add($requires(Permission::RecordUsage));

        $group->post('/subscriptions/{id:[0-9]+}/usage/rating', [SubscriptionMoneyController::class, 'rate'])
            ->add($requires(Permission::RecordUsage));

        $group->post('/subscriptions/{id:[0-9]+}/usage/reset', [SubscriptionMoneyController::class, 'resetUsage'])
            ->add($requires(Permission::RecordUsage));

        $group->get('/budgets', [BudgetController::class, 'index'])
            ->setName('budgets')
            ->add($requires(Permission::ViewSubscriptions));

        $group->get('/budgets/new', [BudgetController::class, 'createForm'])
            ->setName('budget-new')
            ->add($requires(Permission::ManageBudgets));

        $group->post('/budgets', [BudgetController::class, 'create'])
            ->add($requires(Permission::ManageBudgets));

        $group->get('/budgets/{id:[0-9]+}/edit', [BudgetController::class, 'editForm'])
            ->setName('budget-edit')
            ->add($requires(Permission::ManageBudgets));

        $group->post('/budgets/{id:[0-9]+}', [BudgetController::class, 'update'])
            ->add($requires(Permission::ManageBudgets));

        $group->post('/budgets/{id:[0-9]+}/delete', [BudgetController::class, 'delete'])
            ->add($requires(Permission::ManageBudgets));

        $group->get('/forecast', [ForecastController::class, 'index'])
            ->setName('forecast')
            ->add($requires(Permission::ViewSubscriptions));

        // Phase 31: the scenario planner. It writes nothing, so it needs no
        // more than reading the forecast does — the selection route included,
        // which is a POST only because the bulk bar carries a CSRF token.
        $group->get('/forecast/scenario', [ForecastController::class, 'scenario'])
            ->setName('forecast-scenario')
            ->add($requires(Permission::ViewSubscriptions));

        $group->post('/forecast/scenario/selection', [ForecastController::class, 'selection'])
            ->setName('forecast-scenario-selection')
            ->add($requires(Permission::ViewSubscriptions));

        // ------------------------------------------------------------------
        // Phase 6: the calendar, saved views and preferences
        // ------------------------------------------------------------------
        $group->get('/calendar', [CalendarController::class, 'index'])
            ->setName('calendar')
            ->add($requires(Permission::ViewSubscriptions));

        // Phase 25: the calendar feed's link. Like the API-token routes it acts
        // only on the member's own read-only token, so it names no permission.
        $group->post('/calendar/feed-link', [CalendarController::class, 'replaceFeed'])
            ->setName('calendar-feed-link');

        // A saved view is one account's way of looking at the list. It names
        // no permission for the same reason a theme does not — but it is still
        // a list of subscriptions, so seeing the page it points at needs the
        // usual role.
        $group->post('/saved-views', [SavedViewController::class, 'create'])
            ->add($requires(Permission::ViewSubscriptions));

        $group->post('/saved-views/{id:[0-9]+}/delete', [SavedViewController::class, 'delete'])
            ->add($requires(Permission::ViewSubscriptions));

        $group->get('/cancellations', [CancellationController::class, 'index'])
            ->setName('cancellations')
            ->add($requires(Permission::ViewSubscriptions));

        $group->get('/stats', [StatsController::class, 'index'])
            ->setName('stats')
            ->add($requires(Permission::ViewSubscriptions));

        // Categories, tags and payment methods are sections of Settings'
        // General tab since Phase 28. The two screens they had redirect there
        // for bookmarks, asking for nothing themselves: the page they land on
        // asks for its own. Every write stays where it was, and returns to
        // its section.
        $group->get('/categories', [CategoryController::class, 'moved'])->setName('categories');

        $group->post('/categories', [CategoryController::class, 'create'])
            ->add($requires(Permission::ManageCategories));

        $group->post('/categories/{id:[0-9]+}', [CategoryController::class, 'update'])
            ->add($requires(Permission::ManageCategories));

        $group->post('/categories/{id:[0-9]+}/delete', [CategoryController::class, 'delete'])
            ->add($requires(Permission::ManageCategories));

        $group->post('/tags', [CategoryController::class, 'createTag'])
            ->add($requires(Permission::ManageTags));

        $group->post('/tags/{id:[0-9]+}', [CategoryController::class, 'renameTag'])
            ->add($requires(Permission::ManageTags));

        $group->post('/tags/{id:[0-9]+}/delete', [CategoryController::class, 'deleteTag'])
            ->add($requires(Permission::ManageTags));

        // Payment methods are the same kind of household metadata as
        // categories, and the same people look after them.
        $group->get('/payment-methods', [PaymentMethodController::class, 'moved'])->setName('payment-methods');

        $group->post('/payment-methods', [PaymentMethodController::class, 'create'])
            ->add($requires(Permission::ManageCategories));

        $group->post('/payment-methods/defaults', [PaymentMethodController::class, 'seedDefaults'])
            ->add($requires(Permission::ManageCategories));

        $group->post('/payment-methods/{id:[0-9]+}', [PaymentMethodController::class, 'update'])
            ->add($requires(Permission::ManageCategories));

        $group->post('/payment-methods/{id:[0-9]+}/logo/clear', [PaymentMethodController::class, 'clearLogo'])
            ->add($requires(Permission::ManageCategories));

        $group->post('/payment-methods/{id:[0-9]+}/delete', [PaymentMethodController::class, 'delete'])
            ->add($requires(Permission::ManageCategories));

        // Your own page. Changing how Renovo looks to you needs no permission
        // beyond being signed in: it alters what one account sees and nothing
        // that anybody else does, which is why it is not under /settings —
        // everything that is needs somebody's authority.
        $group->get('/profile', [ProfileController::class, 'index'])->setName('profile');
        $group->post('/profile/theme', [ProfileController::class, 'updateTheme']);
        // Theme, palette, density, week start, language and landing page, as
        // one form: with script it posts on each change and restyles the page
        // in place, without it one button sends the lot.
        $group->post('/profile/preferences', [ProfileController::class, 'updatePreferences']);

        // Phase 15: account self-service. No permission on any of them, by the
        // same rule as the preferences above and the security routes below —
        // each acts on the id in the session and takes no argument that could
        // point it at another account.
        //
        // `/profile/account` was the page these forms lived on; they are on
        // `/profile` now. The path stays as a redirect rather than becoming a
        // 404, because an email-change confirmation that has already been sent
        // points a reader here and those links outlive a rearrangement of the
        // screens. 302 rather than 301: a permanent redirect is cached by the
        // browser for as long as it likes, which is a long time to commit to
        // for a layout decision.
        $group->get('/profile/account', [AccountController::class, 'moved'])->setName('account');
        // Name and address together, as the page draws them (Phase 27).
        $group->post('/profile/details', [AccountController::class, 'updateDetails']);
        $group->post('/profile/email/resend', [AccountController::class, 'resendEmailChange']);
        $group->post('/profile/email/cancel', [AccountController::class, 'cancelEmailChange']);
        $group->post('/profile/password', [AccountController::class, 'updatePassword']);
        $group->post('/profile/avatar', [AccountController::class, 'uploadAvatar']);
        $group->post('/profile/avatar/delete', [AccountController::class, 'removeAvatar']);

        // Not under /profile: the id is whose face it is, not whose page it is,
        // and every member of the household fetches every other member's. The
        // service answers 404 for anybody outside it.
        $group->get('/avatars/{id:[0-9]+}', [AccountController::class, 'avatar'])->setName('avatar');

        // The household's read-only overview became Members & roles in Phase
        // 26. The path stays as a redirect for anybody who bookmarked it; the
        // screen it lands on asks for its own permission.
        $group->get('/household', [MemberController::class, 'householdMoved']);

        // ------------------------------------------------------------------
        // Phase 28: Settings, as three tabs
        //
        // General and Data & integrations open for every member, and gate each
        // section where it is drawn and again at the route its form posts to.
        // The Instance tab is the instance's, so its page asks for instance
        // administration and answers 403 to everybody else.
        //
        // The base currency and the rate provider are drawn on General but
        // belong to the instance: their posts take instance administration,
        // whoever else can see them.
        // ------------------------------------------------------------------
        $group->get('/settings', [SettingsController::class, 'general'])->setName('settings');

        $group->get('/settings/data', [SettingsController::class, 'data'])->setName('settings-data');

        $group->get('/settings/instance', [SettingsController::class, 'instance'])
            ->setName('settings-instance')
            ->add($requires(Permission::ManageInstance));

        $group->post('/settings/household', [SettingsController::class, 'updateHousehold'])
            ->add($requires(Permission::ManageHousehold));

        $group->post('/settings/currency', [SettingsController::class, 'updateCurrency'])
            ->add($requires(Permission::ManageInstance));

        $group->post('/settings/rates/refresh', [SettingsController::class, 'refreshRates'])
            ->add($requires(Permission::ManageInstance));

        $group->post('/settings/instance', [SettingsController::class, 'updateInstance'])
            ->add($requires(Permission::ManageInstance));

        // ------------------------------------------------------------------
        // Phase 15: household members; Phase 26: Members & roles
        //
        // The list is every member's to read: who is in the household with
        // them and what each role may do. What it shows of anybody else's
        // spending is decided row by row in HouseholdOverviewService, so the
        // route asks only that the reader belongs here.
        //
        // Managing other people is household management, so every other route
        // names the permission an Owner/Admin has and nobody else does. The
        // service asks the scope the same question again before it acts.
        // ------------------------------------------------------------------
        $group->get('/settings/members', [MemberController::class, 'index'])
            ->setName('members')
            ->add($requires(Permission::ViewSubscriptions));

        $group->get('/settings/members/invite', [MemberController::class, 'inviteForm'])
            ->setName('member-invite')
            ->add($requires(Permission::ManageHousehold));

        $group->post('/settings/members', [MemberController::class, 'add'])
            ->add($requires(Permission::ManageHousehold));

        $group->post('/settings/members/{id:[0-9]+}/role', [MemberController::class, 'changeRole'])
            ->add($requires(Permission::ManageHousehold));

        $group->post('/settings/members/{id:[0-9]+}/invite', [MemberController::class, 'resendInvite'])
            ->add($requires(Permission::ManageHousehold));

        $group->post('/settings/members/{id:[0-9]+}/reset-password', [MemberController::class, 'sendPasswordReset'])
            ->add($requires(Permission::ManageHousehold));

        $group->post('/settings/members/{id:[0-9]+}/revoke', [MemberController::class, 'revoke'])
            ->add($requires(Permission::ManageHousehold));

        $group->post('/settings/members/{id:[0-9]+}/restore', [MemberController::class, 'restore'])
            ->add($requires(Permission::ManageHousehold));

        // A GET that asks the question, and a POST that answers it. Removing a
        // member in ISOLATED mode disposes of rows nobody else can see, so it
        // is not something a single click should do.
        $group->get('/settings/members/{id:[0-9]+}/remove', [MemberController::class, 'confirmRemoval'])
            ->setName('member-remove')
            ->add($requires(Permission::ManageHousehold));

        $group->post('/settings/members/{id:[0-9]+}/remove', [MemberController::class, 'remove'])
            ->add($requires(Permission::ManageHousehold));

        // ------------------------------------------------------------------
        // Phase 3: notifications
        //
        // Your own channels and preferences need no permission beyond being
        // signed in — every one of these routes acts on the authenticated
        // user's own id and cannot be pointed at anybody else's. A Viewer may
        // configure their own reminders: not being allowed to change household
        // data is not a reason to be unable to hear about it.
        //
        // The trusted-host list is the opposite. It decides what this server
        // may be made to connect to, which is a property of the network the
        // instance sits on, so it takes instance administration.
        // ------------------------------------------------------------------
        $group->get('/settings/notifications', [NotificationController::class, 'index'])
            ->setName('notifications');

        $group->post('/settings/notifications/preferences', [NotificationController::class, 'updatePreferences']);

        $group->post('/settings/notifications/channels', [NotificationController::class, 'createChannel']);

        $group->post(
            '/settings/notifications/channels/{id:[0-9]+}',
            [NotificationController::class, 'updateChannel'],
        );

        // The switch beside a channel: on or off, and nothing else about it.
        $group->post(
            '/settings/notifications/channels/{id:[0-9]+}/active',
            [NotificationController::class, 'setActive'],
        );

        $group->post(
            '/settings/notifications/channels/{id:[0-9]+}/delete',
            [NotificationController::class, 'deleteChannel'],
        );

        $group->post(
            '/settings/notifications/channels/{id:[0-9]+}/test',
            [NotificationController::class, 'test'],
        );

        // ------------------------------------------------------------------
        // Phase 4: account security, on /profile since Phase 27
        //
        // Every route here acts on the signed-in user's own account, so none of
        // them names a permission: the id comes from the session and cannot be
        // pointed at anybody else. A Viewer may harden their own sign-in for
        // the same reason they may configure their own reminders.
        //
        // None of these is in PasswordChangeRequiredMiddleware's allowlist,
        // which names its /profile paths one at a time: a member still on a
        // temporary password cannot enrol a factor before choosing their own.
        //
        // The screen these lived on was `/settings/security`; it redirects to
        // the section of the profile that replaced it, for bookmarks. The
        // posts moved outright — nothing outside this application's own forms
        // submits to them, and a redirected POST is not a POST.
        // ------------------------------------------------------------------
        $group->get('/settings/security', [SecurityController::class, 'moved'])->setName('security');

        $group->post('/profile/two-step/totp', [SecurityController::class, 'startTotp']);
        $group->post('/profile/two-step/totp/confirm', [SecurityController::class, 'confirmTotp']);
        $group->post('/profile/two-step/totp/cancel', [SecurityController::class, 'cancelTotp']);
        $group->post('/profile/two-step/totp/disable', [SecurityController::class, 'disableTotp']);
        // Not under /totp: recovery codes cover whichever second factor the
        // account has, including a passkey with no authenticator app.
        $group->post('/profile/two-step/recovery-codes', [SecurityController::class, 'regenerateRecoveryCodes']);

        $group->post('/profile/two-step/passkeys/options', [SecurityController::class, 'passkeyOptions']);
        $group->post('/profile/two-step/passkeys', [SecurityController::class, 'registerPasskey']);
        $group->post('/profile/two-step/passkeys/{id:[0-9]+}/rename', [SecurityController::class, 'renamePasskey']);
        $group->post('/profile/two-step/passkeys/{id:[0-9]+}/delete', [SecurityController::class, 'revokePasskey']);

        $group->post('/profile/sessions/revoke', [SecurityController::class, 'revokeSession']);
        $group->post('/profile/sessions/revoke-others', [SecurityController::class, 'revokeOtherSessions']);

        // The log is a read, but not one every member may make: an instance
        // administrator sees the instance, a household Owner sees their
        // household, and everybody else gets a 403 here rather than an empty
        // page that implies there was nothing to see.
        $group->get('/audit', [AuditLogController::class, 'index'])
            ->setName('audit-log')
            ->add($requires(Permission::ViewAuditLog));

        $group->post('/settings/trusted-hosts', [SettingsController::class, 'addTrustedHost'])
            ->add($requires(Permission::ManageInstance));

        $group->post('/settings/trusted-hosts/{id:[0-9]+}/delete', [SettingsController::class, 'deleteTrustedHost'])
            ->add($requires(Permission::ManageInstance));

        // ------------------------------------------------------------------
        // Phase 5: interoperability
        //
        // Tokens are per-user, like notification channels and passkeys, so they
        // name no permission — a token can never exceed the role of the account
        // that issued it. Importing is an ordinary bulk write. Backups are not:
        // an export is the whole household in one file and a restore adds rows
        // wholesale, so both take the household-management role.
        // ------------------------------------------------------------------
        // The tokens and backup screens are sections of Settings' Data &
        // integrations tab since Phase 28; their paths redirect there.
        $group->get('/settings/api-tokens', [ApiTokenController::class, 'moved'])->setName('api-tokens');

        $group->post('/settings/api-tokens', [ApiTokenController::class, 'create']);

        $group->post('/settings/api-tokens/{id:[0-9]+}/reissue', [ApiTokenController::class, 'reissue']);

        $group->post('/settings/api-tokens/{id:[0-9]+}/revoke', [ApiTokenController::class, 'revoke']);

        $group->get('/import', [ImportController::class, 'start'])
            ->setName('import')
            ->add($requires(Permission::ImportData));

        $group->post('/import', [ImportController::class, 'upload'])
            ->add($requires(Permission::ImportData));

        $group->get('/import/map', [ImportController::class, 'map'])
            ->setName('import-map')
            ->add($requires(Permission::ImportData));

        $group->post('/import/preview', [ImportController::class, 'preview'])
            ->add($requires(Permission::ImportData));

        $group->post('/import/commit', [ImportController::class, 'commit'])
            ->add($requires(Permission::ImportData));

        $group->post('/import/cancel', [ImportController::class, 'cancel'])
            ->add($requires(Permission::ImportData));

        $group->get('/settings/backup', [BackupController::class, 'moved'])->setName('backup');

        $group->post('/settings/backup/export', [BackupController::class, 'export'])
            ->add($requires(Permission::ManageBackups));

        $group->post('/settings/backup/restore', [BackupController::class, 'restore'])
            ->add($requires(Permission::ManageBackups));

        $group->post('/subscriptions/{id:[0-9]+}/attachments', [AttachmentController::class, 'upload'])
            ->add($requires(Permission::ManageAttachments));

        // A download is a read. What decides whether this caller may make it is
        // the scoped lookup inside the controller, not this permission: another
        // household's attachment id returns 404 from the repository.
        $group->get(
            '/subscriptions/{id:[0-9]+}/attachments/{attachmentId:[0-9]+}',
            [AttachmentController::class, 'download'],
        )->add($requires(Permission::ViewSubscriptions));

        $group->post(
            '/subscriptions/{id:[0-9]+}/attachments/{attachmentId:[0-9]+}/delete',
            [AttachmentController::class, 'delete'],
        )->add($requires(Permission::ManageAttachments));

        // The wizard's second and third steps, and the page it ends on. Inside
        // the authenticated group because they run after the administrator
        // account exists — see SetupGuardMiddleware for why these /setup paths
        // stay open, and SetupWizardService::isOpenTo() for who they are open to.
        // The household step renames the household and sets instance-wide
        // settings, so it takes both permissions, whoever the wizard is open to.
        $group->get('/setup/household', [SetupController::class, 'showHousehold'])
            ->setName('setup-household')
            ->add($requires(Permission::ManageHousehold))
            ->add($requires(Permission::ManageInstance));
        $group->post('/setup/household', [SetupController::class, 'saveHousehold'])
            ->add($requires(Permission::ManageHousehold))
            ->add($requires(Permission::ManageInstance));

        $group->get('/setup/notifications', [SetupController::class, 'showNotifications'])
            ->setName('setup-notifications');

        $group->post('/setup/notifications/channels', [SetupController::class, 'addNotificationChannel']);

        $group->post('/setup/notifications/test-email', [SetupController::class, 'sendTestEmail']);

        $group->post('/setup/notifications/finish', [SetupController::class, 'finishNotifications']);

        $group->get('/setup/done', [SetupController::class, 'done'])->setName('setup-done');
        // Added to the group and therefore runs on every route in it, which is
        // the point: a member still on the temporary password an administrator
        // gave them is sent to the account page until they replace it, and a
        // route added later cannot forget to check.
    })->add(PasswordChangeRequiredMiddleware::class)->add(AuthenticationMiddleware::class);

    // ----------------------------------------------------------------------
    // Phase 5: the versioned API
    //
    // Mounted outside the authenticated group, because it authenticates
    // differently: a bearer token and never a session cookie. That is what lets
    // CsrfMiddleware skip these paths — with no ambient credential there is
    // nothing for a forged request to ride on. See TokenAuthenticationMiddleware.
    //
    // Every route below still names the permission it needs, and the scope it
    // runs under is built by the same ScopeFactory the browser uses, so roles
    // and isolation are enforced here by exactly the same code.
    // ----------------------------------------------------------------------

    // The description of the API is public: a client needs it in order to learn
    // how to authenticate, and it contains no instance data.
    $app->get(ApiPath::PREFIX . '/openapi.yaml', [OpenApiController::class, 'asYaml'])->setName('openapi-yaml');
    $app->get(ApiPath::PREFIX . '/openapi.json', [OpenApiController::class, 'asJson'])->setName('openapi-json');

    // The calendar feed, and only the calendar feed, accepts its token in the
    // query string — a calendar client cannot send a header. It is a separate
    // middleware rather than a condition inside the shared one, so a route
    // cannot end up accepting URL credentials by being added in the wrong place.
    $app->get(ApiPath::PREFIX . '/calendar.ics', [CalendarApiController::class, 'feed'])
        ->setName('calendar-feed')
        ->add($requires(Permission::ViewSubscriptions))
        ->add(FeedAuthenticationMiddleware::class);

    $app->group(ApiPath::PREFIX, function (RouteCollectorProxy $group) use ($requires): void {
        $group->get('/me', [MeApiController::class, 'show']);

        $group->get('/subscriptions', [SubscriptionApiController::class, 'index'])
            ->add($requires(Permission::ViewSubscriptions));

        $group->post('/subscriptions', [SubscriptionApiController::class, 'create'])
            ->add($requires(Permission::CreateSubscription));

        $group->get('/subscriptions/{id:[0-9]+}', [SubscriptionApiController::class, 'show'])
            ->add($requires(Permission::ViewSubscriptions));

        // Full replace. There is no PATCH: the service's input is
        // absence-sensitive, so a partial body is the one shape that could
        // half-write a row. See SubscriptionPayload.
        $group->put('/subscriptions/{id:[0-9]+}', [SubscriptionApiController::class, 'update'])
            ->add($requires(Permission::UpdateSubscription));

        $group->delete('/subscriptions/{id:[0-9]+}', [SubscriptionApiController::class, 'delete'])
            ->add($requires(Permission::DeleteSubscription));

        $group->post('/subscriptions/{id:[0-9]+}/cancel', [SubscriptionApiController::class, 'cancel'])
            ->add($requires(Permission::UpdateSubscription));

        $group->post('/subscriptions/{id:[0-9]+}/uncancel', [SubscriptionApiController::class, 'uncancel'])
            ->add($requires(Permission::UpdateSubscription));

        $group->post('/subscriptions/{id:[0-9]+}/logo', [SubscriptionApiController::class, 'uploadLogo'])
            ->add($requires(Permission::UpdateSubscription));

        $group->delete('/subscriptions/{id:[0-9]+}/logo', [SubscriptionApiController::class, 'deleteLogo'])
            ->add($requires(Permission::UpdateSubscription));

        $group->get('/subscriptions/{id:[0-9]+}/attachments', [AttachmentApiController::class, 'index'])
            ->add($requires(Permission::ViewSubscriptions));

        $group->post('/subscriptions/{id:[0-9]+}/attachments', [AttachmentApiController::class, 'upload'])
            ->add($requires(Permission::ManageAttachments));

        $group->get(
            '/subscriptions/{id:[0-9]+}/attachments/{attachmentId:[0-9]+}',
            [AttachmentApiController::class, 'download'],
        )->add($requires(Permission::ViewSubscriptions));

        $group->delete(
            '/subscriptions/{id:[0-9]+}/attachments/{attachmentId:[0-9]+}',
            [AttachmentApiController::class, 'delete'],
        )->add($requires(Permission::ManageAttachments));

        // Phase 34: the payment ledger.
        $group->get('/subscriptions/{id:[0-9]+}/charges', [ChargeApiController::class, 'index'])
            ->add($requires(Permission::ViewSubscriptions));

        $group->post('/subscriptions/{id:[0-9]+}/charges', [ChargeApiController::class, 'create'])
            ->add($requires(Permission::ManageCharges));

        $group->patch('/charges/{id:[0-9]+}', [ChargeApiController::class, 'update'])
            ->add($requires(Permission::ManageCharges));

        $group->get('/categories', [TaxonomyApiController::class, 'categories'])
            ->add($requires(Permission::ViewSubscriptions));

        $group->post('/categories', [TaxonomyApiController::class, 'createCategory'])
            ->add($requires(Permission::ManageCategories));

        $group->put('/categories/{id:[0-9]+}', [TaxonomyApiController::class, 'updateCategory'])
            ->add($requires(Permission::ManageCategories));

        $group->delete('/categories/{id:[0-9]+}', [TaxonomyApiController::class, 'deleteCategory'])
            ->add($requires(Permission::ManageCategories));

        $group->get('/payment-methods', [TaxonomyApiController::class, 'paymentMethods'])
            ->add($requires(Permission::ViewSubscriptions));

        $group->post('/payment-methods', [TaxonomyApiController::class, 'createPaymentMethod'])
            ->add($requires(Permission::ManageCategories));

        $group->put('/payment-methods/{id:[0-9]+}', [TaxonomyApiController::class, 'updatePaymentMethod'])
            ->add($requires(Permission::ManageCategories));

        $group->delete('/payment-methods/{id:[0-9]+}', [TaxonomyApiController::class, 'deletePaymentMethod'])
            ->add($requires(Permission::ManageCategories));

        // Phase 30: a file does not travel in the JSON body, so the logo has a
        // path of its own, as a subscription's does.
        $group->post('/payment-methods/{id:[0-9]+}/logo', [TaxonomyApiController::class, 'uploadPaymentMethodLogo'])
            ->add($requires(Permission::ManageCategories));

        $group->delete('/payment-methods/{id:[0-9]+}/logo', [TaxonomyApiController::class, 'deletePaymentMethodLogo'])
            ->add($requires(Permission::ManageCategories));

        $group->get('/tags', [TaxonomyApiController::class, 'tags'])
            ->add($requires(Permission::ViewSubscriptions));

        $group->post('/tags', [TaxonomyApiController::class, 'createTag'])
            ->add($requires(Permission::ManageTags));

        $group->put('/tags/{id:[0-9]+}', [TaxonomyApiController::class, 'updateTag'])
            ->add($requires(Permission::ManageTags));

        $group->delete('/tags/{id:[0-9]+}', [TaxonomyApiController::class, 'deleteTag'])
            ->add($requires(Permission::ManageTags));
    })->add(ApiAuthenticationMiddleware::class);
};
