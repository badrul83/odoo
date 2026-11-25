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

final class ManualJournalEntryController
{
    public function __construct(private readonly Config $config) {}

    public function options(Request $request,array $params):Response
    {
        return $this->run('Journal entry options could not be loaded.',function(UserContext $user,Authorization $auth,ManualJournalEntryRepository $repo)use($request):Response{return Response::json(['ok'=>true,'options'=>$repo->options($request->query()['company_id']??$user->defaultCompanyId,$user,$auth)]);});
    }

    public function index(Request $request,array $params):Response
    {
        return $this->run('Journal entries could not be loaded.',function(UserContext $user,Authorization $auth,ManualJournalEntryRepository $repo)use($request):Response{return Response::json(['ok'=>true]+$repo->search($request->query(),$user,$auth));});
    }

    public function show(Request $request,array $params):Response
    {
        return $this->run('Journal entry could not be loaded.',function(UserContext $user,Authorization $auth,ManualJournalEntryRepository $repo)use($params):Response{$entry=$repo->find($this->routeId($params),$user,$auth);return$entry?Response::json(['ok'=>true,'entry'=>$entry]):Response::json(['ok'=>false,'error'=>'Journal entry not found.'],404);});
    }

    public function create(Request $request,array $params):Response
    {
        return $this->mutate($request,'Journal entry could not be created.',function(UserContext $user,Authorization $auth,ManualJournalEntryRepository $repo)use($request):Response{return Response::json(['ok'=>true,'entry'=>$repo->create($request->input(),$user,$auth,$user->id,$request->remoteAddress())],201);});
    }

    public function update(Request $request,array $params):Response
    {
        return $this->mutate($request,'Draft journal entry could not be updated.',function(UserContext $user,Authorization $auth,ManualJournalEntryRepository $repo)use($request,$params):Response{return Response::json(['ok'=>true,'entry'=>$repo->update($this->routeId($params),$request->input(),$user,$auth,$user->id,$request->remoteAddress())]);});
    }

    public function post(Request $request,array $params):Response
    {
        return $this->mutate($request,'Journal entry could not be posted.',function(UserContext $user,Authorization $auth,ManualJournalEntryRepository $repo)use($request,$params):Response{return Response::json(['ok'=>true,'entry'=>$repo->post($this->routeId($params),$user,$auth,$user->id,$request->remoteAddress())]);});
    }

    public function reverse(Request $request,array $params):Response
    {
        return $this->mutate($request,'Journal entry reversal could not be created.',function(UserContext $user,Authorization $auth,ManualJournalEntryRepository $repo)use($request,$params):Response{return Response::json(['ok'=>true,'entry'=>$repo->reverse($this->routeId($params),$request->input(),$user,$auth,$user->id,$request->remoteAddress())],201);});
    }

    public function discard(Request $request,array $params):Response
    {
        return $this->mutate($request,'Draft journal entry could not be discarded.',function(UserContext $user,Authorization $auth,ManualJournalEntryRepository $repo)use($request,$params):Response{$repo->discard($this->routeId($params),$user,$auth,$user->id,$request->remoteAddress());return Response::json(['ok'=>true]);});
    }

    private function run(string $failure,callable $action):Response{return$this->handle(null,$failure,$action,false);}
    private function mutate(Request $request,string $failure,callable $action):Response{return$this->handle($request,$failure,$action,true);}
    private function handle(?Request $request,string $failure,callable $action,bool $csrf):Response
    {
        if($csrf&&$request!==null&&!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        try{$pdo=Database::connection($this->config);$user=(new AuthService($pdo))->currentUser();if(!$user)throw new AuthenticationRequiredException('Sign in to use Accounting.');$auth=new Authorization($pdo,$user);return$action($user,$auth,new ManualJournalEntryRepository($pdo));}
        catch(AccessDeniedException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],403);}
        catch(AuthenticationRequiredException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],401);}
        catch(InvalidArgumentException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(PDOException $error){$state=(string)($error->errorInfo[0]??$error->getCode());if(str_starts_with($state,'23'))return Response::json(['ok'=>false,'error'=>'A duplicate or linked-record conflict prevented this Accounting action.'],409);error_log('Manual journal entry database error: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>$failure],500);}
        catch(Throwable $error){error_log('Manual journal entry request failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>$failure],500);}
    }
    private function routeId(array $params):int{$id=filter_var($params['id']??null,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException('Journal entry ID must be a positive integer.');return$id;}
}
