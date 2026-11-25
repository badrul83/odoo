<?php
declare(strict_types=1);

namespace Erp\Modules\Invoicing;

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
use PDO;
use PDOException;
use Throwable;

final class InvoicingController
{
    public function __construct(private readonly Config $config) {}

    public function options(Request $request,array $params):Response
    {
        return $this->run($request,'invoicing.read','Invoicing setup could not be loaded.',function(UserContext $user,Authorization $authorization,InvoicingRepository $repo)use($request):Response{
            return Response::json(['ok'=>true,'options'=>$repo->options($user,$authorization,$request->query()['company_id']??null)]);
        });
    }
    public function index(Request $request,array $params):Response
    {
        return $this->run($request,'invoicing.read','Invoices could not be loaded.',function(UserContext $user,Authorization $authorization,InvoicingRepository $repo)use($request):Response{
            return Response::json(['ok'=>true]+$repo->search($user,$authorization,$request->query()));
        });
    }
    public function show(Request $request,array $params):Response
    {
        return $this->run($request,'invoicing.read','Invoice could not be loaded.',function(UserContext $user,Authorization $authorization,InvoicingRepository $repo)use($params):Response{
            $invoice=$repo->find($this->routeId($params),$user,$authorization);
            return $invoice?Response::json(['ok'=>true,'invoice'=>$invoice]):Response::json(['ok'=>false,'error'=>'Customer invoice not found.'],404);
        });
    }
    public function createFromSales(Request $request,array $params):Response
    {
        return $this->mutate($request,['invoicing.write','sales.read'],'Invoice could not be created from the Sales Order.',function(UserContext $user,Authorization $authorization,InvoicingRepository $repo)use($params,$request):Response{
            $invoice=$repo->createFromSalesOrder($this->routeId($params),$user,$authorization,$user->id,$request->remoteAddress());
            return Response::json(['ok'=>true,'invoice'=>$invoice],201);
        });
    }
    public function create(Request $request,array $params):Response
    {
        return $this->mutate($request,['invoicing.write','contacts.read'],'Direct invoice could not be created.',function(UserContext $user,Authorization $authorization,InvoicingRepository $repo)use($request):Response{
            $invoice=$repo->createDirectInvoice($request->input(),$user,$authorization,$user->id,$request->remoteAddress());
            return Response::json(['ok'=>true,'invoice'=>$invoice],201);
        });
    }
    public function post(Request $request,array $params):Response
    {
        return $this->mutate($request,['invoicing.post','accounting.post'],'Invoice could not be posted.',function(UserContext $user,Authorization $authorization,InvoicingRepository $repo)use($params,$request):Response{
            return Response::json(['ok'=>true,'invoice'=>$repo->post($this->routeId($params),$user,$authorization,$user->id,$request->remoteAddress())]);
        });
    }
    public function createCreditNote(Request $request,array $params):Response
    {
        return $this->mutate($request,['invoicing.write'],'Customer credit note could not be created.',function(UserContext $user,Authorization $authorization,InvoicingRepository $repo)use($params,$request):Response{
            return Response::json(['ok'=>true,'invoice'=>$repo->createCreditNote($this->routeId($params),$request->input(),$user,$authorization,$user->id,$request->remoteAddress())],201);
        });
    }
    public function cancelCreditNote(Request $request,array $params):Response
    {
        return $this->mutate($request,['invoicing.write'],'Draft customer credit note could not be discarded.',function(UserContext $user,Authorization $authorization,InvoicingRepository $repo)use($params,$request):Response{
            return Response::json(['ok'=>true,'invoice'=>$repo->cancelCreditNote($this->routeId($params),$user,$authorization,$user->id,$request->remoteAddress())]);
        });
    }
    public function registerCreditNoteRefund(Request $request,array $params):Response
    {
        return $this->mutate($request,['invoicing.write','accounting.post'],'Customer credit note refund could not be registered.',function(UserContext $user,Authorization $authorization,InvoicingRepository $repo)use($params,$request):Response{
            return Response::json(['ok'=>true,'invoice'=>$repo->registerCreditNoteRefund($this->routeId($params),$request->input(),$user,$authorization,$user->id,$request->remoteAddress())],201);
        });
    }
    public function applyCreditNote(Request $request,array $params):Response
    {
        return $this->mutate($request,['invoicing.write','accounting.reconcile','accounting.post'],'Customer credit could not be applied.',function(UserContext $user,Authorization $authorization,InvoicingRepository $repo)use($params,$request):Response{
            return Response::json(['ok'=>true,'invoice'=>$repo->applyCreditNote($this->routeId($params,'creditNoteId'),$request->input(),$user,$authorization,$user->id,$request->remoteAddress())]);
        });
    }
    public function reconcileCreditNoteRefund(Request $request,array $params):Response
    {
        return $this->mutate($request,['accounting.reconcile','accounting.post'],'Customer credit note refund could not be reconciled.',function(UserContext $user,Authorization $authorization,InvoicingRepository $repo)use($params,$request):Response{
            return Response::json(['ok'=>true,'invoice'=>$repo->reconcileCreditNoteRefund($this->routeId($params),$request->input(),$user,$authorization,$user->id,$request->remoteAddress())]);
        });
    }
    public function registerPayment(Request $request,array $params):Response
    {
        return $this->mutate($request,['invoicing.write','accounting.post'],'Customer payment could not be registered.',function(UserContext $user,Authorization $authorization,InvoicingRepository $repo)use($params,$request):Response{
            return Response::json(['ok'=>true,'invoice'=>$repo->registerPayment($this->routeId($params),$request->input(),$user,$authorization,$user->id,$request->remoteAddress())],201);
        });
    }
    public function reconcilePayment(Request $request,array $params):Response
    {
        return $this->mutate($request,['accounting.reconcile','accounting.post'],'Customer payment could not be reconciled.',function(UserContext $user,Authorization $authorization,InvoicingRepository $repo)use($params,$request):Response{
            return Response::json(['ok'=>true,'invoice'=>$repo->reconcilePayment($this->routeId($params),$request->input(),$user,$authorization,$user->id,$request->remoteAddress())]);
        });
    }
    public function createAccount(Request $request,array $params):Response
    {
        return $this->mutate($request,['accounting.write'],'Account could not be created.',function(UserContext $user,Authorization $authorization,InvoicingRepository $repo)use($request):Response{
            return Response::json(['ok'=>true,'account'=>$repo->createAccount($request->input(),$user,$authorization,$user->id,$request->remoteAddress())],201);
        });
    }
    public function createJournal(Request $request,array $params):Response
    {
        return $this->mutate($request,['accounting.write'],'Sales Journal could not be created.',function(UserContext $user,Authorization $authorization,InvoicingRepository $repo)use($request):Response{
            return Response::json(['ok'=>true,'journal'=>$repo->createJournal($request->input(),$user,$authorization,$user->id,$request->remoteAddress())],201);
        });
    }

    private function run(Request $request,string $permission,string $failure,callable $action):Response{return $this->handle($request,[$permission],$failure,$action,false);}
    private function mutate(Request $request,array $permissions,string $failure,callable $action):Response{return $this->handle($request,$permissions,$failure,$action,true);}
    private function handle(Request $request,array $permissions,string $failure,callable $action,bool $csrf):Response
    {
        if($csrf&&!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        try{
            $pdo=Database::connection($this->config);$user=(new AuthService($pdo))->currentUser();if(!$user)throw new AuthenticationRequiredException('Sign in to use Invoicing.');
            $authorization=new Authorization($pdo,$user);
            foreach($permissions as $permission)if(!$authorization->allows($permission))throw new AccessDeniedException('You do not have permission to perform this invoicing action.');
            return $action($user,$authorization,new InvoicingRepository($pdo));
        }catch(AccessDeniedException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],403);}
        catch(AuthenticationRequiredException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],401);}
        catch(InvalidArgumentException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(PDOException $error){error_log('Invoicing database operation failed: '.$error->getMessage());$state=(string)($error->errorInfo[0]??$error->getCode());return str_starts_with($state,'23')?Response::json(['ok'=>false,'error'=>'A duplicate or linked-record conflict prevented this invoicing action.'],409):Response::json(['ok'=>false,'error'=>$failure],500);}
        catch(Throwable $error){error_log('Invoicing request failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>$failure],500);}
    }
    private function routeId(array $params,string $key='id'):int{$id=filter_var($params[$key]??null,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException('Record ID must be a positive integer.');return $id;}
}
