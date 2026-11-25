<?php
declare(strict_types=1);

namespace Erp\Modules\Todo;

use Erp\Core\Config;
use Erp\Core\Database;
use Erp\Core\Http\Request;
use Erp\Core\Http\Response;
use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\AuthenticationRequiredException;
use Erp\Core\Security\AuthService;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\Session;
use InvalidArgumentException;
use Throwable;

final class TodoNotificationController
{
    public function __construct(private readonly Config $config) {}

    public function inbox(Request $request, array $params): Response
    {
        try {
            [$user, $auth, $repository] = $this->context();
            return Response::json(['ok' => true] + $repository->inbox($user, $auth, $request->query()));
        } catch (AccessDeniedException $error) { return Response::json(['ok' => false, 'error' => $error->getMessage()], 403); }
        catch (AuthenticationRequiredException $error) { return Response::json(['ok' => false, 'error' => $error->getMessage()], 401); }
        catch (InvalidArgumentException $error) { return Response::json(['ok' => false, 'error' => $error->getMessage()], 422); }
        catch (Throwable $error) { error_log('To-do notifications failed: ' . $error->getMessage()); return Response::json(['ok' => false, 'error' => 'Notifications could not be loaded.'], 500); }
    }

    public function markRead(Request $request, array $params): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return Response::json(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419);
        try {
            [$user, $auth, $repository] = $this->context();
            $repository->markRead($params['id'] ?? null, $user, $auth);
            return Response::json(['ok' => true]);
        } catch (AccessDeniedException $error) { return Response::json(['ok' => false, 'error' => $error->getMessage()], 403); }
        catch (AuthenticationRequiredException $error) { return Response::json(['ok' => false, 'error' => $error->getMessage()], 401); }
        catch (InvalidArgumentException $error) { return Response::json(['ok' => false, 'error' => $error->getMessage()], 404); }
        catch (Throwable $error) { error_log('To-do notification update failed: ' . $error->getMessage()); return Response::json(['ok' => false, 'error' => 'Notification could not be updated.'], 500); }
    }

    public function markAllRead(Request $request, array $params): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return Response::json(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419);
        try {
            [$user, $auth, $repository] = $this->context();
            return Response::json(['ok' => true, 'updated' => $repository->markAllRead($user, $auth)]);
        } catch (AccessDeniedException $error) { return Response::json(['ok' => false, 'error' => $error->getMessage()], 403); }
        catch (AuthenticationRequiredException $error) { return Response::json(['ok' => false, 'error' => $error->getMessage()], 401); }
        catch (Throwable $error) { error_log('To-do notifications update failed: ' . $error->getMessage()); return Response::json(['ok' => false, 'error' => 'Notifications could not be updated.'], 500); }
    }

    private function context(): array
    {
        $pdo = Database::connection($this->config);
        $user = (new AuthService($pdo))->currentUser();
        if (!$user) throw new AuthenticationRequiredException('Sign in to read notifications.');
        return [$user, new Authorization($pdo, $user), new TodoNotificationRepository($pdo)];
    }
}
