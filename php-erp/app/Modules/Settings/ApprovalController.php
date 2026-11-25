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
use InvalidArgumentException;
use PDOException;
use Throwable;

final class ApprovalController
{
    public function __construct(private readonly Config $config) {}

    public function policies(Request $request, array $params): Response
    {
        return $this->run('settings.read','Approval policies could not be loaded.',function(UserContext $user,Authorization $auth,ApprovalRepository $repo)use($request):Response{
            $companyId=filter_var($request->query()['company_id']??null,FILTER_VALIDATE_INT);
            if($companyId===false||$companyId<1)throw new InvalidArgumentException('Select a company to load its approval policies.');
            return Response::json(['ok'=>true]+$repo->policies($user,$auth,(int)$companyId));
        });
    }

    public function savePolicy(Request $request, array $params): Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        return $this->run('settings.write','Approval policy could not be saved.',function(UserContext $user,Authorization $auth,ApprovalRepository $repo)use($request):Response{
            return Response::json(['ok'=>true]+$repo->savePolicy($request->input(),$user,$auth,$user->id,$request->remoteAddress()));
        });
    }

    public function inbox(Request $request,array $params):Response
    {
        return $this->run(null,'Approval requests could not be loaded.',function(UserContext $user,Authorization $auth,ApprovalRepository $repo):Response{
            if(!$auth->allows('approvals.read')&&!$auth->allows('approvals.write')&&!$auth->allows('sales.approve')&&!$auth->allows('purchase.approve'))throw new AccessDeniedException('You do not have approval inbox access.');
            return Response::json(['ok'=>true,'records'=>$repo->inbox($user,$auth)]);
        });
    }

    public function decide(Request $request,array $params):Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        return $this->run(null,'Approval decision could not be saved.',function(UserContext $user,Authorization $auth,ApprovalRepository $repo)use($request,$params):Response{
            $id=filter_var($params['id']??null,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException('Approval request ID must be positive.');
            $input=$request->input();$unknown=array_diff(array_keys($input),['decision','comment']);if($unknown)throw new InvalidArgumentException('Unsupported approval fields: '.implode(', ',$unknown));
            $decision=(string)($input['decision']??'');$comment=trim((string)($input['comment']??''));
            return Response::json(['ok'=>true,'request'=>$repo->decide((int)$id,$decision,$comment,$user,$auth,$request->remoteAddress())]);
        });
    }

    private function run(?string $permission,string $failure,callable $action):Response
    {
        try{$pdo=Database::connection($this->config);$user=(new AuthService($pdo))->currentUser();if(!$user)throw new AuthenticationRequiredException('Sign in to use Approvals.');$auth=new Authorization($pdo,$user);if($permission!==null&&!$auth->allows($permission))throw new AccessDeniedException('You do not have permission to manage approval policies.');return$action($user,$auth,new ApprovalRepository($pdo));}
        catch(AccessDeniedException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],403);}
        catch(AuthenticationRequiredException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],401);}
        catch(InvalidArgumentException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(PDOException $error){$state=(string)($error->errorInfo[0]??$error->getCode());if(str_starts_with($state,'23'))return Response::json(['ok'=>false,'error'=>'A linked-record conflict prevented this approval action.'],409);error_log('Approval database operation failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>$failure],500);}
        catch(Throwable $error){error_log('Approval operation failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>$failure],500);}
    }
}
