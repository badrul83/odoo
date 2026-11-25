<?php
declare(strict_types=1);

namespace Erp\Modules\Manufacturing;

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

final class ManufacturingController
{
    public function __construct(private readonly Config $config) {}

    public function options(Request $request,array $params):Response
    {
        try{[$user,$auth,$repo]=$this->context();$this->require($auth,'manufacturing.read');return Response::json(['ok'=>true]+$repo->options($user,$auth,$request->query()['company_id']??null));}catch(Throwable$error){return$this->failure($error,'Manufacturing options could not be loaded.');}
    }
    public function workCenters(Request $request,array $params):Response
    {
        try{[$user,$auth,$repo]=$this->context();$this->require($auth,'manufacturing.read');return Response::json(['ok'=>true]+$repo->workCenters($user,$auth,$request->query()['company_id']??null));}catch(Throwable$error){return$this->failure($error,'Work centers could not be loaded.');}
    }
    public function saveWorkCenter(Request $request,array $params):Response{return$this->save($request,function($repo,$user,$auth,$actor,$ip,$input,$id){$this->require($auth,'manufacturing.write');return$repo->saveWorkCenter($id,$input,$user,$auth,$actor,$ip);},isset($params['id'])?$this->routeId($params):null,'Work center could not be saved.');}
    public function boms(Request $request,array $params):Response
    {
        try{[$user,$auth,$repo]=$this->context();$this->require($auth,'manufacturing.read');$query=$request->query();return Response::json(['ok'=>true]+$repo->boms($user,$auth,$query['company_id']??null,$query));}catch(Throwable$error){return$this->failure($error,'Bills of materials could not be loaded.');}
    }
    public function bom(Request $request,array $params):Response
    {
        try{[$user,$auth,$repo]=$this->context();$this->require($auth,'manufacturing.read');$row=$repo->bom($this->routeId($params),$user,$auth);return$row?Response::json(['ok'=>true,'bom'=>$row]):Response::json(['ok'=>false,'error'=>'Bill of materials not found.'],404);}catch(Throwable$error){return$this->failure($error,'Bill of materials could not be loaded.');}
    }
    public function saveBom(Request $request,array $params):Response{return$this->save($request,function($repo,$user,$auth,$actor,$ip,$input,$id){$this->require($auth,'manufacturing.write');return$repo->saveBom($id,$input,$user,$auth,$actor,$ip);},isset($params['id'])?$this->routeId($params):null,'Bill of materials could not be saved.');}
    public function orders(Request $request,array $params):Response
    {
        try{[$user,$auth,$repo]=$this->context();$this->require($auth,'manufacturing.read');$query=$request->query();return Response::json(['ok'=>true]+$repo->orders($user,$auth,$query['company_id']??null,$query));}catch(Throwable$error){return$this->failure($error,'Manufacturing orders could not be loaded.');}
    }
    public function order(Request $request,array $params):Response
    {
        try{[$user,$auth,$repo]=$this->context();$this->require($auth,'manufacturing.read');$row=$repo->order($this->routeId($params),$user,$auth);return$row?Response::json(['ok'=>true,'order'=>$row]):Response::json(['ok'=>false,'error'=>'Manufacturing Order not found.'],404);}catch(Throwable$error){return$this->failure($error,'Manufacturing Order could not be loaded.');}
    }
    public function createOrder(Request $request,array $params):Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return$this->csrf();
        try{[$user,$auth,$repo]=$this->context();$this->require($auth,'manufacturing.write');$row=$repo->createOrder($request->input(),$user,$auth,$user->id,$request->remoteAddress());return Response::json(['ok'=>true,'order'=>$row],201);}catch(Throwable$error){return$this->failure($error,'Manufacturing Order could not be created.');}
    }
    public function confirm(Request $request,array $params):Response{return$this->transition($request,$params,'manufacturing.confirm',fn($repo,$user,$auth,$id,$input,$actor,$ip)=>$repo->confirmOrder($id,$input,$user,$auth,$actor,$ip),'Manufacturing Order could not be confirmed.',false,true);}
    public function startWorkOrder(Request $request,array $params):Response{return$this->transition($request,$params,'manufacturing.produce',fn($repo,$user,$auth,$id,$input,$actor,$ip)=>$repo->startWorkOrder($id,$user,$auth,$actor,$ip),'Work Order could not be started.');}
    public function completeWorkOrder(Request $request,array $params):Response{return$this->transition($request,$params,'manufacturing.produce',fn($repo,$user,$auth,$id,$input,$actor,$ip)=>$repo->completeWorkOrder($id,$input,$user,$auth,$actor,$ip),'Work Order could not be completed.');}
    public function produce(Request $request,array $params):Response{return$this->transition($request,$params,'manufacturing.produce',fn($repo,$user,$auth,$id,$input,$actor,$ip)=>$repo->produceOrder($id,$input,$user,$auth,$actor,$ip),'Finished products could not be produced.',true);}
    public function cancel(Request $request,array $params):Response{return$this->transition($request,$params,'manufacturing.write',fn($repo,$user,$auth,$id,$input,$actor,$ip)=>$repo->cancelOrder($id,$user,$auth,$actor,$ip),'Manufacturing Order could not be cancelled.',false,true);}

    private function save(Request $request,callable $operation,?int $id,string $fallback):Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return$this->csrf();
        try{[$user,$auth,$repo]=$this->context();$row=$operation($repo,$user,$auth,$user->id,$request->remoteAddress(),$request->input(),$id);return Response::json(['ok'=>true,'record'=>$row],$id===null?201:200);}catch(Throwable$error){return$this->failure($error,$fallback);}
    }
    private function transition(Request $request,array $params,string $permission,callable $operation,string $fallback,bool $needsInventoryValidation=false,bool $needsInventoryWrite=false):Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return$this->csrf();
        try{[$user,$auth,$repo]=$this->context();$this->require($auth,$permission);if($needsInventoryWrite)$this->require($auth,'inventory.write');if($needsInventoryValidation)$this->require($auth,'inventory.validate');$row=$operation($repo,$user,$auth,$this->routeId($params),$request->input(),$user->id,$request->remoteAddress());return Response::json(['ok'=>true,'record'=>$row]);}catch(Throwable$error){return$this->failure($error,$fallback);}
    }
    /** @return array{UserContext,Authorization,ManufacturingRepository} */
    private function context():array{$pdo=Database::connection($this->config);$user=(new AuthService($pdo))->currentUser();if(!$user)throw new AuthenticationRequiredException('Sign in to use Manufacturing.');return[$user,new Authorization($pdo,$user),new ManufacturingRepository($pdo)];}
    private function require(Authorization $auth,string $permission):void{if(!$auth->allows($permission))throw new AccessDeniedException('You do not have permission to perform this Manufacturing action.');}
    private function routeId(array $params):int{$id=filter_var($params['id']??null,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException('Record ID must be a positive integer.');return(int)$id;}
    private function csrf():Response{return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);}
    private function failure(Throwable $error,string $fallback):Response
    {
        if($error instanceof AccessDeniedException)return Response::json(['ok'=>false,'error'=>$error->getMessage()],403);
        if($error instanceof AuthenticationRequiredException)return Response::json(['ok'=>false,'error'=>$error->getMessage()],401);
        if($error instanceof InvalidArgumentException)return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);
        if($error instanceof PDOException){error_log('Manufacturing request failed: '.$error->getMessage());$state=(string)($error->errorInfo[0]??$error->getCode());if(str_starts_with($state,'23'))return Response::json(['ok'=>false,'error'=>'A duplicate reference or linked manufacturing record prevented this operation.'],409);}
        else error_log('Manufacturing request failed: '.$error->getMessage());
        return Response::json(['ok'=>false,'error'=>$fallback],500);
    }
}
