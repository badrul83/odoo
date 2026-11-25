<?php
declare(strict_types=1);

namespace Erp\Modules\Appointments;

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
use PDOException;
use Throwable;

final class AppointmentController
{
    public function __construct(private readonly Config $config) {}

    public function options(Request $request, array $params): Response
    {
        return $this->handle($request, 'appointments.read', false, fn($repo, $user, $auth) => [
            'ok' => true, 'options' => $repo->options($user, $auth, $request->query()['company_id'] ?? null),
        ]);
    }

    public function types(Request $request, array $params): Response
    {
        return $this->handle($request, 'appointments.read', false, fn($repo, $user, $auth) => ['ok' => true] + $repo->types($user, $auth, $request->query()));
    }

    public function type(Request $request, array $params): Response
    {
        return $this->handle($request, 'appointments.read', false, fn($repo, $user, $auth) => ['ok' => true, 'type' => $repo->type($this->id($params), $user, $auth)]);
    }

    public function availableSlots(Request $request, array $params): Response
    {
        return $this->handle($request, 'appointments.read', false, function($repo, $user, $auth) use ($request, $params) {
            $query = $request->query();
            return ['ok' => true] + $repo->availableSlots($this->id($params), (string)($query['date'] ?? ''), (int)($query['guest_count'] ?? 1), $auth);
        });
    }

    public function saveType(Request $request, array $params): Response
    {
        return $this->handle($request, 'appointments.write', true, fn($repo, $user, $auth) => ['ok' => true, 'type' => $repo->saveType($request->input(), null, $user, $auth, $request->remoteAddress())], 201);
    }

    public function updateType(Request $request, array $params): Response
    {
        return $this->handle($request, 'appointments.write', true, fn($repo, $user, $auth) => ['ok' => true, 'type' => $repo->saveType($request->input(), $this->id($params), $user, $auth, $request->remoteAddress())]);
    }

    public function resources(Request $request, array $params): Response
    {
        return $this->handle($request, 'appointments.read', false, fn($repo, $user, $auth) => ['ok' => true] + $repo->resources($user, $auth, (int)($request->query()['company_id'] ?? $user->defaultCompanyId ?? 0)));
    }

    public function saveResource(Request $request, array $params): Response
    {
        return $this->handle($request, 'appointments.write', true, fn($repo, $user, $auth) => ['ok' => true, 'resource' => $repo->saveResource($request->input(), null, $user, $auth, $request->remoteAddress())], 201);
    }

    public function updateResource(Request $request, array $params): Response
    {
        return $this->handle($request, 'appointments.write', true, fn($repo, $user, $auth) => ['ok' => true, 'resource' => $repo->saveResource($request->input(), $this->id($params), $user, $auth, $request->remoteAddress())]);
    }

    public function bookings(Request $request, array $params): Response
    {
        return $this->handle($request, 'appointments.read', false, fn($repo, $user, $auth) => ['ok' => true] + $repo->bookings($user, $auth, $request->query()));
    }

    public function booking(Request $request, array $params): Response
    {
        return $this->handle($request, 'appointments.read', false, fn($repo, $user, $auth) => ['ok' => true, 'booking' => $repo->booking($this->id($params), $auth)]);
    }

    public function createBooking(Request $request, array $params): Response
    {
        return $this->handle($request, 'appointments.write', true, fn($repo, $user, $auth) => ['ok' => true, 'booking' => $repo->createInternalBooking($request->input(), $user, $auth, $request->remoteAddress())], 201);
    }

    public function setBookingStatus(Request $request, array $params): Response
    {
        return $this->handle($request, 'appointments.write', true, fn($repo, $user, $auth) => ['ok' => true, 'booking' => $repo->updateBookingStatus($this->id($params), (string)($request->input()['status'] ?? ''), (string)($request->input()['reason'] ?? ''), $user, $auth, $request->remoteAddress())]);
    }

    public function publicType(Request $request, array $params): Response
    {
        try {
            $repo = new AppointmentRepository(Database::connection($this->config));
            return Response::json(['ok' => true, 'csrf_token' => Session::csrfToken()] + $repo->publicType((string)$params['token']));
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 404);
        } catch (Throwable $error) {
            error_log('Public appointment type failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Appointment booking is temporarily unavailable.'], 500);
        }
    }

    public function publicSlots(Request $request, array $params): Response
    {
        try {
            $query = $request->query();
            $repo = new AppointmentRepository(Database::connection($this->config));
            return Response::json(['ok' => true] + $repo->publicSlots((string)$params['token'], (string)($query['date'] ?? ''), (int)($query['guest_count'] ?? 1)));
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Public appointment slots failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Available times could not be loaded.'], 500);
        }
    }

    public function createPublicBooking(Request $request, array $params): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return Response::json(['ok' => false, 'error' => 'Reload the booking page and try again.'], 419);
        try {
            $repo = new AppointmentRepository(Database::connection($this->config));
            return Response::json(['ok' => true, 'booking' => $repo->createPublicBooking((string)$params['token'], $request->input(), $request->remoteAddress())], 201);
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log('Public appointment booking database failure: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'The appointment could not be booked. Please try another time.'], 409);
        } catch (Throwable $error) {
            error_log('Public appointment booking failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'The appointment could not be booked.'], 500);
        }
    }

    public function publicManageBooking(Request $request, array $params): Response
    {
        try {
            $repo = new AppointmentRepository(Database::connection($this->config));
            return Response::json(['ok' => true, 'csrf_token' => Session::csrfToken(), 'booking' => $repo->publicManageBooking((string)$params['bookingToken'])]);
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 404);
        } catch (Throwable $error) {
            error_log('Public appointment management details failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Appointment details are temporarily unavailable.'], 500);
        }
    }

    public function publicRescheduleSlots(Request $request, array $params): Response
    {
        try {
            $query = $request->query();
            $repo = new AppointmentRepository(Database::connection($this->config));
            return Response::json(['ok' => true] + $repo->publicRescheduleSlots((string)$params['bookingToken'], (string)($query['date'] ?? '')));
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Public appointment reschedule slots failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Available times could not be loaded.'], 500);
        }
    }

    public function reschedulePublicBooking(Request $request, array $params): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return Response::json(['ok' => false, 'error' => 'Reload the appointment page and try again.'], 419);
        try {
            $repo = new AppointmentRepository(Database::connection($this->config));
            return Response::json(['ok' => true, 'booking' => $repo->reschedulePublicBooking((string)$params['bookingToken'], $request->input(), $request->remoteAddress())]);
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log('Public appointment reschedule database failure: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'The appointment could not be rescheduled. Please choose another time.'], 409);
        } catch (Throwable $error) {
            error_log('Public appointment reschedule failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'The appointment could not be rescheduled.'], 500);
        }
    }

    public function cancelPublicBooking(Request $request, array $params): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return Response::json(['ok' => false, 'error' => 'Reload the booking page and try again.'], 419);
        try {
            $repo = new AppointmentRepository(Database::connection($this->config));
            $repo->cancelPublicBooking((string)$params['bookingToken'], (string)($request->input()['reason'] ?? ''), $request->remoteAddress());
            return Response::json(['ok' => true, 'message' => 'The appointment has been cancelled.']);
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Public appointment cancellation failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'The appointment could not be cancelled.'], 500);
        }
    }

    public function publicCancellation(Request $request, array $params): Response
    {
        try {
            $repo = new AppointmentRepository(Database::connection($this->config));
            return Response::json(['ok' => true, 'csrf_token' => Session::csrfToken(), 'booking' => $repo->publicCancellation((string)$params['bookingToken'])]);
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 404);
        } catch (Throwable $error) {
            error_log('Public appointment cancellation details failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Appointment details are temporarily unavailable.'], 500);
        }
    }

    private function handle(Request $request, string $permission, bool $mutation, callable $callback, int $successStatus = 200): Response
    {
        if ($mutation && !Session::verifyCsrf($request->header('x-csrf-token'))) return Response::json(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419);
        try {
            $pdo = Database::connection($this->config);
            $user = (new AuthService($pdo))->currentUser();
            if (!$user) throw new AuthenticationRequiredException('Sign in to use Appointments.');
            $auth = new Authorization($pdo, $user);
            if (!$auth->allows($permission)) throw new AccessDeniedException('You do not have permission to use Appointments.');
            return Response::json($callback(new AppointmentRepository($pdo), $user, $auth), $successStatus);
        } catch (AccessDeniedException $error) { return Response::json(['ok' => false, 'error' => $error->getMessage()], 403); }
        catch (AuthenticationRequiredException $error) { return Response::json(['ok' => false, 'error' => $error->getMessage()], 401); }
        catch (InvalidArgumentException $error) { return Response::json(['ok' => false, 'error' => $error->getMessage()], 422); }
        catch (PDOException $error) { error_log('Appointments database request failed: ' . $error->getMessage()); return Response::json(['ok' => false, 'error' => 'Appointments could not complete the request. Check that migration 026 has been applied.'], 500); }
        catch (Throwable $error) { error_log('Appointments request failed: ' . $error->getMessage()); return Response::json(['ok' => false, 'error' => 'Appointments could not complete this request.'], 500); }
    }

    private function id(array $params): int
    {
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new InvalidArgumentException('Appointment ID must be a positive integer.');
        return (int)$id;
    }
}
