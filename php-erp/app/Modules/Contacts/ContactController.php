<?php
declare(strict_types=1);

namespace Erp\Modules\Contacts;

use Erp\Core\Config;
use Erp\Core\Database;
use Erp\Core\Http\Request;
use Erp\Core\Http\Response;
use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\AuthenticationRequiredException;
use Erp\Core\Security\AuthService;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\DataCipher;
use Erp\Core\Security\Session;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDOException;
use Throwable;

final class ContactController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function index(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'contacts.read');
            $result = $repository->search($user, $authorization, $request->query());
            return Response::json(['ok' => true] + $result);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Contacts list failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Contacts could not be loaded.'], 500);
        }
    }

    public function show(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'contacts.read');
            $id = $this->routeId($params);
            $contact = $repository->find($id, $user, $authorization);
            return $contact
                ? Response::json(['ok' => true, 'contact' => $contact])
                : Response::json(['ok' => false, 'error' => 'Contact not found.'], 404);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Contact read failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Contact could not be loaded.'], 500);
        }
    }

    public function create(Request $request, array $params): Response
    {
        return $this->save($request, null);
    }

    public function update(Request $request, array $params): Response
    {
        try {
            return $this->save($request, $this->routeId($params));
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        }
    }

    public function status(Request $request, array $params): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) {
            return Response::json(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419);
        }
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'contacts.archive');
            $input = $request->input();
            $unknown = array_diff(array_keys($input), ['active']);
            if ($unknown || !array_key_exists('active', $input)) {
                throw new InvalidArgumentException('Contact status accepts only the active boolean field.');
            }
            $active = filter_var($input['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($active === null) {
                throw new InvalidArgumentException('Contact active status must be a boolean.');
            }
            $contact = $repository->setActive($this->routeId($params), $active, $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'contact' => $contact]);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Contact status change failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Contact status could not be changed.'], 500);
        }
    }

    private function save(Request $request, ?int $id): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) {
            return Response::json(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419);
        }
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, $id === null ? 'contact_master_data.create' : 'contacts.write');
            $saved = $repository->save($id, $request->input(), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'contact' => $saved], $id === null ? 201 : 200);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log('Contact save failed: ' . $error->getMessage());
            $sqlState = (string)($error->errorInfo[0] ?? $error->getCode());
            if (str_starts_with($sqlState, '23')) {
                return Response::json(['ok' => false, 'error' => 'Contact could not be saved because a duplicate or linked-record conflict exists.'], 409);
            }
            return Response::json(['ok' => false, 'error' => 'Contact could not be saved.'], 500);
        } catch (Throwable $error) {
            error_log('Contact save failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Contact could not be saved.'], 500);
        }
    }

    /** @return array{UserContext, Authorization, ContactRepository} */
    private function context(): array
    {
        $pdo = Database::connection($this->config);
        $authService = new AuthService($pdo);
        $user = $authService->currentUser();
        if (!$user) {
            throw new AuthenticationRequiredException('Sign in to use Contacts.');
        }
        return [$user, new Authorization($pdo, $user), new ContactRepository($pdo, new DataCipher($this->config->dataEncryptionKey()))];
    }

    private function requirePermission(Authorization $authorization, string $permission): void
    {
        if (!$authorization->allows($permission)) {
            throw new AccessDeniedException('You do not have permission to perform this Contacts action.');
        }
    }

    private function routeId(array $params): int
    {
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new InvalidArgumentException('Contact ID must be a positive integer.');
        }
        return $id;
    }

    private function forbidden(string $message): Response
    {
        return Response::json(['ok' => false, 'error' => $message], 403);
    }

    private function unauthorized(string $message): Response
    {
        return Response::json(['ok' => false, 'error' => $message], 401);
    }
}
