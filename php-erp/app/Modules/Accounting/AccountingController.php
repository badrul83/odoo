<?php
declare(strict_types=1);

namespace Erp\Modules\Accounting;

use Erp\Core\Config;
use Erp\Core\Database;
use Erp\Core\Http\Request;
use Erp\Core\Http\Response;
use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\AuthenticationRequiredException;
use Erp\Core\Security\AuthService;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDOException;
use Throwable;

final class AccountingController
{
    public function __construct(private readonly Config $config) {}

    public function options(Request $request,array $params):Response
    {
        return $this->run('Accounting report options could not be loaded.',function(UserContext $user,Authorization $auth,AccountingReportsRepository $repo)use($request):Response{
            return Response::json(['ok'=>true,'options'=>$repo->options($request->query()['company_id']??$user->defaultCompanyId,$user,$auth)]);
        });
    }

    public function report(Request $request,array $params):Response
    {
        return $this->run('Accounting report could not be loaded.',function(UserContext $user,Authorization $auth,AccountingReportsRepository $repo)use($request):Response{
            return Response::json(['ok'=>true]+$repo->report($request->query(),$user,$auth));
        });
    }

    public function showMove(Request $request, array $params): Response
    {
        return $this->run('Accounting entry could not be loaded.', function(UserContext $user, Authorization $auth, AccountingReportsRepository $repo) use ($params): Response {
            $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) throw new InvalidArgumentException('Accounting entry ID must be a positive integer.');
            $move = $repo->move($id, $auth);
            return $move
                ? Response::json(['ok' => true, 'move' => $move])
                : Response::json(['ok' => false, 'error' => 'Accounting entry was not found.'], 404);
        });
    }

    private function run(string $failure,callable $action):Response
    {
        try{
            $pdo=Database::connection($this->config);$user=(new AuthService($pdo))->currentUser();
            if(!$user)throw new AuthenticationRequiredException('Sign in to use Accounting reports.');
            return $action($user,new Authorization($pdo,$user),new AccountingReportsRepository($pdo));
        }catch(AccessDeniedException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],403);}
        catch(AuthenticationRequiredException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],401);}
        catch(InvalidArgumentException $error){return Response::json(['ok'=>false,'error'=>$error->getMessage()],422);}
        catch(PDOException $error){error_log('Accounting report database error: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>$failure],500);}
        catch(Throwable $error){error_log('Accounting report error: '.$error->getMessage());return Response::json(['ok'=>false,'error'=>$failure],500);}
    }
}
