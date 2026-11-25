<?php
declare(strict_types=1);

namespace Erp\Modules\Contracts;

use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\UserContext;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Throwable;

final class ContractRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function options(UserContext $user, Authorization $auth, mixed $rawCompanyId): array
    {
        $companyId = $this->company($user, $auth, $rawCompanyId, false);
        $contacts = $this->pdo->prepare('SELECT id,name,email,customer,supplier FROM contacts WHERE active=1 AND (company_id IS NULL OR company_id=:company) ORDER BY name,id LIMIT 2000');
        $contacts->execute(['company' => $companyId]);
        $warehouses = $this->pdo->prepare('SELECT id,name,code FROM warehouses WHERE company_id=:company AND active=1 ORDER BY name,id');
        $warehouses->execute(['company' => $companyId]);
        return ['company_id'=>$companyId,'contacts'=>$contacts->fetchAll(),'warehouses'=>$warehouses->fetchAll()];
    }

    public function search(UserContext $user, Authorization $auth, array $filters): array
    {
        $companyId = $this->company($user, $auth, $filters['company_id'] ?? null, false);
        $params = ['company'=>$companyId];
        $where = ['ct.company_id=:company'];
        $kind = trim((string)($filters['contract_type'] ?? 'all'));
        if ($kind !== 'all') {
            if (!in_array($kind, ['customer','supplier','depo_partner'], true)) throw new InvalidArgumentException('Select a valid Contract type.');
            $where[]='ct.contract_type=:kind'; $params['kind']=$kind;
        }
        if (($filters['partner_contact_id'] ?? '') !== '') {
            $where[]='ct.partner_contact_id=:partner'; $params['partner']=$this->positiveId($filters['partner_contact_id'],'Contact');
        }
        $queryText=trim((string)($filters['q']??''));
        if ($queryText!=='') {
            if (mb_strlen($queryText)>190) throw new InvalidArgumentException('Contract search is too long.');
            $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$queryText).'%';
            $where[]='(ct.name LIKE :name_q ESCAPE \'!\' OR ct.reference LIKE :reference_q ESCAPE \'!\' OR p.name LIKE :partner_q ESCAPE \'!\')';
            $params+=['name_q'=>$like,'reference_q'=>$like,'partner_q'=>$like];
        }
        $company=$this->pdo->prepare('SELECT timezone FROM companies WHERE id=:company AND active=1');
        $company->execute(['company'=>$companyId]); $timezone=(string)($company->fetchColumn()?:'UTC');
        try {$today=(new DateTimeImmutable('now',new DateTimeZone($timezone)))->format('Y-m-d');}
        catch (\Throwable) {$today=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d');}
        $status=trim((string)($filters['status']??'all'));
        if ($status!=='all') {
            if (!in_array($status,['draft','scheduled','active','expired','cancelled'],true)) throw new InvalidArgumentException('Select a valid Contract status.');
            if ($status==='draft'||$status==='cancelled') {$where[]='ct.state=:status';$params['status']=$status;}
            elseif ($status==='scheduled') {$where[]="ct.state='active' AND ct.start_date>:today";$params['today']=$today;}
            elseif ($status==='expired') {$where[]="ct.state='active' AND ct.end_date IS NOT NULL AND ct.end_date<:today";$params['today']=$today;}
            else {$where[]="ct.state='active' AND ct.start_date<=:today_start AND (ct.end_date IS NULL OR ct.end_date>=:today_end)";$params['today_start']=$today;$params['today_end']=$today;}
        }
        $clause=implode(' AND ',$where);
        $count=$this->pdo->prepare('SELECT COUNT(*) FROM contracts ct JOIN contacts p ON p.id=ct.partner_contact_id WHERE '.$clause);
        $count->execute($params); $total=(int)$count->fetchColumn();
        $limit=max(1,min(100,(int)($filters['limit']??100))); $offset=max(0,min(1000000,(int)($filters['offset']??0)));
        $query=$this->pdo->prepare('SELECT ct.id,ct.company_id,ct.name,ct.reference,ct.contract_type,ct.partner_contact_id,p.name AS partner_name,ct.warehouse_id,w.name AS warehouse_name,ct.start_date,ct.end_date,ct.terms,ct.state,co.timezone FROM contracts ct JOIN contacts p ON p.id=ct.partner_contact_id JOIN companies co ON co.id=ct.company_id LEFT JOIN warehouses w ON w.id=ct.warehouse_id WHERE '.$clause.' ORDER BY ct.start_date DESC,ct.id DESC LIMIT :limit OFFSET :offset');
        foreach($params as $key=>$value) $query->bindValue(':'.$key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
        $query->bindValue(':limit',$limit,PDO::PARAM_INT); $query->bindValue(':offset',$offset,PDO::PARAM_INT); $query->execute();
        $rows=$query->fetchAll(); foreach($rows as &$row){$row['status']=$this->displayStatus($row,$today);$row['id']=(int)$row['id'];$row['company_id']=(int)$row['company_id'];$row['partner_contact_id']=(int)$row['partner_contact_id'];$row['warehouse_id']=$row['warehouse_id']===null?null:(int)$row['warehouse_id'];} unset($row);
        return ['company_id'=>$companyId,'records'=>$rows,'total'=>$total,'limit'=>$limit,'offset'=>$offset];
    }

    public function find(int $id, UserContext $user, Authorization $auth): ?array
    {
        $query=$this->pdo->prepare('SELECT ct.id,ct.company_id,ct.name,ct.reference,ct.contract_type,ct.partner_contact_id,p.name AS partner_name,p.email AS partner_email,ct.warehouse_id,w.name AS warehouse_name,ct.start_date,ct.end_date,ct.terms,ct.state,co.timezone FROM contracts ct JOIN contacts p ON p.id=ct.partner_contact_id JOIN companies co ON co.id=ct.company_id LEFT JOIN warehouses w ON w.id=ct.warehouse_id WHERE ct.id=:id LIMIT 1');
        $query->execute(['id'=>$id]); $row=$query->fetch(); if(!$row||!$auth->canAccessCompany((int)$row['company_id']))return null;
        if(!$auth->allowsForCompany('contracts.read',(int)$row['company_id'])&&!$auth->allowsForCompany('contracts.write',(int)$row['company_id']))throw new AccessDeniedException('You do not have Contract read access for this company.');
        $today=$this->today((string)$row['timezone']); $row['status']=$this->displayStatus($row,$today);
        foreach(['id','company_id','partner_contact_id','warehouse_id'] as $field)if($row[$field]!==null)$row[$field]=(int)$row[$field];
        return $row;
    }

    public function save(?int $id,array $input,UserContext $user,Authorization $auth,int $actorId,string $ip):array
    {
        $unknown=array_diff(array_keys($input),['company_id','name','reference','contract_type','partner_contact_id','warehouse_id','start_date','end_date','terms','state']);
        if($unknown)throw new InvalidArgumentException('Unsupported Contract fields: '.implode(', ',$unknown));
        $companyId=$this->company($user,$auth,$input['company_id']??null,true);
        $name=trim((string)($input['name']??''));if($name===''||mb_strlen($name)>190)throw new InvalidArgumentException('Contract name is required and must be 190 characters or fewer.');
        $reference=$this->text($input['reference']??null,120,'Reference');
        $type=(string)($input['contract_type']??'');if(!in_array($type,['customer','supplier','depo_partner'],true))throw new InvalidArgumentException('Select a Customer, Supplier, or DEPO Partner Contract type.');
        $partnerId=$this->positiveId($input['partner_contact_id']??null,'Contact');
        $contact=$this->pdo->prepare('SELECT company_id,customer,supplier,active FROM contacts WHERE id=:id LIMIT 1');$contact->execute(['id'=>$partnerId]);$partner=$contact->fetch();
        if(!$partner||!(int)$partner['active']||($partner['company_id']!==null&&(int)$partner['company_id']!==$companyId))throw new InvalidArgumentException('Select an active Contact available to this company.');
        if($type==='customer'&&!(int)$partner['customer'])throw new InvalidArgumentException('Customer Contracts require a Contact marked as a Customer.');
        if($type==='supplier'&&!(int)$partner['supplier'])throw new InvalidArgumentException('Supplier Contracts require a Contact marked as a Supplier.');
        $warehouseId=$this->nullableId($input['warehouse_id']??null,'Warehouse');
        if($warehouseId!==null){$warehouse=$this->pdo->prepare('SELECT 1 FROM warehouses WHERE id=:id AND company_id=:company AND active=1');$warehouse->execute(['id'=>$warehouseId,'company'=>$companyId]);if(!$warehouse->fetchColumn())throw new InvalidArgumentException('Select an active DEPO warehouse in this company.');}
        $start=$this->date($input['start_date']??null,'Start Date',true);$end=$this->date($input['end_date']??null,'End Date',false);
        if($end!==null&&$end<$start)throw new InvalidArgumentException('End Date cannot be earlier than Start Date.');
        $terms=$this->text($input['terms']??null,20000,'Terms');$state=(string)($input['state']??'draft');if(!in_array($state,['draft','active','cancelled'],true))throw new InvalidArgumentException('Select Draft, Active, or Cancelled.');
        $this->pdo->beginTransaction();
        try{
            $old=null;
            if($id!==null){$lock=$this->pdo->prepare('SELECT * FROM contracts WHERE id=:id LIMIT 1 FOR UPDATE');$lock->execute(['id'=>$id]);$old=$lock->fetch();if(!$old||(int)$old['company_id']!==$companyId)throw new InvalidArgumentException('Contract was not found in this company.');}
            $values=['company'=>$companyId,'name'=>$name,'reference'=>$reference,'type'=>$type,'partner'=>$partnerId,'warehouse'=>$warehouseId,'start'=>$start,'end'=>$end,'terms'=>$terms,'state'=>$state,'actor'=>$actorId];
            if($id===null){$this->pdo->prepare('INSERT INTO contracts (company_id,name,reference,contract_type,partner_contact_id,warehouse_id,start_date,end_date,terms,state,created_by,updated_by) VALUES (:company,:name,:reference,:type,:partner,:warehouse,:start,:end,:terms,:state,:creator,:actor)')->execute($values+['creator'=>$actorId]);$id=(int)$this->pdo->lastInsertId();$event='contract_created';}
            else{$this->pdo->prepare('UPDATE contracts SET name=:name,reference=:reference,contract_type=:type,partner_contact_id=:partner,warehouse_id=:warehouse,start_date=:start,end_date=:end,terms=:terms,state=:state,updated_by=:actor WHERE id=:id AND company_id=:company')->execute($values+['id'=>$id]);$event='contract_updated';}
            $oldValues=$old?['name'=>$old['name'],'reference'=>$old['reference'],'state'=>$old['state'],'partner_contact_id'=>(int)$old['partner_contact_id'],'start_date'=>$old['start_date'],'end_date'=>$old['end_date']]:null;
            $newValues=['name'=>$name,'reference'=>$reference,'contract_type'=>$type,'partner_contact_id'=>$partnerId,'warehouse_id'=>$warehouseId,'start_date'=>$start,'end_date'=>$end,'state'=>$state];
            $this->audit($companyId,$actorId,$id,$event,$oldValues,$newValues,$ip);$this->pdo->commit();
        }catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
        return $this->find((int)$id,$user,$auth)??throw new InvalidArgumentException('Saved Contract could not be reloaded.');
    }

    private function company(UserContext $user,Authorization $auth,mixed $raw,bool $write):int
    {
        $id=$this->positiveId($raw??$user->defaultCompanyId,'Company');
        if(!$auth->canAccessCompany($id))throw new InvalidArgumentException('Selected company is not available to this account.');
        $permission=$write?'contracts.write':'contracts.read';
        if(!$auth->allowsForCompany($permission,$id))throw new AccessDeniedException('You do not have Contract '.($write?'write':'read').' access for this company.');
        $active=$this->pdo->prepare('SELECT 1 FROM companies WHERE id=:id AND active=1');$active->execute(['id'=>$id]);if(!$active->fetchColumn())throw new InvalidArgumentException('Select an active company.');
        return $id;
    }
    private function displayStatus(array $row,string $today):string
    {
        if($row['state']==='cancelled')return 'cancelled';if($row['state']==='draft')return 'draft';
        if((string)$row['start_date']>$today)return 'scheduled';if($row['end_date']!==null&&(string)$row['end_date']<$today)return 'expired';return 'active';
    }
    public function compatibleForOrder(int $companyId,int $partnerId,string $type):?array
    {
        if(!in_array($type,['customer','supplier'],true))throw new InvalidArgumentException('Select a valid order Contract type.');
        $company=$this->pdo->prepare('SELECT timezone FROM companies WHERE id=:company AND active=1');$company->execute(['company'=>$companyId]);$timezone=(string)($company->fetchColumn()?:'UTC');$today=$this->today($timezone);
        $query=$this->pdo->prepare("SELECT id,name,reference FROM contracts WHERE company_id=:company AND partner_contact_id=:partner AND contract_type=:type AND state='active' AND start_date<=:today_start AND (end_date IS NULL OR end_date>=:today_end) ORDER BY start_date DESC,id DESC LIMIT 1");$query->execute(['company'=>$companyId,'partner'=>$partnerId,'type'=>$type,'today_start'=>$today,'today_end'=>$today]);$contract=$query->fetch();return$contract?:null;
    }
    public function orderOptions(int $companyId,string $type):array
    {
        if(!in_array($type,['customer','supplier'],true))throw new InvalidArgumentException('Select a valid order Contract type.');
        $company=$this->pdo->prepare('SELECT timezone FROM companies WHERE id=:company AND active=1');$company->execute(['company'=>$companyId]);$timezone=(string)($company->fetchColumn()?:'UTC');$today=$this->today($timezone);
        $query=$this->pdo->prepare("SELECT id,name,reference,partner_contact_id FROM contracts WHERE company_id=:company AND contract_type=:type AND state='active' AND start_date<=:today_start AND (end_date IS NULL OR end_date>=:today_end) ORDER BY name,id");$query->execute(['company'=>$companyId,'type'=>$type,'today_start'=>$today,'today_end'=>$today]);return$query->fetchAll();
    }
    public function matchesOrder(int $id,int $companyId,int $partnerId,string $type):bool
    {
        $query=$this->pdo->prepare('SELECT 1 FROM contracts WHERE id=:id AND company_id=:company AND partner_contact_id=:partner AND contract_type=:type LIMIT 1');$query->execute(['id'=>$id,'company'=>$companyId,'partner'=>$partnerId,'type'=>$type]);return(bool)$query->fetchColumn();
    }
    private function today(string $timezone):string{try{return(new DateTimeImmutable('now',new DateTimeZone($timezone)))->format('Y-m-d');}catch(\Throwable){return(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d');}}
    private function date(mixed $raw,string $label,bool $required):?string
    {
        if($raw===null||$raw===''){if($required)throw new InvalidArgumentException($label.' is required.');return null;}
        $value=(string)$raw;$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);$errors=DateTimeImmutable::getLastErrors();
        if(!$date||($errors!==false&&($errors['warning_count']>0||$errors['error_count']>0))||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException($label.' must be a valid YYYY-MM-DD date.');return$value;
    }
    private function text(mixed $raw,int $max,string $label):?string{$value=trim((string)($raw??''));if(mb_strlen($value)>$max)throw new InvalidArgumentException($label.' must be '.$max.' characters or fewer.');return$value===''?null:$value;}
    private function positiveId(mixed $value,string $label):int{$id=filter_var($value,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException($label.' must be selected.');return$id;}
    private function nullableId(mixed $value,string $label):?int{return$value===null||$value===''?null:$this->positiveId($value,$label);}
    private function audit(int $companyId,int $actorId,int $id,string $event,?array $old,array $new,string $ip):void
    {
        $q=$this->pdo->prepare('INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) VALUES (:company,:actor,:event,\'contract\',:record,:old,:new,:ip)');$binary=@inet_pton($ip);$q->execute(['company'=>$companyId,'actor'=>$actorId,'event'=>$event,'record'=>$id,'old'=>$old===null?null:json_encode($old,JSON_THROW_ON_ERROR),'new'=>json_encode($new,JSON_THROW_ON_ERROR),'ip'=>$binary===false?null:$binary]);
    }
}
