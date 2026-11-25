<?php
declare(strict_types=1);

namespace Erp\Modules\Purchase;

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

final class PurchaseController
{
    public function __construct(private readonly Config $config) {}

    public function options(Request $request,array $params):Response{return $this->run('purchase.read','Purchase options could not be loaded.',function(UserContext $user,Authorization $auth,PurchaseRepository $repo)use($request):Response{return Response::json(['ok'=>true,'options'=>$repo->options($user,$auth,$request->query()['company_id']??null)]);});}
    public function index(Request $request,array $params):Response{return $this->run('purchase.read','Purchase orders could not be loaded.',function(UserContext $user,Authorization $auth,PurchaseRepository $repo)use($request):Response{return Response::json(['ok'=>true]+$repo->search($user,$auth,$request->query()));});}
    public function show(Request $request,array $params):Response{return $this->run('purchase.read','Purchase Order could not be loaded.',function(UserContext $user,Authorization $auth,PurchaseRepository $repo)use($params):Response{$order=$repo->find($this->routeId($params,'id'),$user,$auth);return $order?Response::json(['ok'=>true,'order'=>$order]):Response::json(['ok'=>false,'error'=>'Purchase Order not found.'],404);});}
    public function create(Request $request,array $params):Response{return $this->save($request,null);}
    public function update(Request $request,array $params):Response{return $this->save($request,$this->routeId($params,'id'));}
    public function markSent(Request $request,array $params):Response{return $this->mutate($request,'purchase.write','RFQ could not be marked as sent.',function(UserContext $user,Authorization $auth,PurchaseRepository $repo)use($request,$params):Response{return Response::json(['ok'=>true,'order'=>$repo->markSent($this->routeId($params,'id'),$user,$auth,$user->id,$request->remoteAddress())]);});}
    public function confirm(Request $request,array $params):Response{return $this->mutate($request,'purchase.confirm','Purchase Order could not be confirmed.',function(UserContext $user,Authorization $auth,PurchaseRepository $repo)use($request,$params):Response{$this->requirePermission($auth,'inventory.write');return Response::json(['ok'=>true,'order'=>$repo->confirm($this->routeId($params,'id'),$user,$auth,$user->id,$request->remoteAddress())]);});}
    public function createBill(Request $request,array $params):Response{return $this->mutate($request,'invoicing.write','Vendor bill could not be created.',function(UserContext $user,Authorization $auth,PurchaseRepository $repo)use($request,$params):Response{$this->requirePermission($auth,'purchase.read');$bill=$repo->createBillFromOrder($this->routeId($params,'id'),$user,$auth,$user->id,$request->remoteAddress());return Response::json(['ok'=>true,'bill'=>$bill],201);});}
    public function bills(Request $request,array $params):Response{return $this->run('invoicing.read','Vendor bills could not be loaded.',function(UserContext $user,Authorization $auth,PurchaseRepository $repo)use($request):Response{return Response::json(['ok'=>true]+$repo->searchBills($user,$auth,$request->query()));});}
    public function showBill(Request $request,array $params):Response{return $this->run('invoicing.read','Vendor bill could not be loaded.',function(UserContext $user,Authorization $auth,PurchaseRepository $repo)use($params):Response{$bill=$repo->findBill($this->routeId($params,'billId'),$user,$auth);return $bill?Response::json(['ok'=>true,'bill'=>$bill]):Response::json(['ok'=>false,'error'=>'Vendor bill not found.'],404);});}
    public function postBill(Request $request,array $params):Response{return $this->mutate($request,'invoicing.post','Vendor bill could not be posted.',function(UserContext $user,Authorization $auth,PurchaseRepository $repo)use($request,$params):Response{$this->requirePermission($auth,'accounting.post');return Response::json(['ok'=>true,'bill'=>$repo->postBill($this->routeId($params,'billId'),$user,$auth,$user->id,$request->remoteAddress())]);});}
    public function createCreditNote(Request $request,array $params):Response{return $this->mutate($request,'invoicing.write','Supplier credit note could not be created.',function(UserContext $user,Authorization $auth,PurchaseRepository $repo)use($request,$params):Response{return Response::json(['ok'=>true,'bill'=>$repo->createFullVendorCreditNote($this->routeId($params,'billId'),$request->input(),$user,$auth,$user->id,$request->remoteAddress())],201);});}
    public function postCreditNote(Request $request,array $params):Response{return $this->mutate($request,'invoicing.post','Supplier credit note could not be posted.',function(UserContext $user,Authorization $auth,PurchaseRepository $repo)use($request,$params):Response{$this->requirePermission($auth,'accounting.post');return Response::json(['ok'=>true,'bill'=>$repo->postVendorCreditNote($this->routeId($params,'creditNoteId'),$user,$auth,$user->id,$request->remoteAddress())]);});}
    public function cancelCreditNote(Request $request,array $params):Response{return $this->mutate($request,'invoicing.write','Supplier credit note could not be discarded.',function(UserContext $user,Authorization $auth,PurchaseRepository $repo)use($request,$params):Response{return Response::json(['ok'=>true,'bill'=>$repo->cancelVendorCreditNote($this->routeId($params,'creditNoteId'),$user,$auth,$user->id,$request->remoteAddress())]);});}
    public function applyCreditNote(Request $request,array $params):Response{return $this->mutate($request,'invoicing.write','Supplier credit could not be applied.',function(UserContext $user,Authorization $auth,PurchaseRepository $repo)use($request,$params):Response{$this->requirePermission($auth,'accounting.reconcile');$this->requirePermission($auth,'accounting.post');return Response::json(['ok'=>true,'bill'=>$repo->applyVendorCreditNote($this->routeId($params,'creditNoteId'),$request->input(),$user,$auth,$user->id,$request->remoteAddress())],201);});}
    public function refundCreditNote(Request $request,array $params):Response{return $this->mutate($request,'invoicing.write','Supplier refund could not be registered.',function(UserContext $user,Authorization $auth,PurchaseRepository $repo)use($request,$params):Response{$this->requirePermission($auth,'accounting.post');return Response::json(['ok'=>true,'bill'=>$repo->registerVendorCreditNoteRefund($this->routeId($params,'creditNoteId'),$request->input(),$user,$auth,$user->id,$request->remoteAddress())],201);});}
    public function reconcileCreditNoteRefund(Request $request,array $params):Response{return $this->mutate($request,'accounting.reconcile','Supplier refund could not be reconciled.',function(UserContext $user,Authorization $auth,PurchaseRepository $repo)use($request,$params):Response{$this->requirePermission($auth,'accounting.post');$bill=$repo->reconcileVendorCreditNoteRefund($this->routeId($params,'refundId'),$request->input(),$user,$auth,$user->id,$request->remoteAddress());return Response::json(['ok'=>true,'bill'=>$bill]);});}
    public function payBill(Request $request,array $params):Response{return $this->mutate($request,'invoicing.write','Vendor payment could not be registered.',function(UserContext $user,Authorization $auth,PurchaseRepository $repo)use($request,$params):Response{$this->requirePermission($auth,'accounting.post');return Response::json(['ok'=>true,'bill'=>$repo->registerVendorPayment($this->routeId($params,'billId'),$request->input(),$user,$auth,$user->id,$request->remoteAddress())]);});}
    public function reconcilePayment(Request $request,array $params):Response{return $this->mutate($request,'accounting.reconcile','Vendor payment could not be reconciled.',function(UserContext $user,Authorization $auth,PurchaseRepository $repo)use($request,$params):Response{$this->requirePermission($auth,'accounting.post');$bill=$repo->reconcileVendorPayment($this->routeId($params,'paymentId'),$request->input(),$user,$auth,$user->id,$request->remoteAddress());return Response::json(['ok'=>true,'bill'=>$bill]);});}
    public function createTax(Request $request,array $params):Response{return $this->mutate($request,'accounting.write','Purchase tax could not be created.',function(UserContext $user,Authorization $auth,PurchaseRepository $repo)use($request):Response{return Response::json(['ok'=>true,'tax'=>$repo->createPurchaseTax($request->input(),$user,$auth,$user->id,$request->remoteAddress())],201);});}

    private function save(Request $request,?int $id):Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return $this->csrfExpired();
        try{[$user,$auth,$repo]=$this->context();$this->requirePermission($auth,'purchase.write');$order=$repo->saveDraft($id,$request->input(),$user,$auth,$user->id,$request->remoteAddress());return Response::json(['ok'=>true,'order'=>$order],$id===null?201:200);}
        catch(AccessDeniedException $e){return $this->forbidden($e->getMessage());}catch(AuthenticationRequiredException $e){return $this->unauthorized($e->getMessage());}catch(InvalidArgumentException $e){return Response::json(['ok'=>false,'error'=>$e->getMessage()],422);}catch(PDOException $e){return $this->databaseFailure($e,'Purchase RFQ could not be saved.');}catch(Throwable $e){error_log('Purchase save failed: '.$e->getMessage());return Response::json(['ok'=>false,'error'=>'Purchase RFQ could not be saved.'],500);}
    }
    private function mutate(Request $request,string $permission,string $failure,callable $action):Response{if(!Session::verifyCsrf($request->header('x-csrf-token')))return $this->csrfExpired();return$this->run($permission,$failure,$action);}
    private function run(string $permission,string $failure,callable $action):Response
    {
        try{[$user,$auth,$repo]=$this->context();$this->requirePermission($auth,$permission);return$action($user,$auth,$repo);}
        catch(AccessDeniedException $e){return$this->forbidden($e->getMessage());}catch(AuthenticationRequiredException $e){return$this->unauthorized($e->getMessage());}catch(InvalidArgumentException $e){return Response::json(['ok'=>false,'error'=>$e->getMessage()],422);}catch(PDOException $e){return$this->databaseFailure($e,$failure);}catch(Throwable $e){error_log('Purchase request failed: '.$e->getMessage());return Response::json(['ok'=>false,'error'=>$failure],500);}
    }
    private function context():array{$pdo=Database::connection($this->config);$user=(new AuthService($pdo))->currentUser();if(!$user)throw new AuthenticationRequiredException('Sign in to use Purchase.');return[$user,new Authorization($pdo,$user),new PurchaseRepository($pdo)];}
    private function requirePermission(Authorization $auth,string $permission):void{if(!$auth->allows($permission))throw new AccessDeniedException('You do not have permission to perform this Purchase or Accounting action.');}
    private function routeId(array $params,string $key):int{$id=filter_var($params[$key]??null,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException('The requested Purchase record ID must be a positive integer.');return$id;}
    private function databaseFailure(PDOException $error,string $message):Response{error_log('Purchase database operation failed: '.$error->getMessage());$state=(string)($error->errorInfo[0]??$error->getCode());if(str_starts_with($state,'23'))return Response::json(['ok'=>false,'error'=>'A duplicate or linked-record conflict prevented this Purchase action.'],409);return Response::json(['ok'=>false,'error'=>$message],500);}
    private function csrfExpired():Response{return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);}
    private function forbidden(string $message):Response{return Response::json(['ok'=>false,'error'=>$message],403);}
    private function unauthorized(string $message):Response{return Response::json(['ok'=>false,'error'=>$message],401);}
}
