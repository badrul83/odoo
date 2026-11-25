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

final class StockMoveController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function index(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'inventory.read');
            return Response::json(['ok' => true] + $repository->search($user, $authorization, $request->query()));
        } catch (Throwable $error) { return $this->failure($error, 'Stock moves could not be loaded.'); }
    }

    public function show(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'inventory.read');
            $move = $repository->find($this->routeId($params), $user, $authorization);
            return $move ? Response::json(['ok' => true, 'move' => $move]) : Response::json(['ok' => false, 'error' => 'Stock move not found.'], 404);
        } catch (Throwable $error) { return $this->failure($error, 'Stock move could not be loaded.'); }
    }

    public function create(Request $request, array $params): Response
    {
        return $this->save($request, null);
    }

    public function update(Request $request, array $params): Response
    {
        try { return $this->save($request, $this->routeId($params)); }
        catch (Throwable $error) { return $this->failure($error, 'Stock move could not be saved.'); }
    }

    public function confirm(Request $request, array $params): Response
    {
        return $this->transition($request, $params, 'inventory.write', 'confirm');
    }

    public function validateMove(Request $request, array $params): Response
    {
        return $this->transition($request, $params, 'inventory.validate', 'validate');
    }

    public function cancel(Request $request, array $params): Response
    {
        return $this->transition($request, $params, 'inventory.write', 'cancel');
    }

    public function createReturn(Request $request, array $params): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return $this->csrfExpired();
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'inventory.write');
            $move = $repository->createCustomerReturn($this->routeId($params), $request->input(), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'move' => $move], 201);
        } catch (Throwable $error) { return $this->failure($error, 'Customer return could not be created.'); }
    }

    public function createSupplierReturn(Request $request, array $params): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return $this->csrfExpired();
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'inventory.write');
            $move = $repository->createSupplierReturn($this->routeId($params), $request->input(), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'move' => $move], 201);
        } catch (Throwable $error) { return $this->failure($error, 'Supplier return could not be created.'); }
    }

    public function stockLevels(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'inventory.read');
            $result = $repository->stockLevels($user, $authorization, $request->query()['company_id'] ?? null);
            return Response::json(['ok' => true] + $result);
        } catch (Throwable $error) { return $this->failure($error, 'Stock levels could not be loaded.'); }
    }

    public function quants(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'inventory.read');
            $query = $request->query();
            $companyId = $this->positiveId($query['company_id'] ?? null, 'Company');
            $productId = $this->positiveId($query['product_id'] ?? null, 'Product');
            return Response::json(['ok' => true, 'records' => $repository->quants($user, $authorization, $companyId, $productId)]);
        } catch (Throwable $error) { return $this->failure($error, 'Product stock could not be loaded.'); }
    }

    private function save(Request $request, ?int $id): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return $this->csrfExpired();
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'inventory.write');
            $move = $repository->saveDraft($id, $request->input(), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'move' => $move], $id === null ? 201 : 200);
        } catch (Throwable $error) { return $this->failure($error, 'Stock move could not be saved.'); }
    }

    private function transition(Request $request, array $params, string $permission, string $action): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return $this->csrfExpired();
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, $permission);
            $id = $this->routeId($params);
            $method = match ($action) { 'confirm' => 'confirm', 'validate' => 'validate', 'cancel' => 'cancel' };
            $move = $action === 'validate'
                ? $repository->{$method}($id, $user, $authorization, $user->id, $request->remoteAddress(), $request->input())
                : $repository->{$method}($id, $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'move' => $move]);
        } catch (Throwable $error) { return $this->failure($error, 'Stock move transition could not be completed.'); }
    }

    /** @return array{UserContext, Authorization, StockMoveRepository} */
    private function context(): array
    {
        $pdo = Database::connection($this->config);
        $user = (new AuthService($pdo))->currentUser();
        if (!$user) throw new AuthenticationRequiredException('Sign in to use Inventory.');
        return [$user, new Authorization($pdo, $user), new StockMoveRepository($pdo)];
    }

    private function requirePermission(Authorization $authorization, string $permission): void
    {
        if (!$authorization->allows($permission)) throw new AccessDeniedException('You do not have permission to perform this Inventory action.');
    }

    private function routeId(array $params): int { return $this->positiveId($params['id'] ?? null, 'Stock move'); }

    private function positiveId(mixed $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new InvalidArgumentException($label . ' ID must be a positive integer.');
        return $id;
    }

    private function failure(Throwable $error, string $fallback): Response
    {
        if ($error instanceof AccessDeniedException) return Response::json(['ok' => false, 'error' => $error->getMessage()], 403);
        if ($error instanceof AuthenticationRequiredException) return Response::json(['ok' => false, 'error' => $error->getMessage()], 401);
        if ($error instanceof InvalidArgumentException) return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        if ($error instanceof PDOException) {
            error_log('Inventory stock operation failed: ' . $error->getMessage());
            $sqlState = (string)($error->errorInfo[0] ?? $error->getCode());
            if (str_starts_with($sqlState, '23')) return Response::json(['ok' => false, 'error' => 'A duplicate reference or linked inventory record prevented this operation.'], 409);
        } else {
            error_log('Inventory stock operation failed: ' . $error->getMessage());
        }
        return Response::json(['ok' => false, 'error' => $fallback], 500);
    }

    private function csrfExpired(): Response { return Response::json(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419); }
}
