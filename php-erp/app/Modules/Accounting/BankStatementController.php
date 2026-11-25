<?php
declare(strict_types=1);

namespace Erp\Modules\Accounting;

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

final class BankStatementController
{
    public function __construct(private readonly Config $config) {}

    public function options(Request $request, array $params): Response
    {
        return $this->run('Bank statement options could not be loaded.', function(UserContext $user, Authorization $auth, BankStatementRepository $repo) use ($request): Response {
            return Response::json(['ok' => true, 'options' => $repo->options($request->query()['company_id'] ?? $user->defaultCompanyId, $user, $auth)]);
        });
    }

    public function index(Request $request, array $params): Response
    {
        return $this->run('Bank statements could not be loaded.', function(UserContext $user, Authorization $auth, BankStatementRepository $repo) use ($request): Response {
            return Response::json(['ok' => true] + $repo->search($request->query(), $user, $auth));
        });
    }

    public function show(Request $request, array $params): Response
    {
        return $this->run('Bank statement could not be loaded.', function(UserContext $user, Authorization $auth, BankStatementRepository $repo) use ($request, $params): Response {
            return Response::json(['ok' => true] + $repo->find($this->routeId($params, 'id'), $request->query(), $user, $auth));
        });
    }

    public function candidates(Request $request, array $params): Response
    {
        return $this->run('Payment candidates could not be loaded.', function(UserContext $user, Authorization $auth, BankStatementRepository $repo) use ($params): Response {
            return Response::json(['ok' => true] + $repo->candidates($this->routeId($params, 'statementId'), $this->routeId($params, 'lineId'), $user, $auth));
        });
    }

    public function categorize(Request $request, array $params): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return Response::json(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419);
        return $this->run('Bank statement line could not be categorized.', function(UserContext $user, Authorization $auth, BankStatementRepository $repo) use ($request, $params): Response {
            if (!$auth->allows('accounting.reconcile') || !$auth->allows('accounting.post')) throw new AccessDeniedException('You need Accounting Reconciliation and Posting access to categorize a statement line.');
            $result = $repo->categorize($this->routeId($params, 'statementId'), $this->routeId($params, 'lineId'), $request->input(), $user, $auth, $request->remoteAddress());
            return Response::json(['ok' => true] + $result);
        });
    }

    public function import(Request $request, array $params): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return Response::json(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419);
        $file = $request->file('statement_file');
        if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return Response::json(['ok' => false, 'error' => 'Choose a CSV statement file to import.'], 422);
        $size = (int)($file['size'] ?? 0);
        $path = (string)($file['tmp_name'] ?? '');
        if ($size < 1 || $size > 5_000_000 || $path === '' || !is_uploaded_file($path)) return Response::json(['ok' => false, 'error' => 'The uploaded CSV must be no larger than 5 MB.'], 422);
        $contents = file_get_contents($path);
        if (!is_string($contents)) return Response::json(['ok' => false, 'error' => 'The uploaded CSV could not be read.'], 422);
        return $this->run('Bank statement could not be imported.', function(UserContext $user, Authorization $auth, BankStatementRepository $repo) use ($request, $file, $contents): Response {
            if (!$auth->allows('accounting.reconcile')) throw new AccessDeniedException('You do not have permission to import bank statements.');
            $result = $repo->import($request->input(), (string)($file['name'] ?? ''), $contents, $user, $auth, $request->remoteAddress());
            return Response::json(['ok' => true] + $result, !empty($result['duplicate']) ? 200 : 201);
        });
    }

    private function run(string $failure, callable $action): Response
    {
        try {
            $pdo = Database::connection($this->config);
            $user = (new AuthService($pdo))->currentUser();
            if (!$user) throw new AuthenticationRequiredException('Sign in to use Bank Statements.');
            return $action($user, new Authorization($pdo, $user), new BankStatementRepository($pdo));
        } catch (AccessDeniedException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 403);
        } catch (AuthenticationRequiredException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 401);
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log('Bank statement database error: ' . $error->getMessage());
            $state = (string)($error->errorInfo[0] ?? $error->getCode());
            return str_starts_with($state, '23')
                ? Response::json(['ok' => false, 'error' => 'A duplicate or linked-record constraint prevented this bank-statement operation.'], 409)
                : Response::json(['ok' => false, 'error' => $failure], 500);
        } catch (Throwable $error) {
            error_log('Bank statement operation failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => $failure], 500);
        }
    }

    private function routeId(array $params, string $key): int
    {
        $id = filter_var($params[$key] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new InvalidArgumentException('Bank statement record IDs must be positive integers.');
        return $id;
    }
}
