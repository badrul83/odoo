<?php
declare(strict_types=1);

namespace Erp\Modules\Settings;

use Erp\Core\Security\Authorization;
use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDO;
use Throwable;

final class ApprovalRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function policies(UserContext $actor, Authorization $auth, int $companyId): array
    {
        $this->requireCompany($actor, $auth, $companyId);
        $query = $this->pdo->prepare('SELECT id,company_id,document_type,name,minimum_amount,active FROM approval_policies WHERE company_id=:company ORDER BY document_type');
        $query->execute(['company' => $companyId]);
        $policies = $query->fetchAll();
        $steps = $this->pdo->prepare('SELECT s.sequence,s.approver_user_id,u.login,c.name AS approver_name FROM approval_policy_steps s JOIN users u ON u.id=s.approver_user_id JOIN contacts c ON c.id=u.contact_id WHERE s.policy_id=:policy ORDER BY s.sequence');
        foreach ($policies as &$policy) {
            $policy['id'] = (int)$policy['id'];
            $policy['company_id'] = (int)$policy['company_id'];
            $policy['minimum_amount'] = (string)$policy['minimum_amount'];
            $policy['active'] = (bool)$policy['active'];
            $steps->execute(['policy' => $policy['id']]);
            $policy['steps'] = array_map(static fn(array $step): array => [
                'sequence' => (int)$step['sequence'],
                'approver_user_id' => (int)$step['approver_user_id'],
                'approver_name' => $step['approver_name'],
                'login' => $step['login'],
            ], $steps->fetchAll());
        }
        unset($policy);
        $users = $this->pdo->prepare("SELECT u.id,c.name,u.login FROM users u JOIN contacts c ON c.id=u.contact_id JOIN user_company_access a ON a.user_id=u.id WHERE a.company_id=:company AND u.status IN ('confirmed','active') ORDER BY c.name,u.id");
        $users->execute(['company' => $companyId]);
        return ['policies' => $policies, 'approvers' => array_map(static fn(array $row): array => ['id'=>(int)$row['id'],'name'=>$row['name'],'login'=>$row['login']], $users->fetchAll())];
    }

    public function savePolicy(array $input, UserContext $actor, Authorization $auth, int $actorId, string $ip): array
    {
        $unknown = array_diff(array_keys($input), ['company_id','document_type','name','minimum_amount','active','steps']);
        if ($unknown) throw new InvalidArgumentException('Unsupported approval policy fields: ' . implode(', ', $unknown));
        $companyId = $this->positiveId($input['company_id'] ?? null, 'Company');
        $this->requireCompany($actor, $auth, $companyId);
        $type = (string)($input['document_type'] ?? '');
        if (!in_array($type, ['sales_order','purchase_order'], true)) throw new InvalidArgumentException('Select Sales Order or Purchase Order for this policy.');
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') $name = $type === 'sales_order' ? 'Sales Order Approval' : 'Purchase Order Approval';
        if (mb_strlen($name) > 120) throw new InvalidArgumentException('Approval policy name cannot exceed 120 characters.');
        $rawAmount = $input['minimum_amount'] ?? 0;
        if (!is_scalar($rawAmount) || !preg_match('/^\d{1,14}(?:\.\d{1,6})?$/', (string)$rawAmount)) throw new InvalidArgumentException('Minimum amount must be a non-negative number with up to six decimals.');
        $amount = $this->normalizeDecimal((string)$rawAmount);
        $active = filter_var($input['active'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($active === null) throw new InvalidArgumentException('Active must be true or false.');
        $rawSteps = $input['steps'] ?? [];
        if (!is_array($rawSteps) || count($rawSteps) > 20) throw new InvalidArgumentException('Approval policy supports up to 20 ordered approval steps.');
        $approverIds = [];
        foreach (array_values($rawSteps) as $index => $step) {
            $approverId = $this->positiveId(is_array($step) ? ($step['approver_user_id'] ?? null) : $step, 'Step ' . ($index + 1) . ' Approver');
            if (in_array($approverId, $approverIds, true)) throw new InvalidArgumentException('Each approval step must have a different approver.');
            $approverIds[] = $approverId;
        }
        if ($active && !$approverIds) throw new InvalidArgumentException('Add at least one approver before activating this policy.');
        if ($approverIds) {
            $check = $this->pdo->prepare("SELECT COUNT(*) FROM users u JOIN user_company_access a ON a.user_id=u.id WHERE a.company_id=:company AND u.status IN ('confirmed','active') AND u.id=:user");
            foreach ($approverIds as $id) {
                $check->execute(['company' => $companyId, 'user' => $id]);
                if ((int)$check->fetchColumn() !== 1) throw new InvalidArgumentException('Every approver must be an active or confirmed user assigned to this company.');
            }
        }

        $this->pdo->beginTransaction();
        try {
            $find = $this->pdo->prepare('SELECT id,name,minimum_amount,active FROM approval_policies WHERE company_id=:company AND document_type=:type FOR UPDATE');
            $find->execute(['company' => $companyId, 'type' => $type]);
            $old = $find->fetch() ?: null;
            if ($old) {
                $policyId = (int)$old['id'];
                $this->pdo->prepare('UPDATE approval_policies SET name=:name,minimum_amount=:amount,active=:active,updated_by=:actor WHERE id=:id')->execute(['name'=>$name,'amount'=>$amount,'active'=>$active?1:0,'actor'=>$actorId,'id'=>$policyId]);
                $this->pdo->prepare('DELETE FROM approval_policy_steps WHERE policy_id=:policy')->execute(['policy'=>$policyId]);
            } else {
                $this->pdo->prepare('INSERT INTO approval_policies (company_id,document_type,name,minimum_amount,active,created_by,updated_by) VALUES (:company,:type,:name,:amount,:active,:creator,:updater)')->execute(['company'=>$companyId,'type'=>$type,'name'=>$name,'amount'=>$amount,'active'=>$active?1:0,'creator'=>$actorId,'updater'=>$actorId]);
                $policyId = (int)$this->pdo->lastInsertId();
            }
            $add = $this->pdo->prepare('INSERT INTO approval_policy_steps (policy_id,sequence,approver_user_id) VALUES (:policy,:sequence,:approver)');
            foreach ($approverIds as $index => $approverId) $add->execute(['policy'=>$policyId,'sequence'=>$index+1,'approver'=>$approverId]);
            $this->audit($companyId,$actorId,$policyId,'approval_policy','approval_policy_saved',$old,['document_type'=>$type,'name'=>$name,'minimum_amount'=>$amount,'active'=>$active,'approver_user_ids'=>$approverIds],$ip);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $this->policies($actor, $auth, $companyId);
    }

    /** Called inside the Sales/Purchase confirmation transaction. Null means no gate is required or approval is complete. */
    public function requestIfRequired(string $documentType, int $documentId, int $companyId, string $currency, string $amount, string $fingerprint, int $actorId, string $ip): ?array
    {
        $last = $this->pdo->prepare('SELECT id,status,document_fingerprint FROM approval_requests WHERE document_type=:type AND document_id=:document AND company_id=:company ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $last->execute(['type'=>$documentType,'document'=>$documentId,'company'=>$companyId]);
        $existing = $last->fetch();
        if ($existing && $existing['status'] === 'pending') {
            if (hash_equals((string)$existing['document_fingerprint'],$fingerprint)) return ['id'=>(int)$existing['id'],'status'=>'pending','created'=>false];
            $this->pdo->prepare("UPDATE approval_requests SET status='cancelled',completed_at=UTC_TIMESTAMP() WHERE id=:id")->execute(['id'=>$existing['id']]);
            $this->pdo->prepare("UPDATE approval_request_steps SET status='skipped' WHERE request_id=:request AND status='pending'")->execute(['request'=>$existing['id']]);
            $this->audit($companyId,$actorId,$documentId,$documentType,'approval_invalidated_by_document_change',['approval_request_id'=>(int)$existing['id']],['status'=>'cancelled'],$ip);
        }
        $policyQuery = $this->pdo->prepare('SELECT p.id,p.name,p.minimum_amount,c.currency_code AS company_currency FROM approval_policies p JOIN companies c ON c.id=p.company_id WHERE p.company_id=:company AND p.document_type=:type AND p.active=1');
        $policyQuery->execute(['company'=>$companyId,'type'=>$documentType]);
        $policy = $policyQuery->fetch();
        if ($policy && $currency !== $policy['company_currency']) throw new InvalidArgumentException('Approval thresholds use the company currency. Configure exchange rates or use the company currency before confirming this document.');
        if (!$policy || $this->compareDecimals($amount, (string)$policy['minimum_amount']) < 0) return null;
        if ($existing && $existing['status'] === 'approved' && hash_equals((string)$existing['document_fingerprint'],$fingerprint)) return null;
        $stepsQuery = $this->pdo->prepare('SELECT sequence,approver_user_id FROM approval_policy_steps WHERE policy_id=:policy ORDER BY sequence');
        $stepsQuery->execute(['policy'=>$policy['id']]);
        $steps = $stepsQuery->fetchAll();
        if (!$steps) throw new InvalidArgumentException('The active approval policy has no approval steps. Ask a Settings administrator to review it.');
        $insert = $this->pdo->prepare("INSERT INTO approval_requests (company_id,document_type,document_id,policy_id,requested_by,amount,currency_code,document_fingerprint,status,current_sequence) VALUES (:company,:type,:document,:policy,:actor,:amount,:currency,:fingerprint,'pending',1)");
        $insert->execute(['company'=>$companyId,'type'=>$documentType,'document'=>$documentId,'policy'=>$policy['id'],'actor'=>$actorId,'amount'=>$amount,'currency'=>$currency,'fingerprint'=>$fingerprint]);
        $requestId = (int)$this->pdo->lastInsertId();
        $add = $this->pdo->prepare("INSERT INTO approval_request_steps (request_id,sequence,approver_user_id,status) VALUES (:request,:sequence,:approver,'pending')");
        foreach ($steps as $step) $add->execute(['request'=>$requestId,'sequence'=>$step['sequence'],'approver'=>$step['approver_user_id']]);
        $this->audit($companyId,$actorId,$documentId,$documentType,'approval_requested',null,['approval_request_id'=>$requestId,'policy_id'=>(int)$policy['id'],'amount'=>$amount,'currency_code'=>$currency,'approver_count'=>count($steps)],$ip);
        return ['id'=>$requestId,'status'=>'pending','created'=>true];
    }

    public function approvalForDocument(string $documentType, int $documentId, UserContext $user, Authorization $auth): ?array
    {
        $query = $this->pdo->prepare('SELECT ar.*,c.name AS requester_name FROM approval_requests ar JOIN contacts c ON c.id=(SELECT u.contact_id FROM users u WHERE u.id=ar.requested_by) WHERE ar.document_type=:type AND ar.document_id=:document ORDER BY ar.id DESC LIMIT 1');
        $query->execute(['type'=>$documentType,'document'=>$documentId]);
        $request = $query->fetch();
        if (!$request || !$auth->canAccessCompany((int)$request['company_id'])) return null;
        $request['id']=(int)$request['id'];$request['current_sequence']=(int)$request['current_sequence'];$request['amount']=(string)$request['amount'];
        $steps=$this->pdo->prepare('SELECT s.sequence,s.approver_user_id,s.status,s.decision_comment,s.acted_at,c.name AS approver_name FROM approval_request_steps s JOIN users u ON u.id=s.approver_user_id JOIN contacts c ON c.id=u.contact_id WHERE s.request_id=:request ORDER BY s.sequence');
        $steps->execute(['request'=>$request['id']]);$request['steps']=array_map(static fn(array $row):array=>['sequence'=>(int)$row['sequence'],'approver_user_id'=>(int)$row['approver_user_id'],'status'=>$row['status'],'decision_comment'=>$row['decision_comment'],'acted_at'=>$row['acted_at'],'approver_name'=>$row['approver_name']],$steps->fetchAll());
        return $request;
    }

    public static function fingerprint(array $document): string
    {
        $headerKeys=['company_id','partner_contact_id','invoice_contact_id','delivery_contact_id','crm_lead_id','salesperson_user_id','buyer_user_id','pricelist_id','payment_terms_id','warehouse_id','currency_code','order_date','validity_date','order_deadline','expected_arrival','arrival_confirmation','vendor_reference','bill_control_policy','three_way_match','client_order_ref','note','untaxed_amount','tax_amount','total_amount'];
        $lineKeys=['id','product_id','uom_id','description','quantity','unit_price','discount_percent','tax_id','tax_name_snapshot','tax_rate_snapshot','tax_included_snapshot','subtotal','tax_amount','total'];
        $header=[];foreach($headerKeys as$key)if(array_key_exists($key,$document))$header[$key]=$document[$key];
        $lines=[];foreach(($document['lines']??[]) as$line){$snapshot=[];foreach($lineKeys as$key)if(array_key_exists($key,$line))$snapshot[$key]=$line[$key];$lines[]=$snapshot;}
        return hash('sha256',json_encode(['header'=>$header,'lines'=>$lines],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    public function inbox(UserContext $actor, Authorization $auth): array
    {
        $scope='';$params=['actor'=>$actor->id];
        if(!$actor->superuser){if(!$actor->companyIds)return[];$slots=[];foreach($actor->companyIds as $index=>$companyId){$slots[]=':company_'.$index;$params['company_'.$index]=$companyId;}$scope=' AND ar.company_id IN ('.implode(',',$slots).')';}
        $sql="SELECT ar.id,ar.company_id,ar.document_type,ar.document_id,ar.amount,ar.currency_code,ar.status,ar.current_sequence,ar.created_at,requester.name AS requester_name,co.name AS company_name,supplier.name AS partner_name,COALESCE(so.name,po.name) AS document_name,COALESCE(so.total_amount,po.total_amount) AS document_amount FROM approval_requests ar JOIN approval_request_steps current_step ON current_step.request_id=ar.id AND current_step.sequence=ar.current_sequence AND current_step.approver_user_id=:actor AND current_step.status='pending' JOIN users ru ON ru.id=ar.requested_by JOIN contacts requester ON requester.id=ru.contact_id JOIN companies co ON co.id=ar.company_id LEFT JOIN sales_orders so ON ar.document_type='sales_order' AND so.id=ar.document_id LEFT JOIN purchase_orders po ON ar.document_type='purchase_order' AND po.id=ar.document_id LEFT JOIN contacts supplier ON supplier.id=COALESCE(so.partner_contact_id,po.partner_contact_id) WHERE ar.status='pending'{$scope} ORDER BY ar.created_at,ar.id";
        $query=$this->pdo->prepare($sql);$query->execute($params);$items=$query->fetchAll();
        foreach($items as &$item){$item['id']=(int)$item['id'];$item['company_id']=(int)$item['company_id'];$item['document_id']=(int)$item['document_id'];$item['current_sequence']=(int)$item['current_sequence'];$item['amount']=(string)$item['amount'];}
        unset($item);return $items;
    }

    public function cancelPending(string $documentType,int $documentId,int $companyId,int $actorId,string $ip):void
    {
        $query=$this->pdo->prepare("SELECT id FROM approval_requests WHERE company_id=:company AND document_type=:type AND document_id=:document AND status='pending' ORDER BY id DESC LIMIT 1 FOR UPDATE");$query->execute(['company'=>$companyId,'type'=>$documentType,'document'=>$documentId]);$id=$query->fetchColumn();if($id===false)return;
        $this->pdo->prepare("UPDATE approval_requests SET status='cancelled',completed_at=UTC_TIMESTAMP() WHERE id=:id")->execute(['id'=>$id]);
        $this->pdo->prepare("UPDATE approval_request_steps SET status='skipped' WHERE request_id=:request AND status='pending'")->execute(['request'=>$id]);
        $this->audit($companyId,$actorId,$documentId,$documentType,'approval_cancelled',null,['approval_request_id'=>(int)$id],$ip);
    }

    public function decide(int $requestId, string $decision, string $comment, UserContext $actor, Authorization $auth, string $ip): array
    {
        if(!in_array($decision,['approve','reject'],true))throw new InvalidArgumentException('Choose Approve or Reject.');
        if(mb_strlen($comment)>1000)throw new InvalidArgumentException('Approval comment cannot exceed 1000 characters.');
        $this->pdo->beginTransaction();
        try{
            $query=$this->pdo->prepare('SELECT * FROM approval_requests WHERE id=:id FOR UPDATE');$query->execute(['id'=>$requestId]);$request=$query->fetch();
            if(!$request||$request['status']!=='pending'||!$auth->canAccessCompany((int)$request['company_id']))throw new InvalidArgumentException('This approval request is unavailable or no longer pending.');
            $permission=$request['document_type']==='sales_order'?'sales.approve':'purchase.approve';
            if(!$auth->allows('approvals.write')&&!$auth->allows($permission))throw new AccessDeniedException('You do not have approval access for this document.');
            $stepQuery=$this->pdo->prepare('SELECT id,approver_user_id,status FROM approval_request_steps WHERE request_id=:request AND sequence=:sequence FOR UPDATE');$stepQuery->execute(['request'=>$requestId,'sequence'=>$request['current_sequence']]);$step=$stepQuery->fetch();
            if(!$step||(int)$step['approver_user_id']!==$actor->id||$step['status']!=='pending')throw new InvalidArgumentException('Only the assigned approver for the current approval step can decide this request.');
            $stepStatus=$decision==='approve'?'approved':'rejected';
            $this->pdo->prepare('UPDATE approval_request_steps SET status=:status,decision_comment=:comment,acted_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['status'=>$stepStatus,'comment'=>$comment!==''?$comment:null,'id'=>$step['id']]);
            if($decision==='reject'){$this->pdo->prepare("UPDATE approval_request_steps SET status='skipped' WHERE request_id=:request AND sequence>:sequence AND status='pending'")->execute(['request'=>$requestId,'sequence'=>$request['current_sequence']]);$status='rejected';$sequence=(int)$request['current_sequence'];}
            else{$next=$this->pdo->prepare("SELECT sequence FROM approval_request_steps WHERE request_id=:request AND status='pending' ORDER BY sequence LIMIT 1");$next->execute(['request'=>$requestId]);$nextSequence=$next->fetchColumn();$status=$nextSequence===false?'approved':'pending';$sequence=$nextSequence===false?(int)$request['current_sequence']:(int)$nextSequence;}
            $this->pdo->prepare('UPDATE approval_requests SET status=:status,current_sequence=:sequence,completed_at=:completed WHERE id=:id')->execute(['status'=>$status,'sequence'=>$sequence,'completed'=>$status==='pending'?null:gmdate('Y-m-d H:i:s'),'id'=>$requestId]);
            $this->audit((int)$request['company_id'],$actor->id,(int)$request['document_id'],$request['document_type'],'approval_'.$decision,['status'=>'pending','sequence'=>(int)$request['current_sequence']],['status'=>$status,'sequence'=>$sequence,'comment'=>$comment],$ip);
            $this->pdo->commit();
        }catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$error;}
        return ['id'=>$requestId,'status'=>$status,'current_sequence'=>$sequence,'document_type'=>$request['document_type'],'document_id'=>(int)$request['document_id']];
    }

    private function requireCompany(UserContext $actor, Authorization $auth, int $companyId): void
    {
        if(!$auth->canAccessCompany($companyId))throw new InvalidArgumentException('Company is outside your allowed company scope.');
        if(!$this->pdo->query('SELECT 1 FROM companies WHERE id='.(int)$companyId)->fetchColumn())throw new InvalidArgumentException('Company was not found.');
    }
    private function positiveId(mixed $value,string $label):int{$id=filter_var($value,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException($label.' must be a positive ID.');return$id;}
    private function normalizeDecimal(string $value):string{[$whole,$fraction]=array_pad(explode('.',$value,2),2,'');$whole=ltrim($whole,'0');if($whole==='')$whole='0';$fraction=rtrim($fraction,'0');return$whole.($fraction!==''?'.'.$fraction:'');}
    private function compareDecimals(string $left,string $right):int{$left=$this->normalizeDecimal($left);$right=$this->normalizeDecimal($right);[$li,$lf]=array_pad(explode('.',$left,2),2,'');[$ri,$rf]=array_pad(explode('.',$right,2),2,'');if(strlen($li)!==strlen($ri))return strlen($li)<=>strlen($ri);$integer=strcmp($li,$ri);if($integer!==0)return$integer<=>0;$scale=max(strlen($lf),strlen($rf));return strcmp(str_pad($lf,$scale,'0'),str_pad($rf,$scale,'0'))<=>0;}
    private function audit(int $company,int $actor,int $record,string $model,string $event,?array $old,array $new,string $remote):void{$ip=filter_var($remote,FILTER_VALIDATE_IP)?inet_pton($remote):null;$q=$this->pdo->prepare('INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) VALUES (:company,:user,:event,:model,:record,:old_values,:new_values,:ip)');$q->bindValue(':company',$company,PDO::PARAM_INT);$q->bindValue(':user',$actor,PDO::PARAM_INT);$q->bindValue(':event',$event);$q->bindValue(':model',$model);$q->bindValue(':record',$record,PDO::PARAM_INT);$q->bindValue(':old_values',$old===null?null:json_encode($old,JSON_THROW_ON_ERROR),$old===null?PDO::PARAM_NULL:PDO::PARAM_STR);$q->bindValue(':new_values',json_encode($new,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));$q->bindValue(':ip',$ip,$ip===null?PDO::PARAM_NULL:PDO::PARAM_LOB);$q->execute();}
}
