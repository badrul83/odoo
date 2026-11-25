<?php
declare(strict_types=1);

namespace Erp\Modules\Calendar;

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

final class CalendarController
{
    public function __construct(private readonly Config $config) {}

    public function options(Request $request, array $params): Response
    {
        return $this->handle($request, false, fn($repo,$user,$auth) => ['ok'=>true,'options'=>$repo->options($request->query()['company_id']??null,$user,$auth)]);
    }

    public function events(Request $request, array $params): Response
    {
        return $this->handle($request, false, fn($repo,$user,$auth) => ['ok'=>true] + $repo->events($user,$auth,$request->query()));
    }

    public function event(Request $request, array $params): Response
    {
        return $this->handle($request, false, fn($repo,$user,$auth) => ['ok'=>true,'event'=>$repo->event($this->id($params),$auth)]);
    }

    public function create(Request $request, array $params): Response
    {
        return $this->handle($request, true, fn($repo,$user,$auth) => ['ok'=>true,'event'=>$repo->save($request->input(),null,$user,$auth,$request->remoteAddress())], 201);
    }

    public function update(Request $request, array $params): Response
    {
        return $this->handle($request, true, fn($repo,$user,$auth) => ['ok'=>true,'event'=>$repo->save($request->input(),$this->id($params),$user,$auth,$request->remoteAddress())]);
    }

    public function updateOccurrence(Request $request, array $params): Response
    {
        return $this->handle($request, true, function($repo,$user,$auth) use($request,$params) {
            $input = $request->input();
            $repo->saveOccurrence($input,$this->id($params),$user,$auth,$request->remoteAddress());
            return ['ok'=>true];
        });
    }

    public function setOccurrenceStatus(Request $request, array $params): Response
    {
        return $this->handle($request, true, function($repo,$user,$auth) use($request,$params) {
            $input = $request->input();
            $repo->setOccurrenceStatus($this->id($params),(string)($input['occurrence_start_utc']??''),(string)($input['status']??''),$user,$auth,$request->remoteAddress());
            return ['ok'=>true];
        });
    }

    public function clearOccurrenceException(Request $request, array $params): Response
    {
        return $this->handle($request, true, function($repo,$user,$auth) use($request,$params) {
            $input = $request->input();
            $repo->clearOccurrenceException($this->id($params),(string)($input['occurrence_start_utc']??''),$user,$auth,$request->remoteAddress());
            return ['ok'=>true];
        });
    }

    public function setStatus(Request $request, array $params): Response
    {
        return $this->handle($request, true, function($repo,$user,$auth) use($request,$params) {
            $repo->cancel($this->id($params),(string)($request->input()['status']??''),$user,$auth,$request->remoteAddress());
            return ['ok'=>true];
        });
    }

    public function respond(Request $request, array $params): Response
    {
        return $this->handle($request, true, function($repo,$user,$auth) use($request,$params) {
            $input = $request->input();
            $response = $repo->respond($this->id($params),(string)($input['response_status']??''),$user,$auth,$request->remoteAddress(),isset($input['occurrence_start_utc'])?(string)$input['occurrence_start_utc']:null);
            return ['ok'=>true,'response_status'=>$response];
        });
    }

    private function handle(Request $request, bool $mutation, callable $callback, int $successStatus=200): Response
    {
        if($mutation&&!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        try {
            $pdo=Database::connection($this->config); $user=(new AuthService($pdo))->currentUser();
            if(!$user)throw new AuthenticationRequiredException('Sign in to use Calendar.');
            $auth=new Authorization($pdo,$user);
            return Response::json($callback(new CalendarRepository($pdo),$user,$auth),$successStatus);
        } catch(AccessDeniedException $error) { return Response::json(['ok'=>false,'error'=>$error->getMessage()],403); }
        catch(AuthenticationRequiredException $error) { return Response::json(['ok'=>false,'error'=>$error->getMessage()],401); }
        catch(InvalidArgumentException $error) { return Response::json(['ok'=>false,'error'=>$error->getMessage()],422); }
        catch(PDOException $error) { error_log('Calendar database request failed: '.$error->getMessage()); return Response::json(['ok'=>false,'error'=>'Calendar could not complete this request. Check that the latest Calendar migrations have been applied.'],500); }
        catch(Throwable $error) { error_log('Calendar request failed: '.$error->getMessage()); return Response::json(['ok'=>false,'error'=>'Calendar could not complete this request.'],500); }
    }

    private function id(array $params): int
    {
        $id=filter_var($params['id']??null,FILTER_VALIDATE_INT); if($id===false||$id<1)throw new InvalidArgumentException('Calendar event ID must be a positive integer.'); return (int)$id;
    }
}
