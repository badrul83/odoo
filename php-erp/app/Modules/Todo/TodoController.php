<?php
declare(strict_types=1);

namespace Erp\Modules\Todo;

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

final class TodoController
{
    public function __construct(private readonly Config $config) {}

    public function options(Request $request, array $params): Response
    {
        try {
            [$user,$auth,$repository]=$this->context();$this->require($auth,'todo.read');$query=$request->query();
            return Response::json(['ok'=>true,'options'=>$repository->options($query['company_id']??$user->defaultCompanyId,$query['source_model']??null,$query['q']??null,$user,$auth)]);
        } catch (AccessDeniedException $error) { return $this->forbidden($error->getMessage()); }
        catch (AuthenticationRequiredException $error) { return $this->unauthorized($error->getMessage()); }
        catch (InvalidArgumentException $error) { return Response::json(['ok'=>false,'error'=>$error->getMessage()],422); }
        catch (Throwable $error) { error_log('To-do options failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>'To-do setup options could not be loaded.'],500); }
    }

    public function index(Request $request,array $params):Response
    {
        try{[$user,$auth,$repository]=$this->context();$this->require($auth,'todo.read');return Response::json(['ok'=>true]+$repository->search($user,$auth,$request->query()));}
        catch(AccessDeniedException$error){return$this->forbidden($error->getMessage());}
        catch(AuthenticationRequiredException$error){return$this->unauthorized($error->getMessage());}
        catch(InvalidArgumentException$error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(Throwable$error){error_log('To-do list failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>'To-dos could not be loaded.'],500);}
    }

    public function show(Request $request,array $params):Response
    {
        try{[$user,$auth,$repository]=$this->context();$this->require($auth,'todo.read');$task=$repository->find($this->id($params),$user,$auth);if(!$task)return Response::json(['ok'=>false,'error'=>'To-do was not found or is outside your allowed records.'],404);return Response::json(['ok'=>true,'task'=>$task]);}
        catch(AccessDeniedException$error){return$this->forbidden($error->getMessage());}
        catch(AuthenticationRequiredException$error){return$this->unauthorized($error->getMessage());}
        catch(InvalidArgumentException$error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(Throwable$error){error_log('To-do detail failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>'To-do could not be loaded.'],500);}
    }

    public function create(Request $request,array $params):Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return$this->csrfExpired();
        try{[$user,$auth,$repository]=$this->context();$this->require($auth,'todo.write');$task=$repository->create($request->input(),$user,$auth,$user->id,$request->remoteAddress());return Response::json(['ok'=>true,'task'=>$task],201);}
        catch(AccessDeniedException$error){return$this->forbidden($error->getMessage());}
        catch(AuthenticationRequiredException$error){return$this->unauthorized($error->getMessage());}
        catch(InvalidArgumentException$error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(PDOException$error){error_log('To-do create failed: '.$error->getMessage());return$this->databaseFailure($error,'To-do could not be created.');}
        catch(Throwable$error){error_log('To-do create failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>'To-do could not be created.'],500);}
    }

    public function update(Request $request,array $params):Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return$this->csrfExpired();
        try{[$user,$auth,$repository]=$this->context();$this->require($auth,'todo.write');$task=$repository->update($this->id($params),$request->input(),$user,$auth,$user->id,$request->remoteAddress());return Response::json(['ok'=>true,'task'=>$task]);}
        catch(AccessDeniedException$error){return$this->forbidden($error->getMessage());}
        catch(AuthenticationRequiredException$error){return$this->unauthorized($error->getMessage());}
        catch(InvalidArgumentException$error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(PDOException$error){error_log('To-do update failed: '.$error->getMessage());return$this->databaseFailure($error,'To-do could not be updated.');}
        catch(Throwable$error){error_log('To-do update failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>'To-do could not be updated.'],500);}
    }

    public function priority(Request $request,array $params):Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return$this->csrfExpired();
        try{[$user,$auth,$repository]=$this->context();$this->require($auth,'todo.write');$task=$repository->setPriority($this->id($params),$request->input()['priority']??null,$user,$auth,$user->id,$request->remoteAddress());return Response::json(['ok'=>true,'task'=>$task]);}
        catch(AccessDeniedException$error){return$this->forbidden($error->getMessage());}
        catch(AuthenticationRequiredException$error){return$this->unauthorized($error->getMessage());}
        catch(InvalidArgumentException$error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(PDOException$error){error_log('To-do priority update failed: '.$error->getMessage());return$this->databaseFailure($error,'To-do priority could not be updated.');}
        catch(Throwable$error){error_log('To-do priority update failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>'To-do priority could not be updated.'],500);}
    }

    public function complete(Request $request,array $params):Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return$this->csrfExpired();
        try{[$user,$auth,$repository]=$this->context();$this->require($auth,'todo.write');$task=$repository->complete($this->id($params),$user,$auth,$user->id,$request->remoteAddress());return Response::json(['ok'=>true,'task'=>$task]);}
        catch(AccessDeniedException$error){return$this->forbidden($error->getMessage());}
        catch(AuthenticationRequiredException$error){return$this->unauthorized($error->getMessage());}
        catch(InvalidArgumentException$error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(PDOException$error){error_log('To-do completion failed: '.$error->getMessage());return$this->databaseFailure($error,'To-do status could not be updated.');}
        catch(Throwable$error){error_log('To-do completion failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>'To-do status could not be updated.'],500);}
    }

    public function archive(Request $request,array $params):Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return$this->csrfExpired();
        try{[$user,$auth,$repository]=$this->context();$this->require($auth,'todo.write');$repository->archive($this->id($params),$user,$auth,$user->id,$request->remoteAddress());return Response::json(['ok'=>true]);}
        catch(AccessDeniedException$error){return$this->forbidden($error->getMessage());}
        catch(AuthenticationRequiredException$error){return$this->unauthorized($error->getMessage());}
        catch(InvalidArgumentException$error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(PDOException$error){error_log('To-do archive failed: '.$error->getMessage());return$this->databaseFailure($error,'To-do could not be archived.');}
        catch(Throwable$error){error_log('To-do archive failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>'To-do could not be archived.'],500);}
    }

    public function updateStage(Request $request,array $params):Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return$this->csrfExpired();
        try{[$user,$auth,$repository]=$this->context();$this->require($auth,'settings.write');$stage=$repository->updateStage($this->id($params),$request->input(),$auth,$user->id,$request->remoteAddress());return Response::json(['ok'=>true,'stage'=>$stage]);}
        catch(AccessDeniedException$error){return$this->forbidden($error->getMessage());}
        catch(AuthenticationRequiredException$error){return$this->unauthorized($error->getMessage());}
        catch(InvalidArgumentException$error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(PDOException$error){error_log('To-do stage update failed: '.$error->getMessage());return$this->databaseFailure($error,'To-do stage could not be updated.');}
        catch(Throwable$error){error_log('To-do stage update failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>'To-do stage could not be updated.'],500);}
    }

    public function deleteStage(Request $request,array $params):Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return$this->csrfExpired();
        try{[$user,$auth,$repository]=$this->context();$this->require($auth,'settings.write');$repository->deleteStage($this->id($params),$auth,$user->id,$request->remoteAddress());return Response::json(['ok'=>true]);}
        catch(AccessDeniedException$error){return$this->forbidden($error->getMessage());}
        catch(AuthenticationRequiredException$error){return$this->unauthorized($error->getMessage());}
        catch(InvalidArgumentException$error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(PDOException$error){error_log('To-do stage archive failed: '.$error->getMessage());return$this->databaseFailure($error,'To-do stage could not be archived.');}
        catch(Throwable$error){error_log('To-do stage archive failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>'To-do stage could not be archived.'],500);}
    }

    public function createStage(Request $request,array $params):Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return$this->csrfExpired();
        try{[$user,$auth,$repository]=$this->context();$this->require($auth,'settings.write');$stage=$repository->createStage($request->input(),$auth,$user->id,$request->remoteAddress());return Response::json(['ok'=>true,'stage'=>$stage],201);}
        catch(AccessDeniedException$error){return$this->forbidden($error->getMessage());}
        catch(AuthenticationRequiredException$error){return$this->unauthorized($error->getMessage());}
        catch(InvalidArgumentException$error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(PDOException$error){error_log('To-do stage create failed: '.$error->getMessage());return$this->databaseFailure($error,'To-do stage could not be created.');}
        catch(Throwable$error){error_log('To-do stage create failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>'To-do stage could not be created.'],500);}
    }

    private function context():array{$pdo=Database::connection($this->config);$user=(new AuthService($pdo))->currentUser();if(!$user)throw new AuthenticationRequiredException('Sign in to use To-dos.');return[$user,new Authorization($pdo,$user),new TodoRepository($pdo)];}
    private function require(Authorization $auth,string $permission):void{if(!$auth->allows($permission))throw new AccessDeniedException('You do not have permission to perform this To-do action.');}
    private function id(array $params):int{$id=filter_var($params['id']??null,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException('To-do ID must be a positive integer.');return$id;}
    private function databaseFailure(PDOException $error,string $message):Response{$state=(string)($error->errorInfo[0]??$error->getCode());return Response::json(['ok'=>false,'error'=>str_starts_with($state,'23')?'A linked To-do record prevents this operation.':$message],str_starts_with($state,'23')?409:500);}
    private function csrfExpired():Response{return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);}
    private function forbidden(string $message):Response{return Response::json(['ok'=>false,'error'=>$message],403);}
    private function unauthorized(string $message):Response{return Response::json(['ok'=>false,'error'=>$message],401);}
}
