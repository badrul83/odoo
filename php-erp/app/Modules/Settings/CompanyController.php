<?php
declare(strict_types=1);

namespace Erp\Modules\Settings;

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
use DateTimeZone;
use InvalidArgumentException;
use PDOException;
use Throwable;

final class CompanyController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function index(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'settings.read');
            return Response::json(['ok' => true] + $repository->search($user));
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (Throwable $error) {
            error_log('Company list failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Companies could not be loaded.'], 500);
        }
    }

    public function options(Request $request, array $params): Response
    {
        try {
            [$user, $authorization] = $this->context();
            if (!$authorization->allows('settings.read') && !$authorization->allows('settings.write')) {
                throw new AccessDeniedException('You do not have permission to view company configuration options.');
            }
            $pdo = Database::connection($this->config);
            $currencies = $pdo->query('SELECT currency_code AS value, name, symbol FROM currencies WHERE active = 1 ORDER BY name')->fetchAll();
            $timezones = array_map(static fn (string $zone): array => ['value' => $zone, 'name' => $zone], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC));
            return Response::json(['ok' => true, 'options' => ['currencies' => $currencies, 'timezones' => $timezones]]);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (Throwable $error) {
            error_log('Company options failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Company options could not be loaded.'], 500);
        }
    }

    public function logo(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'settings.read');
            $companyId = $this->routeId($params, 'companyId');
            $company = $repository->find($companyId, $user);
            if (!$company) return Response::json(['ok' => false, 'error' => 'Company not found.'], 404);
            $path = $company['logo_path'] ?? null;
            if (isset($params['branchId'])) {
                $branchId = $this->routeId($params, 'branchId');
                $branch = null;
                foreach ($company['branches'] as $candidate) if ((int)$candidate['id'] === $branchId) { $branch = $candidate; break; }
                if (!$branch) return Response::json(['ok' => false, 'error' => 'Branch not found.'], 404);
                $path = $branch['logo_path'] ?? null;
            }
            $asset = $path ? (new CompanyLogoStorage($this->config))->read((string)$path) : null;
            return $asset
                ? Response::raw($asset['body'], $asset['mime'], 200, ['Content-Disposition' => 'inline', 'X-Content-Type-Options' => 'nosniff'])
                : Response::json(['ok' => false, 'error' => 'Logo not found.'], 404);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Company logo read failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Company logo could not be loaded.'], 500);
        }
    }

    public function uploadLogo(Request $request, array $params): Response
    {
        if (!$this->csrfValid($request)) return $this->csrfExpired();
        $storage = new CompanyLogoStorage($this->config);
        $newPath = null;
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'settings.write');
            $companyId = $this->routeId($params, 'companyId');
            $company = $repository->find($companyId, $user);
            if (!$company) return Response::json(['ok' => false, 'error' => 'Company not found.'], 404);
            $branchId = isset($params['branchId']) ? $this->routeId($params, 'branchId') : null;
            if ($branchId !== null && !array_filter($company['branches'], static fn (array $branch): bool => (int)$branch['id'] === $branchId)) {
                return Response::json(['ok' => false, 'error' => 'Branch not found.'], 404);
            }
            $oldPath = $company['logo_path'] ?? null;
            if ($branchId !== null) foreach ($company['branches'] as $branch) if ((int)$branch['id'] === $branchId) $oldPath = $branch['logo_path'] ?? null;
            $newPath = $storage->store($request->file('logo'));
            $saved = $branchId === null
                ? $repository->setCompanyLogo($companyId, $newPath, $user, $user->id, $request->remoteAddress())
                : $repository->setBranchLogo($companyId, $branchId, $newPath, $user, $user->id, $request->remoteAddress());
            $storage->delete(is_string($oldPath) ? $oldPath : null);
            return Response::json(['ok' => true, 'company' => $branchId === null ? $saved : null, 'branch' => $branchId === null ? null : $saved]);
        } catch (AccessDeniedException $error) {
            $storage->delete($newPath);
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            $storage->delete($newPath);
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            $storage->delete($newPath);
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            $storage->delete($newPath);
            error_log('Company logo upload failed: ' . $error->getMessage());
            return $this->databaseError($error);
        } catch (Throwable $error) {
            $storage->delete($newPath);
            error_log('Company logo upload failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Company logo could not be saved.'], 500);
        }
    }

    public function removeLogo(Request $request, array $params): Response
    {
        if (!$this->csrfValid($request)) return $this->csrfExpired();
        $storage = new CompanyLogoStorage($this->config);
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'settings.write');
            $companyId = $this->routeId($params, 'companyId');
            $company = $repository->find($companyId, $user);
            if (!$company) return Response::json(['ok' => false, 'error' => 'Company not found.'], 404);
            $branchId = isset($params['branchId']) ? $this->routeId($params, 'branchId') : null;
            $oldPath = $company['logo_path'] ?? null;
            if ($branchId !== null) {
                $branch = null;
                foreach ($company['branches'] as $candidate) if ((int)$candidate['id'] === $branchId) { $branch = $candidate; break; }
                if (!$branch) return Response::json(['ok' => false, 'error' => 'Branch not found.'], 404);
                $oldPath = $branch['logo_path'] ?? null;
            }
            $saved = $branchId === null
                ? $repository->setCompanyLogo($companyId, null, $user, $user->id, $request->remoteAddress())
                : $repository->setBranchLogo($companyId, $branchId, null, $user, $user->id, $request->remoteAddress());
            $storage->delete(is_string($oldPath) ? $oldPath : null);
            return Response::json(['ok' => true, 'company' => $branchId === null ? $saved : null, 'branch' => $branchId === null ? null : $saved]);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log('Company logo removal failed: ' . $error->getMessage());
            return $this->databaseError($error);
        } catch (Throwable $error) {
            error_log('Company logo removal failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Company logo could not be removed.'], 500);
        }
    }

    public function show(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'settings.read');
            $id = $this->routeId($params, 'companyId');
            $company = $repository->find($id, $user);
            return $company
                ? Response::json(['ok' => true, 'company' => $company])
                : Response::json(['ok' => false, 'error' => 'Company not found.'], 404);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Company read failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Company could not be loaded.'], 500);
        }
    }

    public function create(Request $request, array $params): Response
    {
        return $this->save($request, null);
    }

    public function update(Request $request, array $params): Response
    {
        try {
            return $this->save($request, $this->routeId($params, 'companyId'));
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        }
    }

    public function branches(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'settings.read');
            $company = $repository->find($this->routeId($params, 'companyId'), $user);
            return $company
                ? Response::json(['ok' => true, 'branches' => $company['branches']])
                : Response::json(['ok' => false, 'error' => 'Company not found.'], 404);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Company branch list failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Company branches could not be loaded.'], 500);
        }
    }

    public function documentPrefixes(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'settings.read');
            $companyId = $this->routeId($params, 'companyId');
            return Response::json(['ok' => true, 'prefixes' => $repository->documentPrefixes($companyId, $user)]);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Company document prefixes could not be loaded: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Document prefixes could not be loaded.'], 500);
        }
    }

    public function saveDocumentPrefixes(Request $request, array $params): Response
    {
        if (!$this->csrfValid($request)) return $this->csrfExpired();
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'settings.write');
            $companyId = $this->routeId($params, 'companyId');
            $prefixes = $repository->saveDocumentPrefixes($companyId, $request->input(), $user, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'prefixes' => $prefixes]);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log('Company document prefix save failed: ' . $error->getMessage());
            return $this->databaseError($error);
        } catch (Throwable $error) {
            error_log('Company document prefix save failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Document prefixes could not be saved.'], 500);
        }
    }

    public function createBranch(Request $request, array $params): Response
    {
        if (!$this->csrfValid($request)) {
            return $this->csrfExpired();
        }
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'settings.write');
            $branch = $repository->createBranch(
                $this->routeId($params, 'companyId'),
                $request->input(),
                $user,
                $user->id,
                $request->remoteAddress(),
            );
            return Response::json(['ok' => true, 'branch' => $branch], 201);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log('Branch create failed: ' . $error->getMessage());
            return $this->databaseError($error);
        } catch (Throwable $error) {
            error_log('Branch create failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Branch could not be created.'], 500);
        }
    }

    public function updateBranch(Request $request, array $params): Response
    {
        if (!$this->csrfValid($request)) {
            return $this->csrfExpired();
        }
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'settings.write');
            $branch = $repository->updateBranch(
                $this->routeId($params, 'companyId'),
                $this->routeId($params, 'branchId'),
                $request->input(),
                $user,
                $user->id,
                $request->remoteAddress(),
            );
            return Response::json(['ok' => true, 'branch' => $branch]);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log('Branch update failed: ' . $error->getMessage());
            return $this->databaseError($error);
        } catch (Throwable $error) {
            error_log('Branch update failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Branch could not be updated.'], 500);
        }
    }

    public function archiveBranch(Request $request, array $params): Response
    {
        if (!$this->csrfValid($request)) {
            return $this->csrfExpired();
        }
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'settings.write');
            $repository->archiveBranch(
                $this->routeId($params, 'companyId'),
                $this->routeId($params, 'branchId'),
                $user,
                $user->id,
                $request->remoteAddress(),
            );
            return Response::json(['ok' => true]);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log('Branch archive failed: ' . $error->getMessage());
            return $this->databaseError($error);
        } catch (Throwable $error) {
            error_log('Branch archive failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Branch could not be archived.'], 500);
        }
    }

    private function save(Request $request, ?int $id): Response
    {
        if (!$this->csrfValid($request)) {
            return $this->csrfExpired();
        }
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'settings.write');
            $company = $repository->save($id, $request->input(), $user, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'company' => $company], $id === null ? 201 : 200);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log('Company save failed: ' . $error->getMessage());
            return $this->databaseError($error);
        } catch (Throwable $error) {
            error_log('Company save failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Company could not be saved.'], 500);
        }
    }

    /** @return array{UserContext, Authorization, CompanyRepository} */
    private function context(): array
    {
        $pdo = Database::connection($this->config);
        $user = (new AuthService($pdo))->currentUser();
        if (!$user) {
            throw new AuthenticationRequiredException('Sign in to manage company settings.');
        }
        return [$user, new Authorization($pdo, $user), new CompanyRepository($pdo)];
    }

    private function requirePermission(Authorization $authorization, string $permission): void
    {
        if (!$authorization->allows($permission)) {
            throw new AccessDeniedException('You do not have permission to perform this company settings action.');
        }
    }

    private function csrfValid(Request $request): bool
    {
        return Session::verifyCsrf($request->header('x-csrf-token'));
    }

    private function routeId(array $params, string $name): int
    {
        $id = filter_var($params[$name] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new InvalidArgumentException('The ' . $name . ' must be a positive integer.');
        }
        return $id;
    }

    private function databaseError(PDOException $error): Response
    {
        $sqlState = (string)($error->errorInfo[0] ?? $error->getCode());
        return str_starts_with($sqlState, '23')
            ? Response::json(['ok' => false, 'error' => 'A duplicate or linked-record conflict exists.'], 409)
            : Response::json(['ok' => false, 'error' => 'The company setting could not be saved.'], 500);
    }

    private function csrfExpired(): Response
    {
        return Response::json(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419);
    }

    private function forbidden(string $message): Response { return Response::json(['ok' => false, 'error' => $message], 403); }
    private function unauthorized(string $message): Response { return Response::json(['ok' => false, 'error' => $message], 401); }
}
