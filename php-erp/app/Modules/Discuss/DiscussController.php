<?php
declare(strict_types=1);

namespace Erp\Modules\Discuss;

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

final class DiscussController
{
    public function __construct(private readonly Config $config) {}

    public function options(Request $r, array $p): Response { return $this->handle($r, 'discuss.read', false, fn($repo,$user,$auth) => ['ok'=>true,'options'=>$repo->options($user,$auth,$r->query()['company_id']??null)]); }
    public function workspace(Request $r, array $p): Response { return $this->handle($r, 'discuss.read', false, fn($repo,$user,$auth) => ['ok'=>true]+$repo->workspace($user,$auth,$r->query()['company_id']??null,(string)($r->query()['filter']??'inbox'))); }
    public function conversation(Request $r, array $p): Response { return $this->handle($r, 'discuss.read', false, fn($repo,$user,$auth) => ['ok'=>true]+$repo->conversation($this->id($p),$user,$auth,(int)($r->query()['before_id']??0))); }
    public function createChannel(Request $r, array $p): Response { return $this->handle($r,'discuss.write',true,fn($repo,$user,$auth) => ['ok'=>true,'conversation'=>$repo->createChannel($r->input(),$user,$auth,$r->remoteAddress())],201); }
    public function createDirect(Request $r, array $p): Response { return $this->handle($r,'discuss.write',true,fn($repo,$user,$auth) => ['ok'=>true,'conversation'=>$repo->createDirect($r->input(),$user,$auth,$r->remoteAddress())],201); }
    public function join(Request $r, array $p): Response { return $this->handle($r,'discuss.write',true,function($repo,$user,$auth) use($p,$r) {$repo->join($this->id($p),$user,$auth,$r->remoteAddress());return ['ok'=>true];}); }
    public function send(Request $r, array $p): Response { return $this->handle($r,'discuss.write',true,fn($repo,$user,$auth) => ['ok'=>true,'message'=>$repo->send($this->id($p),(string)($r->input()['body']??''),$user,$auth,$r->remoteAddress())],201); }
    public function markRead(Request $r, array $p): Response { return $this->handle($r,'discuss.read',true,function($repo,$user,$auth) use($p) {$repo->markRead($this->id($p),$user,$auth);return ['ok'=>true];}); }
    public function preference(Request $r, array $p): Response { return $this->handle($r,'discuss.read',true,function($repo,$user,$auth) use($p,$r) {$input=$r->input();$key=(string)($input['key']??'');if(!in_array($key,['starred','archived'],true)||!is_bool($input['value']??null))throw new InvalidArgumentException('Choose a valid conversation preference.');$repo->preference($this->id($p),$key==='archived'?'archived_at':'starred',$input['value'],$user,$auth,$r->remoteAddress());return ['ok'=>true];}); }

    private function handle(Request $request,string $permission,bool $mutation,callable $callback,int $successStatus=200):Response
    {
        if($mutation&&!Session::verifyCsrf($request->header('x-csrf-token')))return Response::json(['ok'=>false,'error'=>'Your session expired. Reload the page and try again.'],419);
        try{$pdo=Database::connection($this->config);$user=(new AuthService($pdo))->currentUser();if(!$user)throw new AuthenticationRequiredException('Sign in to use Discuss.');$auth=new Authorization($pdo,$user);if(!$auth->allows($permission))throw new AccessDeniedException('You do not have permission to use Discuss.');$result=$callback(new DiscussRepository($pdo),$user,$auth);return Response::json($result,$successStatus);}
        catch(AccessDeniedException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],403);}
        catch(AuthenticationRequiredException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],401);}
        catch(InvalidArgumentException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(PDOException $error){error_log('Discuss database request failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>'Discuss could not complete this request. Check that migration 025 has been applied.'],500);}
        catch(Throwable $error){error_log('Discuss request failed: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>'Discuss could not complete this request.'],500);}
    }
    private function id(array $params):int{$id=filter_var($params['id']??null,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException('Conversation ID must be a positive integer.');return$id;}
}
