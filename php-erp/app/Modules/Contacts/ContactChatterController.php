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
use Erp\Core\Security\Session;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDOException;
use Throwable;

final class ContactChatterController
{
    public function __construct(private readonly Config $config) {}

    public function timeline(Request $request,array $params):Response
    {
        return $this->run('Contact chatter could not be loaded.',function(UserContext $user,Authorization $auth,ContactChatterRepository $repo)use($request,$params):Response{
            $id=$this->routeId($params['id']??null,'Contact');
            return Response::json(['ok'=>true]+$repo->timeline($id,$request->query(),$user,$auth));
        });
    }

    public function addMessage(Request $request,array $params):Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        return $this->run('Contact message could not be saved.',function(UserContext $user,Authorization $auth,ContactChatterRepository $repo)use($request,$params):Response{
            $id=$this->routeId($params['id']??null,'Contact');
            return Response::json(['ok'=>true,'message'=>$repo->addMessage($id,$request->input(),$user,$auth,$request->remoteAddress())],201);
        });
    }

    public function addActivity(Request $request,array $params):Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        return $this->run('Contact activity could not be saved.',function(UserContext $user,Authorization $auth,ContactChatterRepository $repo)use($request,$params):Response{
            $id=$this->routeId($params['id']??null,'Contact');
            return Response::json(['ok'=>true,'activity'=>$repo->addActivity($id,$request->input(),$user,$auth,$request->remoteAddress())],201);
        });
    }

    public function completeActivity(Request $request,array $params):Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        return $this->run('Contact activity could not be updated.',function(UserContext $user,Authorization $auth,ContactChatterRepository $repo)use($request,$params):Response{
            $contactId=$this->routeId($params['id']??null,'Contact');$activityId=$this->routeId($params['activityId']??null,'Activity');
            return Response::json(['ok'=>true,'activity'=>$repo->completeActivity($contactId,$activityId,$request->input(),$user,$auth,$request->remoteAddress())]);
        });
    }

    private function run(string $failure,callable $action):Response
    {
        try{$pdo=Database::connection($this->config);$user=(new AuthService($pdo))->currentUser();if(!$user)throw new AuthenticationRequiredException('Sign in to use Contact chatter.');$auth=new Authorization($pdo,$user);return$action($user,$auth,new ContactChatterRepository($pdo));}
        catch(AccessDeniedException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],403);}
        catch(AuthenticationRequiredException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],401);}
        catch(InvalidArgumentException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(PDOException $error){$state=(string)($error->errorInfo[0]??$error->getCode());if(str_starts_with($state,'23'))return Response::json(['ok'=>false,'error'=>'A linked-record conflict prevented this Contact chatter action.'],409);error_log('Contact chatter database error: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>$failure],500);}
        catch(Throwable $error){error_log('Contact chatter error: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>$failure],500);}
    }

    private function routeId(mixed $value,string $label):int{$id=filter_var($value,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException($label.' ID must be a positive integer.');return$id;}
}
