<?php
declare(strict_types=1);

use Erp\Core\Config;
use Erp\Core\Database;
use Erp\Core\Http\Request;
use Erp\Core\Http\Response;
use Erp\Core\Http\Router;
use Erp\Core\Security\AuthService;
use Erp\Core\Security\Session;
use Erp\Core\Security\WebAuthnService;
use Erp\Modules\Contacts\ContactController;
use Erp\Modules\Contacts\ContactChatterController;
use Erp\Modules\Contacts\ContactOptionsController;
use Erp\Modules\Contacts\CommissionPlanController;
use Erp\Modules\Export\MasterDataExportController;
use Erp\Modules\CRM\CrmController;
use Erp\Modules\Settings\CompanyController;
use Erp\Modules\Settings\ApprovalController;
use Erp\Modules\Settings\UserAccessController;
use Erp\Modules\Inventory\InventoryController;
use Erp\Modules\Inventory\InventoryCycleCountController;
use Erp\Modules\Inventory\StockMoveController;
use Erp\Modules\Sales\SalesController;
use Erp\Modules\Sales\SalesTemplateController;
use Erp\Modules\Contracts\ContractController;
use Erp\Modules\Invoicing\InvoicingController;
use Erp\Modules\Accounting\AccountingController;
use Erp\Modules\Accounting\ManualJournalEntryController;
use Erp\Modules\Accounting\BankStatementController;
use Erp\Modules\Todo\TodoController;
use Erp\Modules\Todo\TodoNotificationController;
use Erp\Modules\Discuss\DiscussController;
use Erp\Modules\Appointments\AppointmentController;
use Erp\Modules\Manufacturing\ManufacturingController;
use Erp\Modules\Dashboards\DashboardController;
use Erp\Modules\Calendar\CalendarController;

require dirname(__DIR__) . '/app/bootstrap.php';

$config = Config::fromEnvironment(dirname(__DIR__));
date_default_timezone_set($config->timezone());
try {
    Session::start($config);
} catch (Throwable $error) {
    error_log('ERP session startup failed: ' . $error->getMessage());
    Response::json(['ok' => false, 'error' => 'The application session could not be started.'], 503)->send();
    exit;
}

$request = Request::fromGlobals();
$requestPath = $request->path();
$authorizationHeader = $request->header('authorization');
if ($authorizationHeader !== null) {
    if (preg_match('/^Bearer\s+(.+)$/i', trim($authorizationHeader), $bearer) !== 1) {
        Response::json(['ok'=>false,'error'=>'A valid ERP API bearer key is required.'],401)->send();
        exit;
    }
    $apiUserId = (new AuthService(Database::connection($config)))->authenticateApiKey(trim($bearer[1]));
    if ($apiUserId === null) {
        Response::json(['ok'=>false,'error'=>'The API key is invalid, expired, or revoked.'],401)->send();
        exit;
    }
    Session::authorizeApiUser($apiUserId);
}
if ($request->method() === 'GET' && $requestPath === '/') {
    $html = file_get_contents(__DIR__ . '/app.html');
    $basePath = $config->basePath() === '/' ? '' : $config->basePath();
    $assetVersion = (string)(filemtime(__DIR__ . '/app.css') ?: 0) . '-' . (string)(filemtime(__DIR__ . '/app.js') ?: 0);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo strtr($html === false ? '' : $html, [
        '@@ERP_BASE_PATH@@' => htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        '@@ERP_ASSET_VERSION@@' => $assetVersion,
    ]);
    exit;
}
if ($request->method() === 'GET' && preg_match('#^/book/(?:[a-f0-9]{64}|cancel/[a-f0-9]{64}|manage/[a-f0-9]{64})$#', $requestPath) === 1) {
    $html = file_get_contents(__DIR__ . '/booking.html');
    $basePath = $config->basePath() === '/' ? '' : $config->basePath();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cache-Control: no-store');
    echo str_replace('@@ERP_BASE_PATH@@', htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html === false ? '' : $html);
    exit;
}
$router = new Router();

$router->get('/health', static function (Request $request, array $params) use ($config): Response {
    $database = 'not_configured';
    try {
        Database::connection($config)->query('SELECT 1');
        $database = 'ok';
    } catch (Throwable) {
        $database = $config->hasDatabaseCredentials() ? 'unavailable' : 'not_configured';
    }

    $status = $database === 'ok' ? 200 : 503;
    return Response::json([
        'ok' => $database === 'ok',
        'application' => 'ERP PHP',
        'database' => $database,
        'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
    ], $status);
});

$router->get('/api/v1/status', static fn (Request $request, array $params): Response => Response::json([
    'ok' => true,
    'message' => 'ERP foundation is running. Business modules are being migrated in tracked phases.',
]));

$dashboard = new DashboardController($config);
$router->get('/api/v1/dashboard', [$dashboard, 'show']);

$router->get('/api/v1/auth/session', static function (Request $request, array $params) use ($config): Response {
    $user = null;
    $permissions = [];
    $calendarPreferences = ['calendar_default_view' => 'month', 'calendar_show_weekends' => true, 'default_warehouse_id' => null];
    if (isset($_SESSION['user_id'])) {
        $user = (new AuthService(Database::connection($config)))->currentUser();
        if ($user) {
            $permissions = (new Erp\Core\Security\Authorization(Database::connection($config), $user))->permissionKeys();
            $preferenceQuery = Database::connection($config)->prepare('SELECT calendar_default_view,calendar_show_weekends,default_warehouse_id FROM users WHERE id=:id LIMIT 1');
            $preferenceQuery->execute(['id' => $user->id]);
            $savedCalendarPreferences = $preferenceQuery->fetch();
            if ($savedCalendarPreferences) {
                $calendarPreferences = [
                    'calendar_default_view' => (string)$savedCalendarPreferences['calendar_default_view'],
                    'calendar_show_weekends' => (bool)$savedCalendarPreferences['calendar_show_weekends'],
                    'default_warehouse_id' => $savedCalendarPreferences['default_warehouse_id'] === null ? null : (int)$savedCalendarPreferences['default_warehouse_id'],
                ];
            }
        }
    }
    return Response::json([
        'ok' => true,
        'authenticated' => $user !== null,
        'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'login' => $user->login] + $calendarPreferences : null,
        'companies' => $user ? $user->companyIds : [],
        'default_company_id' => $user?->defaultCompanyId,
        'superuser' => $user?->superuser ?? false,
        'permissions' => $permissions,
        'csrf_token' => Session::csrfToken(),
    ]);
});

$router->post('/api/v1/auth/login', static function (Request $request, array $params) use ($config): Response {
    if (!Session::verifyCsrf($request->header('x-csrf-token'))) {
        return Response::json(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419);
    }
    $input = $request->input();
    $auth = new AuthService(Database::connection($config), $config->dataEncryptionKey());
    $result = $auth->startLogin((string)($input['login'] ?? ''), (string)($input['password'] ?? ''), $request->remoteAddress());
    if (!$result['authenticated'] && !$result['requires_two_factor']) {
        return Response::json(['ok' => false, 'error' => 'The login details are incorrect or the account is not active.'], 401);
    }
    return Response::json(['ok' => true, 'message' => $result['requires_two_factor'] ? 'Enter your authenticator or recovery code.' : 'Signed in.', 'requires_two_factor' => $result['requires_two_factor'], 'csrf_token' => Session::csrfToken()]);
});

$router->post('/api/v1/auth/two-factor', static function (Request $request, array $params) use ($config): Response {
    if (!Session::verifyCsrf($request->header('x-csrf-token'))) return Response::json(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419);
    $input = $request->input();
    $unknown = array_diff(array_keys($input), ['code']);
    if ($unknown) return Response::json(['ok' => false, 'error' => 'Unsupported two-factor fields.'], 422);
    $auth = new AuthService(Database::connection($config), $config->dataEncryptionKey());
    if (!$auth->completeTwoFactorLogin((string)($input['code'] ?? ''), $request->remoteAddress())) return Response::json(['ok' => false, 'error' => 'The authenticator or recovery code is invalid, expired, or no longer available.'], 401);
    return Response::json(['ok' => true, 'message' => 'Signed in.', 'csrf_token' => Session::csrfToken()]);
});

$router->post('/api/v1/auth/two-factor/cancel', static function (Request $request, array $params) use ($config): Response {
    if (!Session::verifyCsrf($request->header('x-csrf-token'))) return Response::json(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419);
    (new AuthService(Database::connection($config), $config->dataEncryptionKey()))->cancelTwoFactorLogin();
    return Response::json(['ok' => true, 'csrf_token' => Session::csrfToken()]);
});

$router->post('/api/v1/auth/passkey/options', static function (Request $request, array $params) use ($config): Response {
    if (!Session::verifyCsrf($request->header('x-csrf-token'))) return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
    try {
        $pdo = Database::connection($config);
        $options = (new WebAuthnService($pdo, $config))->beginLogin(new AuthService($pdo, $config->dataEncryptionKey()), $request->remoteAddress());
        return Response::json(['ok'=>true,'options'=>$options]);
    } catch (\InvalidArgumentException $error) {
        return Response::json(['ok'=>false,'error'=>$error->getMessage()],429);
    } catch (Throwable $error) {
        error_log('Passkey login options failed: '.$error->getMessage());
        return Response::json(['ok'=>false,'error'=>'Passkey sign-in is unavailable. Use your password.'],500);
    }
});

$router->post('/api/v1/auth/passkey/verify', static function (Request $request, array $params) use ($config): Response {
    if (!Session::verifyCsrf($request->header('x-csrf-token'))) return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
    try {
        $pdo = Database::connection($config);
        $auth = new AuthService($pdo, $config->dataEncryptionKey());
        $result = (new WebAuthnService($pdo, $config))->finishLogin($request->input(), $auth, $request->remoteAddress());
        return Response::json(['ok'=>true,'authenticated'=>$result['authenticated'],'requires_two_factor'=>$result['requires_two_factor'],'csrf_token'=>Session::csrfToken()]);
    } catch (\InvalidArgumentException $error) {
        return Response::json(['ok'=>false,'error'=>$error->getMessage()],401);
    } catch (Throwable $error) {
        error_log('Passkey login verification failed: '.$error->getMessage());
        return Response::json(['ok'=>false,'error'=>'Passkey sign-in is unavailable. Use your password.'],500);
    }
});

$router->post('/api/v1/auth/logout', static function (Request $request, array $params) use ($config): Response {
    if (!Session::verifyCsrf($request->header('x-csrf-token'))) {
        return Response::json(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419);
    }
    (new AuthService(Database::connection($config)))->logout();
    Session::start($config);
    return Response::json(['ok' => true, 'csrf_token' => Session::csrfToken()]);
});

$contacts = new ContactController($config);
$router->get('/api/v1/contacts', [$contacts, 'index']);
$router->get('/api/v1/contacts/{id}', [$contacts, 'show']);
$router->post('/api/v1/contacts', [$contacts, 'create']);
$router->patch('/api/v1/contacts/{id}', [$contacts, 'update']);
$router->patch('/api/v1/contacts/{id}/status', [$contacts, 'status']);
$contactChatter = new ContactChatterController($config);
$router->get('/api/v1/contacts/{id}/chatter', [$contactChatter, 'timeline']);
$router->post('/api/v1/contacts/{id}/messages', [$contactChatter, 'addMessage']);
$router->post('/api/v1/contacts/{id}/activities', [$contactChatter, 'addActivity']);
$router->post('/api/v1/contacts/{id}/activities/{activityId}/done', [$contactChatter, 'completeActivity']);
$todo = new TodoController($config);
$todoNotifications = new TodoNotificationController($config);
$router->get('/api/v1/notifications', [$todoNotifications, 'inbox']);
$router->post('/api/v1/notifications/{id}/read', [$todoNotifications, 'markRead']);
$router->post('/api/v1/notifications/read-all', [$todoNotifications, 'markAllRead']);
$router->get('/api/v1/todo/options', [$todo, 'options']);
$router->get('/api/v1/todo/tasks', [$todo, 'index']);
$router->get('/api/v1/todo/tasks/{id}', [$todo, 'show']);
$router->post('/api/v1/todo/tasks', [$todo, 'create']);
$router->patch('/api/v1/todo/tasks/{id}', [$todo, 'update']);
$router->post('/api/v1/todo/tasks/{id}/priority', [$todo, 'priority']);
$router->post('/api/v1/todo/tasks/{id}/complete', [$todo, 'complete']);
$router->delete('/api/v1/todo/tasks/{id}', [$todo, 'archive']);
$router->post('/api/v1/todo/stages', [$todo, 'createStage']);
$router->patch('/api/v1/todo/stages/{id}', [$todo, 'updateStage']);
$router->delete('/api/v1/todo/stages/{id}', [$todo, 'deleteStage']);
$discuss = new DiscussController($config);
$router->get('/api/v1/discuss/options', [$discuss, 'options']);
$router->get('/api/v1/discuss/conversations', [$discuss, 'workspace']);
$router->post('/api/v1/discuss/channels', [$discuss, 'createChannel']);
$router->post('/api/v1/discuss/direct', [$discuss, 'createDirect']);
$router->get('/api/v1/discuss/conversations/{id}', [$discuss, 'conversation']);
$router->post('/api/v1/discuss/conversations/{id}/join', [$discuss, 'join']);
$router->post('/api/v1/discuss/conversations/{id}/messages', [$discuss, 'send']);
$router->post('/api/v1/discuss/conversations/{id}/read', [$discuss, 'markRead']);
$router->post('/api/v1/discuss/conversations/{id}/preference', [$discuss, 'preference']);
$appointments = new AppointmentController($config);
$calendar = new CalendarController($config);
$router->get('/api/v1/calendar/options', [$calendar, 'options']);
$router->get('/api/v1/calendar/events', [$calendar, 'events']);
$router->get('/api/v1/calendar/events/{id}', [$calendar, 'event']);
$router->post('/api/v1/calendar/events', [$calendar, 'create']);
$router->patch('/api/v1/calendar/events/{id}', [$calendar, 'update']);
$router->patch('/api/v1/calendar/events/{id}/occurrence', [$calendar, 'updateOccurrence']);
$router->post('/api/v1/calendar/events/{id}/occurrence/status', [$calendar, 'setOccurrenceStatus']);
$router->delete('/api/v1/calendar/events/{id}/occurrence', [$calendar, 'clearOccurrenceException']);
$router->post('/api/v1/calendar/events/{id}/status', [$calendar, 'setStatus']);
$router->post('/api/v1/calendar/events/{id}/response', [$calendar, 'respond']);
$router->get('/api/v1/appointments/options', [$appointments, 'options']);
$router->get('/api/v1/appointments/types', [$appointments, 'types']);
$router->get('/api/v1/appointments/types/{id}', [$appointments, 'type']);
$router->get('/api/v1/appointments/types/{id}/slots', [$appointments, 'availableSlots']);
$router->post('/api/v1/appointments/types', [$appointments, 'saveType']);
$router->patch('/api/v1/appointments/types/{id}', [$appointments, 'updateType']);
$router->get('/api/v1/appointments/resources', [$appointments, 'resources']);
$router->post('/api/v1/appointments/resources', [$appointments, 'saveResource']);
$router->patch('/api/v1/appointments/resources/{id}', [$appointments, 'updateResource']);
$router->get('/api/v1/appointments/bookings', [$appointments, 'bookings']);
$router->get('/api/v1/appointments/bookings/{id}', [$appointments, 'booking']);
$router->post('/api/v1/appointments/bookings', [$appointments, 'createBooking']);
$router->post('/api/v1/appointments/bookings/{id}/status', [$appointments, 'setBookingStatus']);
$router->get('/api/v1/public/appointments/{token}', [$appointments, 'publicType']);
$router->get('/api/v1/public/appointments/{token}/slots', [$appointments, 'publicSlots']);
$router->post('/api/v1/public/appointments/{token}/book', [$appointments, 'createPublicBooking']);
$router->get('/api/v1/public/appointments/manage/{bookingToken}', [$appointments, 'publicManageBooking']);
$router->get('/api/v1/public/appointments/manage/{bookingToken}/slots', [$appointments, 'publicRescheduleSlots']);
$router->post('/api/v1/public/appointments/manage/{bookingToken}', [$appointments, 'reschedulePublicBooking']);
$router->get('/api/v1/public/appointments/cancel/{bookingToken}', [$appointments, 'publicCancellation']);
$router->post('/api/v1/public/appointments/cancel/{bookingToken}', [$appointments, 'cancelPublicBooking']);
$accounting = new AccountingController($config);
$router->get('/api/v1/accounting/options', [$accounting, 'options']);
$router->get('/api/v1/accounting/reports', [$accounting, 'report']);
$router->get('/api/v1/accounting/moves/{id}', [$accounting, 'showMove']);
$manualJournalEntries = new ManualJournalEntryController($config);
$bankStatements = new BankStatementController($config);
$router->get('/api/v1/accounting/bank-statements/options', [$bankStatements, 'options']);
$router->get('/api/v1/accounting/bank-statements', [$bankStatements, 'index']);
$router->get('/api/v1/accounting/bank-statements/{statementId}/lines/{lineId}/candidates', [$bankStatements, 'candidates']);
$router->post('/api/v1/accounting/bank-statements/{statementId}/lines/{lineId}/categorize', [$bankStatements, 'categorize']);
$router->get('/api/v1/accounting/bank-statements/{id}', [$bankStatements, 'show']);
$router->post('/api/v1/accounting/bank-statements/import', [$bankStatements, 'import']);
$router->get('/api/v1/accounting/manual-entries/options', [$manualJournalEntries, 'options']);
$router->get('/api/v1/accounting/manual-entries', [$manualJournalEntries, 'index']);
$router->post('/api/v1/accounting/manual-entries', [$manualJournalEntries, 'create']);
$router->get('/api/v1/accounting/manual-entries/{id}', [$manualJournalEntries, 'show']);
$router->patch('/api/v1/accounting/manual-entries/{id}', [$manualJournalEntries, 'update']);
$router->post('/api/v1/accounting/manual-entries/{id}/post', [$manualJournalEntries, 'post']);
$router->post('/api/v1/accounting/manual-entries/{id}/reverse', [$manualJournalEntries, 'reverse']);
$router->delete('/api/v1/accounting/manual-entries/{id}', [$manualJournalEntries, 'discard']);
$router->get('/api/v1/contact-options', [new ContactOptionsController($config), 'index']);
$router->get('/api/v1/contact-create-options', [new ContactOptionsController($config), 'create']);
$router->get('/api/v1/master-data/export', [new MasterDataExportController($config), 'download']);

$crm = new CrmController($config);
$router->get('/api/v1/crm/options', [$crm, 'options']);
$router->get('/api/v1/crm/configuration', [$crm, 'configuration']);
$router->post('/api/v1/crm/stages', [$crm, 'saveStage']);
$router->patch('/api/v1/crm/stages/{id}', [$crm, 'saveStage']);
$router->post('/api/v1/crm/teams', [$crm, 'saveTeam']);
$router->patch('/api/v1/crm/teams/{id}', [$crm, 'saveTeam']);
$router->get('/api/v1/crm/leads', [$crm, 'index']);
$router->get('/api/v1/crm/leads/{id}', [$crm, 'show']);
$router->post('/api/v1/crm/leads', [$crm, 'create']);
$router->patch('/api/v1/crm/leads/{id}', [$crm, 'update']);
$router->post('/api/v1/crm/leads/{id}/convert', [$crm, 'convert']);
$router->post('/api/v1/crm/leads/{id}/won', [$crm, 'won']);
$router->post('/api/v1/crm/leads/{id}/lost', [$crm, 'lost']);
$router->post('/api/v1/crm/leads/{leadId}/activities', [$crm, 'addActivity']);
$router->post('/api/v1/crm/activities/{id}/done', [$crm, 'completeActivity']);

$companies = new CompanyController($config);
$router->get('/api/v1/companies/options', [$companies, 'options']);
$router->get('/api/v1/companies', [$companies, 'index']);
$router->get('/api/v1/companies/{companyId}/logo', [$companies, 'logo']);
$router->post('/api/v1/companies/{companyId}/logo', [$companies, 'uploadLogo']);
$router->delete('/api/v1/companies/{companyId}/logo', [$companies, 'removeLogo']);
$router->get('/api/v1/companies/{companyId}', [$companies, 'show']);
$router->post('/api/v1/companies', [$companies, 'create']);
$router->patch('/api/v1/companies/{companyId}', [$companies, 'update']);
$router->get('/api/v1/companies/{companyId}/branches', [$companies, 'branches']);
$router->get('/api/v1/companies/{companyId}/document-prefixes', [$companies, 'documentPrefixes']);
$router->put('/api/v1/companies/{companyId}/document-prefixes', [$companies, 'saveDocumentPrefixes']);
$router->get('/api/v1/companies/{companyId}/branches/{branchId}/logo', [$companies, 'logo']);
$router->post('/api/v1/companies/{companyId}/branches/{branchId}/logo', [$companies, 'uploadLogo']);
$router->delete('/api/v1/companies/{companyId}/branches/{branchId}/logo', [$companies, 'removeLogo']);
$router->post('/api/v1/companies/{companyId}/branches', [$companies, 'createBranch']);
$router->patch('/api/v1/companies/{companyId}/branches/{branchId}', [$companies, 'updateBranch']);
$router->delete('/api/v1/companies/{companyId}/branches/{branchId}', [$companies, 'archiveBranch']);

$users = new UserAccessController($config);
$router->get('/api/v1/users', [$users, 'index']);
$router->get('/api/v1/users/options', [$users, 'options']);
$router->get('/api/v1/users/warehouse-options', [$users, 'warehouseOptions']);
$router->post('/api/v1/users', [$users, 'create']);
$router->get('/api/v1/users/me/sessions', [$users, 'ownSessions']);
$router->get('/api/v1/users/me/passkeys', [$users, 'ownPasskeys']);
$router->post('/api/v1/users/me/passkeys/register', [$users, 'beginPasskeyRegistration']);
$router->post('/api/v1/users/me/passkeys/register/complete', [$users, 'finishPasskeyRegistration']);
$router->post('/api/v1/users/me/passkeys/{id}/remove', [$users, 'removePasskey']);
$router->post('/api/v1/users/me/sessions/{id}/logout', [$users, 'revokeOwnSession']);
$router->post('/api/v1/users/me/password', [$users, 'changeOwnPassword']);
$router->get('/api/v1/users/me/two-factor', [$users, 'twoFactorStatus']);
$router->get('/api/v1/users/me/api-keys', [$users, 'apiKeys']);
$router->post('/api/v1/users/me/api-keys', [$users, 'createApiKey']);
$router->delete('/api/v1/users/me/api-keys/{id}', [$users, 'revokeApiKey']);
$router->post('/api/v1/users/me/two-factor/enroll', [$users, 'beginTwoFactorEnrollment']);
$router->post('/api/v1/users/me/two-factor/confirm', [$users, 'confirmTwoFactorEnrollment']);
$router->post('/api/v1/users/me/two-factor/disable', [$users, 'disableTwoFactor']);
$router->patch('/api/v1/users/me/preferences', [$users, 'updateOwnPreferences']);
$router->get('/api/v1/users/{id}', [$users, 'show']);
$router->patch('/api/v1/users/{id}', [$users, 'update']);

$approvals = new ApprovalController($config);
$router->get('/api/v1/approvals/policies', [$approvals, 'policies']);
$router->put('/api/v1/approvals/policies', [$approvals, 'savePolicy']);
$router->get('/api/v1/approvals', [$approvals, 'inbox']);
$router->post('/api/v1/approvals/{id}/decision', [$approvals, 'decide']);

$inventory = new InventoryController($config);
$router->get('/api/v1/inventory/options', [$inventory, 'options']);
$router->get('/api/v1/inventory/valuation', [$inventory, 'valuation']);
$router->get('/api/v1/inventory/valuation-closing', [$inventory, 'valuationClosingReport']);
$router->post('/api/v1/inventory/valuation-closings', [$inventory, 'generateValuationClosing']);
$router->get('/api/v1/inventory/valuation-closings/{id}', [$inventory, 'showValuationClosing']);
$router->post('/api/v1/inventory/valuation-closings/{id}/post', [$inventory, 'postValuationClosing']);
$router->post('/api/v1/inventory/valuation-closings/{id}/discard', [$inventory, 'discardValuationClosing']);
$cycleCounts = new InventoryCycleCountController($config);
$router->get('/api/v1/inventory/cycle-count-options', [$cycleCounts, 'options']);
$router->patch('/api/v1/inventory/locations/{locationId}/cycle-count-schedule', [$cycleCounts, 'saveLocationSchedule']);
$router->get('/api/v1/inventory/cycle-counts', [$cycleCounts, 'index']);
$router->get('/api/v1/inventory/cycle-counts/{id}', [$cycleCounts, 'show']);
$router->post('/api/v1/inventory/cycle-counts', [$cycleCounts, 'create']);
$router->post('/api/v1/inventory/cycle-counts/{id}/lines', [$cycleCounts, 'addLine']);
$router->patch('/api/v1/inventory/cycle-counts/{id}/lines/{lineId}', [$cycleCounts, 'updateLine']);
$router->post('/api/v1/inventory/cycle-counts/{id}/validate', [$cycleCounts, 'validateCount']);
$router->post('/api/v1/inventory/cycle-counts/{id}/cancel', [$cycleCounts, 'cancel']);
$manufacturing = new ManufacturingController($config);
$router->get('/api/v1/manufacturing/options', [$manufacturing, 'options']);
$router->get('/api/v1/manufacturing/work-centers', [$manufacturing, 'workCenters']);
$router->post('/api/v1/manufacturing/work-centers', [$manufacturing, 'saveWorkCenter']);
$router->patch('/api/v1/manufacturing/work-centers/{id}', [$manufacturing, 'saveWorkCenter']);
$router->get('/api/v1/manufacturing/boms', [$manufacturing, 'boms']);
$router->get('/api/v1/manufacturing/boms/{id}', [$manufacturing, 'bom']);
$router->post('/api/v1/manufacturing/boms', [$manufacturing, 'saveBom']);
$router->patch('/api/v1/manufacturing/boms/{id}', [$manufacturing, 'saveBom']);
$router->get('/api/v1/manufacturing/orders', [$manufacturing, 'orders']);
$router->get('/api/v1/manufacturing/orders/{id}', [$manufacturing, 'order']);
$router->post('/api/v1/manufacturing/orders', [$manufacturing, 'createOrder']);
$router->post('/api/v1/manufacturing/orders/{id}/confirm', [$manufacturing, 'confirm']);
$router->post('/api/v1/manufacturing/orders/{id}/produce', [$manufacturing, 'produce']);
$router->post('/api/v1/manufacturing/orders/{id}/cancel', [$manufacturing, 'cancel']);
$router->post('/api/v1/manufacturing/work-orders/{id}/start', [$manufacturing, 'startWorkOrder']);
$router->post('/api/v1/manufacturing/work-orders/{id}/done', [$manufacturing, 'completeWorkOrder']);
$router->get('/api/v1/inventory/accounting-settings', [$inventory, 'accountingSettings']);
$router->put('/api/v1/inventory/accounting-settings', [$inventory, 'saveAccountingSettings']);
$router->get('/api/v1/products', [$inventory, 'index']);
$router->get('/api/v1/products/catalog', [$inventory, 'catalog']);
$router->get('/api/v1/products/options', [$inventory, 'productOptions']);
$router->get('/api/v1/products/import-template', [$inventory, 'productImportTemplate']);
$router->post('/api/v1/products/import', [$inventory, 'importProducts']);
$router->get('/api/v1/products/{id}', [$inventory, 'show']);
$router->post('/api/v1/products', [$inventory, 'create']);
$router->patch('/api/v1/products/{id}', [$inventory, 'update']);
$router->post('/api/v1/inventory/product-categories', [$inventory, 'createProductCategory']);
$router->patch('/api/v1/inventory/product-categories/{id}', [$inventory, 'updateProductCategory']);
$router->patch('/api/v1/inventory/product-categories/{id}/status', [$inventory, 'productCategoryStatus']);
$router->post('/api/v1/inventory/uom-categories', [$inventory, 'createUomCategory']);
$router->patch('/api/v1/inventory/uom-categories/{id}', [$inventory, 'updateUomCategory']);
$router->patch('/api/v1/inventory/uom-categories/{id}/status', [$inventory, 'uomCategoryStatus']);
$router->post('/api/v1/inventory/uoms', [$inventory, 'createUom']);
$router->patch('/api/v1/inventory/uoms/{id}', [$inventory, 'updateUom']);
$router->patch('/api/v1/inventory/uoms/{id}/status', [$inventory, 'uomStatus']);
$router->post('/api/v1/warehouses', [$inventory, 'createWarehouse']);
$router->patch('/api/v1/warehouses/{id}', [$inventory, 'updateWarehouse']);
$router->patch('/api/v1/warehouses/{id}/status', [$inventory, 'warehouseStatus']);
$router->post('/api/v1/stock-locations', [$inventory, 'createLocation']);
$router->patch('/api/v1/stock-locations/{id}', [$inventory, 'updateLocation']);
$router->patch('/api/v1/stock-locations/{id}/status', [$inventory, 'locationStatus']);

$stockMoves = new StockMoveController($config);
$router->get('/api/v1/stock-moves', [$stockMoves, 'index']);
$router->get('/api/v1/stock-moves/{id}', [$stockMoves, 'show']);
$router->post('/api/v1/stock-moves', [$stockMoves, 'create']);
$router->patch('/api/v1/stock-moves/{id}', [$stockMoves, 'update']);
$router->post('/api/v1/stock-moves/{id}/confirm', [$stockMoves, 'confirm']);
$router->post('/api/v1/stock-moves/{id}/validate', [$stockMoves, 'validateMove']);
$router->post('/api/v1/stock-moves/{id}/cancel', [$stockMoves, 'cancel']);
$router->post('/api/v1/stock-moves/{id}/returns', [$stockMoves, 'createReturn']);
$router->post('/api/v1/stock-moves/{id}/supplier-returns', [$stockMoves, 'createSupplierReturn']);
$router->get('/api/v1/stock-levels', [$stockMoves, 'stockLevels']);
$router->get('/api/v1/products/{id}/stock-quants', [$stockMoves, 'quants']);

$sales = new SalesController($config);
$contracts = new ContractController($config);
$router->get('/api/v1/contracts/options', [$contracts, 'options']);
$router->get('/api/v1/contracts', [$contracts, 'index']);
$router->get('/api/v1/contracts/{id}', [$contracts, 'show']);
$router->post('/api/v1/contracts', [$contracts, 'create']);
$router->patch('/api/v1/contracts/{id}', [$contracts, 'update']);
$salesTemplates = new SalesTemplateController($config);
$router->get('/api/v1/sales/templates', [$salesTemplates, 'index']);
$router->get('/api/v1/sales/templates/{id}', [$salesTemplates, 'show']);
$router->post('/api/v1/sales/templates', [$salesTemplates, 'create']);
$router->patch('/api/v1/sales/templates/{id}', [$salesTemplates, 'update']);
$router->post('/api/v1/sales/templates/{id}/archive', [$salesTemplates, 'archive']);
$router->get('/api/v1/sales/options', [$sales, 'options']);
$router->get('/api/v1/sales', [$sales, 'index']);
$router->get('/api/v1/sales/{id}', [$sales, 'show']);
$router->post('/api/v1/sales', [$sales, 'create']);
$router->post('/api/v1/sales/taxes', [$sales, 'createTax']);
$router->patch('/api/v1/sales/{id}', [$sales, 'update']);
$router->post('/api/v1/sales/{id}/send', [$sales, 'markSent']);
$router->post('/api/v1/sales/{id}/confirm', [$sales, 'confirm']);
$router->post('/api/v1/sales/{id}/cancel', [$sales, 'cancel']);


$commissionPlans = new CommissionPlanController($config);
$router->get('/api/v1/partner-commissions/options', [$commissionPlans, 'options']);
$router->get('/api/v1/partner-commissions', [$commissionPlans, 'index']);
$router->post('/api/v1/partner-commissions', [$commissionPlans, 'create']);
$router->patch('/api/v1/partner-commissions/{id}', [$commissionPlans, 'update']);
$router->post('/api/v1/partner-commissions/{id}/archive', [$commissionPlans, 'archive']);

$purchase = new Erp\Modules\Purchase\PurchaseController($config);
$router->get('/api/v1/purchase/options', [$purchase, 'options']);
$router->get('/api/v1/purchase', [$purchase, 'index']);
$router->get('/api/v1/purchase/bills', [$purchase, 'bills']);
$router->get('/api/v1/purchase/bills/{billId}', [$purchase, 'showBill']);
$router->get('/api/v1/purchase/{id}', [$purchase, 'show']);
$router->post('/api/v1/purchase', [$purchase, 'create']);
$router->patch('/api/v1/purchase/{id}', [$purchase, 'update']);
$router->post('/api/v1/purchase/{id}/send', [$purchase, 'markSent']);
$router->post('/api/v1/purchase/{id}/confirm', [$purchase, 'confirm']);
$router->post('/api/v1/purchase/{id}/bills', [$purchase, 'createBill']);
$router->post('/api/v1/purchase/bills/{billId}/post', [$purchase, 'postBill']);
$router->post('/api/v1/purchase/bills/{billId}/credit-notes', [$purchase, 'createCreditNote']);
$router->post('/api/v1/purchase/credit-notes/{creditNoteId}/post', [$purchase, 'postCreditNote']);
$router->post('/api/v1/purchase/credit-notes/{creditNoteId}/cancel', [$purchase, 'cancelCreditNote']);
$router->post('/api/v1/purchase/credit-notes/{creditNoteId}/applications', [$purchase, 'applyCreditNote']);
$router->post('/api/v1/purchase/credit-notes/{creditNoteId}/refunds', [$purchase, 'refundCreditNote']);
$router->post('/api/v1/purchase/refunds/{refundId}/reconcile', [$purchase, 'reconcileCreditNoteRefund']);
$router->post('/api/v1/purchase/bills/{billId}/payments', [$purchase, 'payBill']);
$router->post('/api/v1/purchase/payments/{paymentId}/reconcile', [$purchase, 'reconcilePayment']);
$router->post('/api/v1/purchase/taxes', [$purchase, 'createTax']);

$invoicing = new InvoicingController($config);
$router->get('/api/v1/invoicing/options', [$invoicing, 'options']);
$router->get('/api/v1/invoicing', [$invoicing, 'index']);
$router->get('/api/v1/invoicing/{id}', [$invoicing, 'show']);
$router->post('/api/v1/invoicing', [$invoicing, 'create']);
$router->post('/api/v1/invoicing/from-sales/{id}', [$invoicing, 'createFromSales']);
$router->post('/api/v1/invoicing/{id}/credit-notes', [$invoicing, 'createCreditNote']);
$router->post('/api/v1/invoicing/{id}/cancel-credit-note', [$invoicing, 'cancelCreditNote']);
$router->post('/api/v1/invoicing/credit-notes/{creditNoteId}/applications', [$invoicing, 'applyCreditNote']);
$router->post('/api/v1/invoicing/{id}/refunds', [$invoicing, 'registerCreditNoteRefund']);
$router->post('/api/v1/invoicing/refunds/{id}/reconcile', [$invoicing, 'reconcileCreditNoteRefund']);
$router->post('/api/v1/invoicing/{id}/post', [$invoicing, 'post']);
$router->post('/api/v1/invoicing/{id}/payments', [$invoicing, 'registerPayment']);
$router->post('/api/v1/invoicing/payments/{id}/reconcile', [$invoicing, 'reconcilePayment']);
$router->post('/api/v1/invoicing/accounts', [$invoicing, 'createAccount']);
$router->post('/api/v1/invoicing/journals', [$invoicing, 'createJournal']);

$response = $router->dispatch($request);
$response->send();
