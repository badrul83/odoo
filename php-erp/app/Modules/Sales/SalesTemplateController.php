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

final class SalesTemplateController
{
    public function __construct(private readonly Config $config) {}

    public function index(Request $request,array $params): Response
    {
        return $this->run('Quotation templates could not be loaded.',function(UserContext $user,Authorization $auth,SalesTemplateRepository $repo)use($request):Response{
            $companyId=$this->positiveId($request->query()['company_id']??null,'Company');
            return Response::json(['ok'=>true,'templates'=>$repo->list($companyId,$user,$auth)]);
        });
    }

    public function show(Request $request,array $params): Response
    {
        return $this->run('Quotation template could not be loaded.',function(UserContext $user,Authorization $auth,SalesTemplateRepository $repo)use($request,$params):Response{
            $companyId=$this->positiveId($request->query()['company_id']??null,'Company');
            $template=$repo->find($this->routeId($params),$companyId,$auth);
            return $template?Response::json(['ok'=>true,'template'=>$template]):Response::json(['ok'=>false,'error'=>'Quotation template not found.'],404);
        });
    }

    public function create(Request $request,array $params): Response { return $this->save($request,null); }
    public function update(Request $request,array $params): Response { return $this->save($request,$this->routeId($params)); }

    public function archive(Request $request,array $params): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        return $this->run('Quotation template could not be archived.',function(UserContext $user,Authorization $auth,SalesTemplateRepository $repo)use($request,$params):Response{
            $companyId=$this->positiveId($request->input()['company_id']??null,'Company');
            $repo->archive($this->routeId($params),$companyId,$user,$auth,$request->remoteAddress());
            return Response::json(['ok'=>true]);
        });
    }

    private function save(Request $request,?int $id): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        if ($request->hasInvalidJson()) return Response::json(['ok'=>false,'error'=>'The request body must be valid JSON.'],400);
        return $this->run('Quotation template could not be saved.',function(UserContext $user,Authorization $auth,SalesTemplateRepository $repo)use($request,$id):Response{
            $template=$repo->save($id,$request->input(),$user,$auth,$request->remoteAddress());
            return Response::json(['ok'=>true,'template'=>$template],$id===null?201:200);
        });
    }

    private function run(string $failure,callable $action): Response
    {
        try {
            $pdo=Database::connection($this->config); $user=(new AuthService($pdo))->currentUser();
            if (!$user) throw new AuthenticationRequiredException('Sign in to manage Sales quotation templates.');
            $auth=new Authorization($pdo,$user);
            if (!$auth->allows('sales.write')) throw new AccessDeniedException('You do not have permission to manage Sales quotation templates.');
            return $action($user,$auth,new SalesTemplateRepository($pdo));
        } catch (AccessDeniedException $error) { return Response::json(['ok'=>false,'error'=>$error->getMessage()],403); }
        catch (AuthenticationRequiredException $error) { return Response::json(['ok'=>false,'error'=>$error->getMessage()],401); }
        catch (InvalidArgumentException $error) { return Response::json(['ok'=>false,'error'=>$error->getMessage()],422); }
        catch (PDOException $error) { error_log($failure.' '.$error->getMessage()); return Response::json(['ok'=>false,'error'=>$failure],503); }
        catch (Throwable $error) { error_log($failure.' '.$error->getMessage()); return Response::json(['ok'=>false,'error'=>$failure],500); }
    }

    private function routeId(array $params): int { return $this->positiveId($params['id']??null,'Quotation Template ID'); }
    private function positiveId(mixed $value,string $label): int { $id=filter_var($value,FILTER_VALIDATE_INT); if ($id===false||$id<1) throw new InvalidArgumentException($label.' must be a positive integer.'); return $id; }
}
