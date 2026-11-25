<?php
declare(strict_types=1);

namespace Erp\Modules\Contracts;

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

final class ContractController
{
    public function __construct(private readonly Config $config) {}

    public function options(Request $request,array $params):Response
    {
        return $this->run('Contract options could not be loaded.',function(UserContext $user,Authorization $auth,ContractRepository $repo)use($request):Response{
            return Response::json(['ok'=>true,'options'=>$repo->options($user,$auth,$request->query()['company_id']??null)]);
        });
    }

    public function index(Request $request,array $params):Response
    {
        return $this->run('Contracts could not be loaded.',function(UserContext $user,Authorization $auth,ContractRepository $repo)use($request):Response{
            return Response::json(['ok'=>true]+$repo->search($user,$auth,$request->query()));
        });
    }

    public function show(Request $request,array $params):Response
    {
        return $this->run('Contract could not be loaded.',function(UserContext $user,Authorization $auth,ContractRepository $repo)use($params):Response{
            $contract=$repo->find($this->routeId($params),$user,$auth);
            return $contract?Response::json(['ok'=>true,'contract'=>$contract]):Response::json(['ok'=>false,'error'=>'Contract not found.'],404);
        });
    }

    public function create(Request $request,array $params):Response{return$this->save($request,null);}
    public function update(Request $request,array $params):Response
    {
        try{return$this->save($request,$this->routeId($params));}
        catch(InvalidArgumentException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
    }

    private function save(Request $request,?int $id):Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        if($request->hasInvalidJson())return Response::json(['ok'=>false,'error'=>'The request body must be valid JSON.'],400);
        return $this->run('Contract could not be saved.',function(UserContext $user,Authorization $auth,ContractRepository $repo)use($request,$id):Response{
            $contract=$repo->save($id,$request->input(),$user,$auth,$user->id,$request->remoteAddress());
            return Response::json(['ok'=>true,'contract'=>$contract],$id===null?201:200);
        });
    }

    private function run(string $failure,callable $action):Response
    {
        try{
            $pdo=Database::connection($this->config);$user=(new AuthService($pdo))->currentUser();
            if(!$user)throw new AuthenticationRequiredException('Sign in to use Contracts.');
            $auth=new Authorization($pdo,$user);return$action($user,$auth,new ContractRepository($pdo));
        }catch(AccessDeniedException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],403);}
        catch(AuthenticationRequiredException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],401);}
        catch(InvalidArgumentException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(PDOException $error){error_log('Contract database operation failed: '.$error->getMessage());$state=(string)($error->errorInfo[0]??$error->getCode());return str_starts_with($state,'23')?Response::json(['ok'=>false,'error'=>'A duplicate or linked-record conflict prevented this Contract action.'],409):Response::json(['ok'=>false,'error'=>$failure],500);}
        catch(Throwable $error){error_log('Contract request failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>$failure],500);}
    }

    private function routeId(array $params):int{$id=filter_var($params['id']??null,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException('Contract ID must be a positive integer.');return$id;}
}
