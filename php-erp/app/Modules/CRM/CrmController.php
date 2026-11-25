<?php
declare(strict_types=1);

namespace Erp\Modules\CRM;

use Erp\Core\Config;
use Erp\Core\Database;
use Erp\Core\Http\Request;
use Erp\Core\Http\Response;
use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\AuthenticationRequiredException;
use Erp\Core\Security\AuthService;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\Session;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDOException;
use Throwable;

final class CrmController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function options(Request $request, array $params): Response
    {
        return $this->run('crm.read', 'CRM options could not be loaded.', function (UserContext $user, Authorization $authorization, CrmRepository $repository) use ($request): Response {
            return Response::json(['ok' => true, 'options' => $repository->options($user, $authorization, $request->query()['company_id'] ?? null)]);
        });
    }

    public function configuration(Request $request, array $params): Response
    {
        return $this->run('settings.write', 'CRM pipeline settings could not be loaded.', function (UserContext $user, Authorization $authorization, CrmRepository $repository) use ($request): Response {
            return Response::json(['ok' => true, 'configuration' => $repository->configuration($user, $authorization, $request->query()['company_id'] ?? null)]);
        });
    }

    public function saveStage(Request $request, array $params): Response
    {
        return $this->mutate($request, 'settings.write', 'CRM stage could not be saved.', function (UserContext $user, Authorization $authorization, CrmRepository $repository) use ($request, $params): Response {
            $id = isset($params['id']) ? $this->routeId($params) : null;
            return Response::json(['ok' => true] + $repository->saveStage($id, $request->input(), $user, $authorization, $user->id, $request->remoteAddress()), $id === null ? 201 : 200);
        });
    }

    public function saveTeam(Request $request, array $params): Response
    {
        return $this->mutate($request, 'settings.write', 'CRM sales team could not be saved.', function (UserContext $user, Authorization $authorization, CrmRepository $repository) use ($request, $params): Response {
            $id = isset($params['id']) ? $this->routeId($params) : null;
            return Response::json(['ok' => true] + $repository->saveTeam($id, $request->input(), $user, $authorization, $user->id, $request->remoteAddress()), $id === null ? 201 : 200);
        });
    }

    public function index(Request $request, array $params): Response
    {
        return $this->run('crm.read', 'CRM records could not be loaded.', function (UserContext $user, Authorization $authorization, CrmRepository $repository) use ($request): Response {
            return Response::json(['ok' => true] + $repository->search($user, $authorization, $request->query()));
        });
    }

    public function show(Request $request, array $params): Response
    {
        return $this->run('crm.read', 'CRM record could not be loaded.', function (UserContext $user, Authorization $authorization, CrmRepository $repository) use ($params): Response {
            $lead = $repository->find($this->routeId($params), $user, $authorization);
            return $lead ? Response::json(['ok' => true, 'lead' => $lead]) : Response::json(['ok' => false, 'error' => 'CRM record not found.'], 404);
        });
    }

    public function create(Request $request, array $params): Response
    {
        return $this->save($request, null);
    }

    public function update(Request $request, array $params): Response
    {
        try { return $this->save($request, $this->routeId($params)); }
        catch (InvalidArgumentException $error) { return Response::json(['ok' => false, 'error' => $error->getMessage()], 422); }
    }

    public function convert(Request $request, array $params): Response
    {
        return $this->mutate($request, 'crm.write', 'CRM lead could not be converted.', function (UserContext $user, Authorization $authorization, CrmRepository $repository) use ($request, $params): Response {
            $lead = $repository->convert($this->routeId($params), $request->input(), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'lead' => $lead]);
        });
    }

    public function won(Request $request, array $params): Response
    {
        return $this->close($request, $params, 'won');
    }

    public function lost(Request $request, array $params): Response
    {
        return $this->close($request, $params, 'lost');
    }

    public function addActivity(Request $request, array $params): Response
    {
        return $this->mutate($request, 'crm.write', 'Activity could not be scheduled.', function (UserContext $user, Authorization $authorization, CrmRepository $repository) use ($request, $params): Response {
            $assignee = $request->input()['assigned_user_id'] ?? null;
            if ($assignee !== null && (int)$assignee !== $user->id) $this->requirePermission($authorization, 'crm.assign');
            $lead = $repository->addActivity($this->routeId($params, 'leadId'), $request->input(), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'lead' => $lead], 201);
        });
    }

    public function completeActivity(Request $request, array $params): Response
    {
        return $this->mutate($request, 'crm.write', 'Activity could not be completed.', function (UserContext $user, Authorization $authorization, CrmRepository $repository) use ($request, $params): Response {
            if ($request->input()) throw new InvalidArgumentException('Activity completion does not accept additional fields.');
            $lead = $repository->completeActivity($this->routeId($params), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'lead' => $lead]);
        });
    }

    private function save(Request $request, ?int $id): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return $this->csrfExpired();
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'crm.write');
            if ($id === null) {
                $input = $request->input();
                $assigningOther = !empty($input['salesperson_user_id']) && (int)$input['salesperson_user_id'] !== $user->id;
                if ($assigningOther || !empty($input['team_id'])) $this->requirePermission($authorization, 'crm.assign');
            }
            if ($id !== null && (array_key_exists('salesperson_user_id', $request->input()) || array_key_exists('team_id', $request->input()))) {
                $existing = $repository->find($id, $user, $authorization);
                if (!$existing) return Response::json(['ok' => false, 'error' => 'CRM record not found.'], 404);
                $input = $request->input();
                $assignmentChanged = (array_key_exists('salesperson_user_id', $input) && (string)($input['salesperson_user_id'] ?? '') !== (string)($existing['salesperson_user_id'] ?? ''))
                    || (array_key_exists('team_id', $input) && (string)($input['team_id'] ?? '') !== (string)($existing['team_id'] ?? ''));
                if ($assignmentChanged) $this->requirePermission($authorization, 'crm.assign');
            }
            $lead = $repository->save($id, $request->input(), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'lead' => $lead], $id === null ? 201 : 200);
        } catch (AccessDeniedException $error) { return $this->forbidden($error->getMessage()); }
        catch (AuthenticationRequiredException $error) { return $this->unauthorized($error->getMessage()); }
        catch (InvalidArgumentException $error) { return Response::json(['ok' => false, 'error' => $error->getMessage()], 422); }
        catch (PDOException $error) { return $this->databaseFailure($error, 'CRM record could not be saved.'); }
        catch (Throwable $error) { error_log('CRM save failed: ' . $error->getMessage()); return Response::json(['ok' => false, 'error' => 'CRM record could not be saved.'], 500); }
    }

    private function close(Request $request, array $params, string $state): Response
    {
        return $this->mutate($request, 'crm.write', 'Opportunity could not be closed.', function (UserContext $user, Authorization $authorization, CrmRepository $repository) use ($request, $params, $state): Response {
            $lead = $repository->close($this->routeId($params), $state, $request->input(), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'lead' => $lead]);
        });
    }

    /** @param callable(UserContext,Authorization,CrmRepository):Response $action */
    private function mutate(Request $request, string $permission, string $failure, callable $action): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return $this->csrfExpired();
        return $this->run($permission, $failure, $action);
    }

    /** @param callable(UserContext,Authorization,CrmRepository):Response $action */
    private function run(string $permission, string $failure, callable $action): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, $permission);
            return $action($user, $authorization, $repository);
        } catch (AccessDeniedException $error) { return $this->forbidden($error->getMessage()); }
        catch (AuthenticationRequiredException $error) { return $this->unauthorized($error->getMessage()); }
        catch (InvalidArgumentException $error) { return Response::json(['ok' => false, 'error' => $error->getMessage()], 422); }
        catch (PDOException $error) { return $this->databaseFailure($error, $failure); }
        catch (Throwable $error) { error_log('CRM request failed: ' . $error->getMessage()); return Response::json(['ok' => false, 'error' => $failure], 500); }
    }

    /** @return array{UserContext, Authorization, CrmRepository} */
    private function context(): array
    {
        $pdo = Database::connection($this->config);
        $user = (new AuthService($pdo))->currentUser();
        if (!$user) throw new AuthenticationRequiredException('Sign in to use CRM.');
        return [$user, new Authorization($pdo, $user), new CrmRepository($pdo)];
    }

    private function requirePermission(Authorization $authorization, string $permission): void
    {
        if (!$authorization->allows($permission)) throw new AccessDeniedException('You do not have permission to perform this CRM action.');
    }

    private function routeId(array $params, string $key = 'id'): int
    {
        $id = filter_var($params[$key] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new InvalidArgumentException('CRM record ID must be a positive integer.');
        return $id;
    }

    private function databaseFailure(PDOException $error, string $message): Response
    {
        error_log('CRM database operation failed: ' . $error->getMessage());
        $state = (string)($error->errorInfo[0] ?? $error->getCode());
        if (str_starts_with($state, '23')) return Response::json(['ok' => false, 'error' => 'A duplicate or linked-record conflict prevented this CRM action.'], 409);
        return Response::json(['ok' => false, 'error' => $message], 500);
    }

    private function csrfExpired(): Response { return Response::json(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419); }
    private function forbidden(string $message): Response { return Response::json(['ok' => false, 'error' => $message], 403); }
    private function unauthorized(string $message): Response { return Response::json(['ok' => false, 'error' => $message], 401); }
}
