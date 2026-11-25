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
use Erp\Core\Security\WebAuthnService;
use InvalidArgumentException;
use PDOException;
use Throwable;

final class UserAccessController
{
    public function __construct(private readonly Config $config) {}

    public function index(Request $request,array $params): Response
    {
        return $this->run('settings.write','Users could not be loaded.',function(UserContext $user,Authorization $auth,UserAccessRepository $repo):Response{return Response::json(['ok'=>true]+$repo->search($user,$auth));});
    }

    public function options(Request $request,array $params): Response
    {
        return $this->run('settings.write','User creation options could not be loaded.',function(UserContext $user,Authorization $auth,UserAccessRepository $repo):Response{return Response::json(['ok'=>true]+$repo->options($user));});
    }

    public function warehouseOptions(Request $request,array $params): Response
    {
        try {
            [$user,$auth,$repo] = $this->context();
            $rawCompanyId = $request->query()['company_id'] ?? null;
            $companyId = filter_var($rawCompanyId, FILTER_VALIDATE_INT);
            if ($companyId === false || $companyId < 1) throw new InvalidArgumentException('Choose a valid company for the default warehouse.');
            return Response::json(['ok' => true] + $repo->warehouseOptions((int)$companyId, $user, $auth));
        } catch (AccessDeniedException $error) {
            return Response::json(['ok'=>false,'error'=>$error->getMessage()],403);
        } catch (AuthenticationRequiredException $error) {
            return Response::json(['ok'=>false,'error'=>$error->getMessage()],401);
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);
        } catch (Throwable $error) {
            error_log('User warehouse options could not be loaded: '.$error->getMessage());
            return Response::json(['ok'=>false,'error'=>'Default warehouses could not be loaded.'],500);
        }
    }

    public function create(Request $request,array $params): Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        return $this->run('settings.write','User could not be created.',function(UserContext $user,Authorization $auth,UserAccessRepository $repo)use($request):Response{return Response::json(['ok'=>true,'user'=>$repo->create($request->input(),$user,$auth,$user->id,$request->remoteAddress())],201);});
    }

    public function show(Request $request,array $params): Response
    {
        try{$userIdContext=$this->context();[$user,$auth,$repo]=$userIdContext;$id=$this->routeId($params);if($id!==$user->id&&!$auth->allows('settings.write'))return Response::json(['ok'=>false,'error'=>'You do not have permission to manage this user profile.'],403);$profile=$repo->find($id,$user,$auth);return$profile?Response::json(['ok'=>true,'user'=>$profile,'permissions'=>$auth->allows('settings.write')?$repo->permissions():[]]):Response::json(['ok'=>false,'error'=>'User not found.'],404);}
        catch(AuthenticationRequiredException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],401);}
        catch(InvalidArgumentException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(PDOException $error){error_log('User profile read failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>'User profile could not be loaded.'],500);}
        catch(Throwable $error){error_log('User profile read failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>'User profile could not be loaded.'],500);}
    }

    public function update(Request $request,array $params): Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        return $this->run('settings.write','User profile could not be saved.',function(UserContext $user,Authorization $auth,UserAccessRepository $repo)use($request,$params):Response{return Response::json(['ok'=>true,'user'=>$repo->update($this->routeId($params),$request->input(),$user,$auth,$user->id,$request->remoteAddress())]);});
    }

    public function ownSessions(Request $request,array $params): Response
    {
        return $this->runSelf('User device sessions could not be loaded.',function(UserContext $user,Authorization $auth,UserAccessRepository $repo)use($request):Response{$raw=$request->query()['offset']??0;$offset=filter_var($raw,FILTER_VALIDATE_INT);if($offset===false||$offset<0||$offset>1000000)throw new InvalidArgumentException('Device page offset is invalid.');return Response::json(['ok'=>true]+$repo->ownSessions($user,40,$offset));});
    }

    public function ownPasskeys(Request $request,array $params): Response
    {
        return $this->runSelf('Passkeys could not be loaded.',function(UserContext $user,Authorization $auth,UserAccessRepository $repo):Response{return Response::json(['ok'=>true]+$this->passkeyService()->passkeys($user));});
    }

    public function beginPasskeyRegistration(Request $request,array $params): Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        return $this->runSelf('Passkey registration could not be started.',function(UserContext $user,Authorization $auth,UserAccessRepository $repo)use($request):Response{$input=$request->input();if(array_diff(array_keys($input),['current_password']))throw new InvalidArgumentException('Unsupported passkey registration fields.');return Response::json(['ok'=>true,'options'=>$this->passkeyService()->beginRegistration((string)($input['current_password']??''),$user,$request->remoteAddress())]);});
    }

    public function finishPasskeyRegistration(Request $request,array $params): Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        return $this->runSelf('Passkey could not be saved.',function(UserContext $user,Authorization $auth,UserAccessRepository $repo)use($request):Response{return Response::json(['ok'=>true]+$this->passkeyService()->finishRegistration($request->input(),$user,$request->remoteAddress()),201);});
    }

    public function removePasskey(Request $request,array $params): Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        return $this->runSelf('Passkey could not be removed.',function(UserContext $user,Authorization $auth,UserAccessRepository $repo)use($request,$params):Response{$input=$request->input();if(array_diff(array_keys($input),['current_password']))throw new InvalidArgumentException('Unsupported passkey removal fields.');$this->passkeyService()->removePasskey($this->routeId($params),(string)($input['current_password']??''),$user,$request->remoteAddress());return Response::json(['ok'=>true]);});
    }

    public function revokeOwnSession(Request $request,array $params): Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        return $this->runSelf('Device session could not be ended.',function(UserContext $user,Authorization $auth,UserAccessRepository $repo)use($params,$request):Response{$repo->revokeOwnSession($this->routeId($params),$user,$user->id,$request->remoteAddress());return Response::json(['ok'=>true]);});
    }

    public function changeOwnPassword(Request $request,array $params): Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        return $this->runSelf('Password could not be changed.',function(UserContext $user,Authorization $auth,UserAccessRepository $repo)use($request):Response{return Response::json(['ok'=>true]+$repo->changeOwnPassword($request->input(),$user,$request->remoteAddress()));});
    }

    public function twoFactorStatus(Request $request,array $params): Response
    {
        return $this->runSelf('Two-factor status could not be loaded.',function(UserContext $user,Authorization $auth,UserAccessRepository $repo):Response{return Response::json(['ok'=>true]+$repo->twoFactorStatus($user));});
    }

    public function apiKeys(Request $request,array $params): Response
    {
        return $this->runSelf('API keys could not be loaded.',function(UserContext $user,Authorization $auth,UserAccessRepository $repo):Response{return Response::json(['ok'=>true]+$repo->apiKeys($user));});
    }

    public function createApiKey(Request $request,array $params): Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        return $this->runSelf('API key could not be created.',function(UserContext $user,Authorization $auth,UserAccessRepository $repo)use($request):Response{return Response::json(['ok'=>true]+$repo->createApiKey($request->input(),$user,$user->id,$request->remoteAddress()),201);});
    }

    public function revokeApiKey(Request $request,array $params): Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        return $this->runSelf('API key could not be revoked.',function(UserContext $user,Authorization $auth,UserAccessRepository $repo)use($request,$params):Response{$repo->revokeApiKey($this->routeId($params),$user,$user->id,$request->remoteAddress());return Response::json(['ok'=>true]);});
    }

    public function beginTwoFactorEnrollment(Request $request,array $params): Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        return $this->runSelf('Authenticator setup could not be started.',function(UserContext $user,Authorization $auth,UserAccessRepository $repo)use($request):Response{$input=$request->input();$unknown=array_diff(array_keys($input),['current_password']);if($unknown)throw new InvalidArgumentException('Unsupported authenticator setup fields.');$result=$repo->beginTwoFactorEnrollment((string)($input['current_password']??''),$user,'PHP ERP',$request->remoteAddress());return Response::json(['ok'=>true]+$result);});
    }

    public function confirmTwoFactorEnrollment(Request $request,array $params): Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        return $this->runSelf('Authenticator setup could not be confirmed.',function(UserContext $user,Authorization $auth,UserAccessRepository $repo)use($request):Response{$input=$request->input();$unknown=array_diff(array_keys($input),['code']);if($unknown)throw new InvalidArgumentException('Unsupported authenticator confirmation fields.');return Response::json(['ok'=>true]+$repo->confirmTwoFactorEnrollment((string)($input['code']??''),$user,$user->id,$request->remoteAddress()));});
    }

    public function disableTwoFactor(Request $request,array $params): Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        return $this->runSelf('Two-factor authentication could not be disabled.',function(UserContext $user,Authorization $auth,UserAccessRepository $repo)use($request):Response{$input=$request->input();$unknown=array_diff(array_keys($input),['current_password','code']);if($unknown)throw new InvalidArgumentException('Unsupported authenticator disable fields.');return Response::json(['ok'=>true]+$repo->disableTwoFactor((string)($input['current_password']??''),(string)($input['code']??''),$user,$user->id,$request->remoteAddress()));});
    }

    public function updateOwnPreferences(Request $request,array $params): Response
    {
        if(!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        return $this->runSelf('Your preferences could not be saved.',function(UserContext $user,Authorization $auth,UserAccessRepository $repo)use($request):Response{return Response::json(['ok'=>true,'user'=>$repo->updateOwnPreferences($request->input(),$user,$auth,$request->remoteAddress())]);});
    }

    private function run(string $permission,string $failure,callable $action): Response
    {
        try{[$user,$auth,$repository]=$this->context();if(!$auth->allows($permission))throw new AccessDeniedException('You do not have permission to manage users and access rights.');return$action($user,$auth,$repository);}
        catch(AccessDeniedException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],403);}
        catch(AuthenticationRequiredException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],401);}
        catch(InvalidArgumentException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(PDOException $error){$state=(string)($error->errorInfo[0]??$error->getCode());if(str_starts_with($state,'23'))return Response::json(['ok'=>false,'error'=>'A duplicate or linked user record prevented this change.'],409);error_log('User access database operation failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>$failure],500);}
        catch(Throwable $error){error_log('User access operation failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>$failure],500);}
    }

    private function runSelf(string $failure,callable $action): Response
    {
        try{[$user,$auth,$repository]=$this->context();return$action($user,$auth,$repository);}
        catch(AuthenticationRequiredException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],401);}
        catch(InvalidArgumentException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(PDOException $error){error_log('User security database operation failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>$failure],500);}
        catch(Throwable $error){error_log('User security operation failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>$failure],500);}
    }

    private function context(): array
    {
        $pdo=Database::connection($this->config);$user=(new AuthService($pdo,$this->config->dataEncryptionKey()))->currentUser();if(!$user)throw new AuthenticationRequiredException('Sign in to manage Users.');return[$user,new Authorization($pdo,$user),new UserAccessRepository($pdo,$this->config->dataEncryptionKey())];
    }

    private function passkeyService(): WebAuthnService
    {
        return new WebAuthnService(Database::connection($this->config), $this->config);
    }

    private function routeId(array $params): int
    {
        $id=filter_var($params['id']??null,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException('The requested User ID must be a positive integer.');return$id;
    }
}
