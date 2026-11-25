<?php
declare(strict_types=1);

namespace Erp\Modules\Dashboards;

use Erp\Core\Config;
use Erp\Core\Database;
use Erp\Core\Http\Request;
use Erp\Core\Http\Response;
use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\AuthenticationRequiredException;
use Erp\Core\Security\AuthService;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDOException;
use Throwable;

final class DashboardController
{
    public function __construct(private readonly Config $config) {}

    public function show(Request $request, array $params): Response
    {
        try {
            $pdo = Database::connection($this->config);
            $user = (new AuthService($pdo))->currentUser();
            if (!$user) throw new AuthenticationRequiredException('Sign in to use Dashboards.');
            $auth = new Authorization($pdo, $user);
            if (!$auth->allows('dashboards.read')) throw new AccessDeniedException('You do not have permission to read Dashboards.');
            $company = $request->query()['company_id'] ?? $user->defaultCompanyId;
            return Response::json(['ok' => true, 'dashboard' => (new DashboardRepository($pdo))->summary($company, $user, $auth)]);
        } catch (AccessDeniedException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 403);
        } catch (AuthenticationRequiredException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 401);
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log('Dashboard database error: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Dashboard data could not be loaded.'], 500);
        } catch (Throwable $error) {
            error_log('Dashboard error: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Dashboard data could not be loaded.'], 500);
        }
    }
}
