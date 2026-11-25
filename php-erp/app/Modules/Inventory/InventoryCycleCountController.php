<?php
declare(strict_types=1);

namespace Erp\Modules\Inventory;

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

final class InventoryCycleCountController
{
    public function __construct(private readonly Config $config) {}

    public function index(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'inventory.read');
            return Response::json(['ok' => true] + $repository->search($user, $authorization, $request->query()));
        } catch (Throwable $error) { return $this->failure($error, 'Inventory cycle counts could not be loaded.'); }
    }

    public function options(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'inventory.read');
            return Response::json(['ok' => true, 'options' => $repository->options($user, $authorization, $request->query()['company_id'] ?? null)]);
        } catch (Throwable $error) { return $this->failure($error, 'Cycle count options could not be loaded.'); }
    }

    public function saveLocationSchedule(Request $request, array $params): Response
    {
        return $this->mutate($request, function (array $context) use ($request, $params): Response {
            [$user, $authorization, $repository] = $context;
            $this->requirePermission($authorization, 'inventory.write');
            $locationId = $this->routeId($params['locationId'] ?? null);
            $schedule = $repository->saveLocationSchedule($locationId, $request->input(), $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'location_schedule' => $schedule]);
        }, 'Cycle count schedule could not be saved.');
    }

    public function show(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'inventory.read');
            $count = $repository->find($this->routeId($params['id'] ?? null), $user, $authorization);
            return $count
                ? Response::json(['ok' => true, 'cycle_count' => $count])
                : Response::json(['ok' => false, 'error' => 'Inventory cycle count was not found.'], 404);
        } catch (Throwable $error) { return $this->failure($error, 'Inventory cycle count could not be loaded.'); }
    }

    public function create(Request $request, array $params): Response
    {
        return $this->mutate($request, function (array $context) use ($request): Response {
            [$user, $authorization, $repository] = $context;
            $this->requirePermission($authorization, 'inventory.write');
            $count = $repository->create($request->input(), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'cycle_count' => $count], 201);
        }, 'Inventory cycle count could not be created.');
    }

    public function addLine(Request $request, array $params): Response
    {
        return $this->mutate($request, function (array $context) use ($request, $params): Response {
            [$user, $authorization, $repository] = $context;
            $this->requirePermission($authorization, 'inventory.write');
            $count = $repository->addLine($this->routeId($params['id'] ?? null), $request->input(), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'cycle_count' => $count]);
        }, 'Inventory cycle count line could not be added.');
    }

    public function updateLine(Request $request, array $params): Response
    {
        return $this->mutate($request, function (array $context) use ($request, $params): Response {
            [$user, $authorization, $repository] = $context;
            $this->requirePermission($authorization, 'inventory.write');
            $count = $repository->setLineCounted(
                $this->routeId($params['id'] ?? null),
                $this->routeId($params['lineId'] ?? null),
                $request->input(), $user, $authorization, $user->id, $request->remoteAddress(),
            );
            return Response::json(['ok' => true, 'cycle_count' => $count]);
        }, 'Counted quantity could not be saved.');
    }

    public function validateCount(Request $request, array $params): Response
    {
        return $this->mutate($request, function (array $context) use ($request, $params): Response {
            [$user, $authorization, $repository] = $context;
            $this->requirePermission($authorization, 'inventory.validate');
            if ($request->input()) throw new InvalidArgumentException('Cycle count validation does not accept additional fields.');
            $count = $repository->validate($this->routeId($params['id'] ?? null), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'cycle_count' => $count]);
        }, 'Inventory cycle count could not be validated.');
    }

    public function cancel(Request $request, array $params): Response
    {
        return $this->mutate($request, function (array $context) use ($request, $params): Response {
            [$user, $authorization, $repository] = $context;
            $this->requirePermission($authorization, 'inventory.write');
            if ($request->input()) throw new InvalidArgumentException('Cycle count cancellation does not accept additional fields.');
            $count = $repository->cancel($this->routeId($params['id'] ?? null), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'cycle_count' => $count]);
        }, 'Inventory cycle count could not be cancelled.');
    }

    private function mutate(Request $request, callable $operation, string $fallback): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return Response::json(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419);
        try { return $operation($this->context()); }
        catch (Throwable $error) { return $this->failure($error, $fallback); }
    }

    /** @return array{UserContext, Authorization, InventoryCycleCountRepository} */
    private function context(): array
    {
        $pdo = Database::connection($this->config);
        $user = (new AuthService($pdo))->currentUser();
        if (!$user) throw new AuthenticationRequiredException('Sign in to use Inventory.');
        return [$user, new Authorization($pdo, $user), new InventoryCycleCountRepository($pdo)];
    }

    private function requirePermission(Authorization $authorization, string $permission): void
    {
        if (!$authorization->allows($permission)) throw new AccessDeniedException('You do not have permission to perform this Inventory action.');
    }

    private function routeId(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new InvalidArgumentException('Cycle count and line IDs must be positive integers.');
        return $id;
    }

    private function failure(Throwable $error, string $fallback): Response
    {
        if ($error instanceof AccessDeniedException) return Response::json(['ok' => false, 'error' => $error->getMessage()], 403);
        if ($error instanceof AuthenticationRequiredException) return Response::json(['ok' => false, 'error' => $error->getMessage()], 401);
        if ($error instanceof InvalidArgumentException) return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        if ($error instanceof PDOException) {
            error_log('Inventory cycle count failed: ' . $error->getMessage());
            $sqlState = (string)($error->errorInfo[0] ?? $error->getCode());
            if (str_starts_with($sqlState, '23')) return Response::json(['ok' => false, 'error' => 'A duplicate product position or linked inventory record prevented this operation. Reload the count and review its lines.'], 409);
        } else {
            error_log('Inventory cycle count failed: ' . $error->getMessage());
        }
        return Response::json(['ok' => false, 'error' => $fallback], 500);
    }
}
