<?php
declare(strict_types=1);

namespace Erp\Modules\Sales;

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

final class SalesController
{
    public function __construct(private readonly Config $config) {}

    public function options(Request $request,array $params): Response
    {
        return $this->run('sales.read','Sales options could not be loaded.',function(UserContext $user,Authorization $authorization,SalesRepository $repo)use($request):Response{
            return Response::json(['ok'=>true,'options'=>$repo->options($user,$authorization,$request->query()['company_id']??null)]);
        });
    }
    public function index(Request $request,array $params): Response
    {
        return $this->run('sales.read','Sales quotations could not be loaded.',function(UserContext $user,Authorization $authorization,SalesRepository $repo)use($request):Response{
            return Response::json(['ok'=>true]+$repo->search($user,$authorization,$request->query()));
        });
    }
    public function show(Request $request,array $params): Response
    {
        return $this->run('sales.read','Sales order could not be loaded.',function(UserContext $user,Authorization $authorization,SalesRepository $repo)use($params):Response{
            $order=$repo->find($this->routeId($params),$user,$authorization);
            return $order?Response::json(['ok'=>true,'order'=>$order]):Response::json(['ok'=>false,'error'=>'Sales order not found.'],404);
        });
    }
    public function create(Request $request,array $params): Response { return $this->save($request,null); }
    public function update(Request $request,array $params): Response { return $this->save($request,$this->routeId($params)); }
    public function markSent(Request $request,array $params): Response
    {
        return $this->mutate($request,'sales.write','Quotation could not be marked as sent.',function(UserContext $user,Authorization $authorization,SalesRepository $repo)use($params,$request):Response{
            return Response::json(['ok'=>true,'order'=>$repo->markSent($this->routeId($params),$user,$authorization,$user->id,$request->remoteAddress())]);
        });
    }
    public function confirm(Request $request,array $params): Response
    {
        return $this->mutate($request,'sales.confirm','Sales order could not be confirmed.',function(UserContext $user,Authorization $authorization,SalesRepository $repo)use($request,$params):Response{
            return Response::json(['ok'=>true,'order'=>$repo->confirm($this->routeId($params),$user,$authorization,$user->id,$request->remoteAddress())]);
        });
    }
    public function cancel(Request $request,array $params): Response
    {
        return $this->mutate($request,'sales.confirm','Quotation could not be cancelled.',function(UserContext $user,Authorization $authorization,SalesRepository $repo)use($request,$params):Response{
            return Response::json(['ok'=>true,'order'=>$repo->cancel($this->routeId($params),$user,$authorization,$user->id,$request->remoteAddress())]);
        });
    }
    public function createTax(Request $request,array $params): Response
    {
        return $this->mutate($request,'accounting.write','Tax could not be created.',function(UserContext $user,Authorization $authorization,SalesRepository $repo)use($request):Response{
            $tax=$repo->createTax($request->input(),$user,$authorization,$user->id,$request->remoteAddress());
            return Response::json(['ok'=>true,'tax'=>$tax],201);
        });
    }

    private function save(Request $request,?int $id): Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return $this->csrfExpired();
        try{
            [$user,$authorization,$repo]=$this->context();$this->requirePermission($authorization,'sales.write');
            $order=$repo->saveDraft($id,$request->input(),$user,$authorization,$user->id,$request->remoteAddress());
            return Response::json(['ok'=>true,'order'=>$order],$id===null?201:200);
        }catch(AccessDeniedException $e){return $this->forbidden($e->getMessage());}
        catch(AuthenticationRequiredException $e){return $this->unauthorized($e->getMessage());}
        catch(InvalidArgumentException $e){return Response::json(['ok'=>false,'error'=>$e->getMessage()],422);}
        catch(PDOException $e){return $this->databaseFailure($e,'Sales quotation could not be saved.');}
        catch(Throwable $e){error_log('Sales save failed: '.$e->getMessage());return Response::json(['ok'=>false,'error'=>'Sales quotation could not be saved.'],500);}
    }
    private function mutate(Request $request,string $permission,string $failure,callable $action):Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return $this->csrfExpired();
        return $this->run($permission,$failure,$action);
    }
    private function run(string $permission,string $failure,callable $action):Response
    {
        try{[$user,$authorization,$repo]=$this->context();$this->requirePermission($authorization,$permission);return $action($user,$authorization,$repo);}
        catch(AccessDeniedException $e){return $this->forbidden($e->getMessage());}
        catch(AuthenticationRequiredException $e){return $this->unauthorized($e->getMessage());}
        catch(InvalidArgumentException $e){return Response::json(['ok'=>false,'error'=>$e->getMessage()],422);}
        catch(PDOException $e){return $this->databaseFailure($e,$failure);}
        catch(Throwable $e){error_log('Sales request failed: '.$e->getMessage());return Response::json(['ok'=>false,'error'=>$failure],500);}
    }
    private function context():array
    {
        $pdo=Database::connection($this->config);$user=(new AuthService($pdo))->currentUser();
        if(!$user)throw new AuthenticationRequiredException('Sign in to use Sales.');
        return[$user,new Authorization($pdo,$user),new SalesRepository($pdo)];
    }
    private function requirePermission(Authorization $authorization,string $permission):void
    {
        if(!$authorization->allows($permission))throw new AccessDeniedException('You do not have permission to perform this Sales action.');
    }
    private function routeId(array $params):int
    {
        $id=filter_var($params['id']??null,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException('Sales order ID must be a positive integer.');return $id;
    }
    private function databaseFailure(PDOException $error,string $message):Response
    {
        error_log('Sales database operation failed: '.$error->getMessage());$state=(string)($error->errorInfo[0]??$error->getCode());
        if(str_starts_with($state,'23'))return Response::json(['ok'=>false,'error'=>'A duplicate or linked-record conflict prevented this Sales action.'],409);
        return Response::json(['ok'=>false,'error'=>$message],500);
    }
    private function csrfExpired():Response{return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);}
    private function forbidden(string $message):Response{return Response::json(['ok'=>false,'error'=>$message],403);}
    private function unauthorized(string $message):Response{return Response::json(['ok'=>false,'error'=>$message],401);}
}
