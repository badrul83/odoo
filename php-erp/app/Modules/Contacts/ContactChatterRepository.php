<?php
declare(strict_types=1);

namespace Erp\Modules\Contacts;

use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\UserContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Throwable;

final class ContactChatterRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function timeline(int $contactId, array $filters, UserContext $user, Authorization $auth): array
    {
        if (!$auth->allows('contacts.read')) throw new AccessDeniedException('You do not have permission to read this Contact.');
        $companyId = $this->companyId($filters['company_id'] ?? $user->defaultCompanyId, $auth);
        if (!$auth->allowsForCompany('contacts.read', $companyId)) throw new AccessDeniedException('You do not have permission to read Contacts in this company.');
        $this->assertContact($contactId, $companyId);
        $limit = max(1, min(100, (int)($filters['limit'] ?? 50)));
        $messageOffset = max(0, min(1000000, (int)($filters['message_offset'] ?? 0)));
        $activityOffset = max(0, min(1000000, (int)($filters['activity_offset'] ?? 0)));

        $messageCount = $this->pdo->prepare('SELECT COUNT(*) FROM contact_messages WHERE company_id=:company AND contact_id=:contact');
        $messageCount->execute(['company'=>$companyId,'contact'=>$contactId]);
        $messages = $this->pdo->prepare('SELECT m.id,m.message_type,m.body,m.created_at,m.author_user_id,author.name AS author_name FROM contact_messages m JOIN users au ON au.id=m.author_user_id JOIN contacts author ON author.id=au.contact_id WHERE m.company_id=:company AND m.contact_id=:contact ORDER BY m.id DESC LIMIT :limit OFFSET :offset');
        $messages->bindValue(':company',$companyId,PDO::PARAM_INT);$messages->bindValue(':contact',$contactId,PDO::PARAM_INT);$messages->bindValue(':limit',$limit,PDO::PARAM_INT);$messages->bindValue(':offset',$messageOffset,PDO::PARAM_INT);$messages->execute();

        $activityCount = $this->pdo->prepare('SELECT COUNT(*) FROM contact_activities WHERE company_id=:company AND contact_id=:contact');
        $activityCount->execute(['company'=>$companyId,'contact'=>$contactId]);
        $activities = $this->pdo->prepare("SELECT a.id,a.activity_type,a.summary,a.due_at,a.state,a.created_at,a.assigned_user_id,assignee.name AS assigned_name,creator.name AS creator_name FROM contact_activities a JOIN users au ON au.id=a.assigned_user_id JOIN contacts assignee ON assignee.id=au.contact_id JOIN users cu ON cu.id=a.created_by JOIN contacts creator ON creator.id=cu.contact_id WHERE a.company_id=:company AND a.contact_id=:contact ORDER BY a.created_at DESC,a.id DESC LIMIT :limit OFFSET :offset");
        $activities->bindValue(':company',$companyId,PDO::PARAM_INT);$activities->bindValue(':contact',$contactId,PDO::PARAM_INT);$activities->bindValue(':limit',$limit,PDO::PARAM_INT);$activities->bindValue(':offset',$activityOffset,PDO::PARAM_INT);$activities->execute();
        $users = $this->pdo->prepare("SELECT u.id,c.name FROM users u JOIN contacts c ON c.id=u.contact_id JOIN user_company_access a ON a.user_id=u.id AND a.company_id=:company WHERE u.status IN ('active','confirmed') ORDER BY c.name,u.id");
        $users->execute(['company'=>$companyId]);
        return ['company_id'=>$companyId,'messages'=>array_reverse($messages->fetchAll()),'message_total'=>(int)$messageCount->fetchColumn(),'message_offset'=>$messageOffset,'activities'=>$activities->fetchAll(),'activity_total'=>(int)$activityCount->fetchColumn(),'activity_offset'=>$activityOffset,'assignees'=>array_map(static fn(array $row):array=>['id'=>(int)$row['id'],'name'=>$row['name']],$users->fetchAll())];
    }

    public function addMessage(int $contactId, array $input, UserContext $user, Authorization $auth, string $ip): array
    {
        if (!$auth->allows('contacts.write')) throw new AccessDeniedException('You do not have permission to add a Contact message.');
        $unknown=array_diff(array_keys($input),['company_id','message_type','body']);if($unknown)throw new InvalidArgumentException('Unsupported message fields: '.implode(', ',$unknown));
        $companyId=$this->companyId($input['company_id']??$user->defaultCompanyId,$auth);if(!$auth->allowsForCompany('contacts.write',$companyId))throw new AccessDeniedException('You do not have permission to add Contact messages in this company.');$this->assertContact($contactId,$companyId);
        $type=(string)($input['message_type']??'message');if(!in_array($type,['message','note'],true))throw new InvalidArgumentException('Choose Send message or Log note.');
        $body=trim((string)($input['body']??''));if($body==='')throw new InvalidArgumentException('Message text is required.');if(mb_strlen($body)>10000)throw new InvalidArgumentException('Message text cannot exceed 10,000 characters.');
        $this->pdo->beginTransaction();
        try{$this->pdo->prepare('INSERT INTO contact_messages (company_id,contact_id,author_user_id,message_type,body) VALUES (:company,:contact,:author,:type,:body)')->execute(['company'=>$companyId,'contact'=>$contactId,'author'=>$user->id,'type'=>$type,'body'=>$body]);$id=(int)$this->pdo->lastInsertId();$this->audit($companyId,$user->id,$id,'contact.message',$type==='note'?'note_logged':'message_sent',null,['contact_id'=>$contactId,'message_type'=>$type,'body'=>$body],$ip);$this->pdo->commit();}
        catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$error;}
        return $this->message($id);
    }

    public function addActivity(int $contactId, array $input, UserContext $user, Authorization $auth, string $ip): array
    {
        if (!$auth->allows('contacts.write')) throw new AccessDeniedException('You do not have permission to schedule a Contact activity.');
        $unknown=array_diff(array_keys($input),['company_id','activity_type','summary','due_at','assigned_user_id']);if($unknown)throw new InvalidArgumentException('Unsupported activity fields: '.implode(', ',$unknown));
        $companyId=$this->companyId($input['company_id']??$user->defaultCompanyId,$auth);if(!$auth->allowsForCompany('contacts.write',$companyId))throw new AccessDeniedException('You do not have permission to schedule Contact activities in this company.');$this->assertContact($contactId,$companyId);
        $type=(string)($input['activity_type']??'todo');if(!in_array($type,['todo','call','meeting','email','upload','other'],true))throw new InvalidArgumentException('Choose a valid activity type.');
        $summary=trim((string)($input['summary']??''));if($summary==='')throw new InvalidArgumentException('Activity summary is required.');if(mb_strlen($summary)>255)throw new InvalidArgumentException('Activity summary cannot exceed 255 characters.');
        $dueAt=$this->dateTime($input['due_at']??null);$assignee=$this->positiveId($input['assigned_user_id']??$user->id,'Assigned user');$this->assertCompanyUser($assignee,$companyId);
        $this->pdo->beginTransaction();
        try{$this->pdo->prepare("INSERT INTO contact_activities (company_id,contact_id,activity_type,summary,due_at,assigned_user_id,state,created_by) VALUES (:company,:contact,:type,:summary,:due,:assignee,'planned',:creator)")->execute(['company'=>$companyId,'contact'=>$contactId,'type'=>$type,'summary'=>$summary,'due'=>$dueAt,'assignee'=>$assignee,'creator'=>$user->id]);$id=(int)$this->pdo->lastInsertId();$this->audit($companyId,$user->id,$id,'contact.activity','scheduled',null,['contact_id'=>$contactId,'activity_type'=>$type,'summary'=>$summary,'due_at'=>$dueAt,'assigned_user_id'=>$assignee,'state'=>'planned'],$ip);$this->pdo->commit();}
        catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$error;}
        return $this->activity($id);
    }

    public function completeActivity(int $contactId, int $activityId, array $input, UserContext $user, Authorization $auth, string $ip): array
    {
        if (!$auth->allows('contacts.write')) throw new AccessDeniedException('You do not have permission to update Contact activities.');
        $unknown=array_diff(array_keys($input),['company_id']);if($unknown)throw new InvalidArgumentException('Unsupported activity fields: '.implode(', ',$unknown));
        $companyId=$this->companyId($input['company_id']??$user->defaultCompanyId,$auth);if(!$auth->allowsForCompany('contacts.write',$companyId))throw new AccessDeniedException('You do not have permission to update Contact activities in this company.');$this->assertContact($contactId,$companyId);
        $this->pdo->beginTransaction();
        try{$query=$this->pdo->prepare('SELECT id,state FROM contact_activities WHERE id=:id AND contact_id=:contact AND company_id=:company FOR UPDATE');$query->execute(['id'=>$activityId,'contact'=>$contactId,'company'=>$companyId]);$current=$query->fetch();if(!$current)throw new InvalidArgumentException('Contact activity was not found in this company.');if($current['state']!=='planned')throw new InvalidArgumentException('Only planned Contact activities can be marked done.');$this->pdo->prepare("UPDATE contact_activities SET state='done',completed_by=:user,completed_at=UTC_TIMESTAMP() WHERE id=:id AND state='planned'")->execute(['user'=>$user->id,'id'=>$activityId]);$this->audit($companyId,$user->id,$activityId,'contact.activity','completed',['state'=>'planned'],['state'=>'done'],$ip);$this->pdo->commit();}
        catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$error;}
        return $this->activity($activityId);
    }

    private function assertContact(int $contactId,int $companyId):void
    {
        $query=$this->pdo->prepare('SELECT company_id FROM contacts WHERE id=:id');$query->execute(['id'=>$contactId]);$contact=$query->fetch();if(!$contact)throw new InvalidArgumentException('Contact was not found.');if($contact['company_id']!==null&&(int)$contact['company_id']!==$companyId)throw new InvalidArgumentException('Contact belongs to a different company.');
    }
    private function assertCompanyUser(int $userId,int $companyId):void{$q=$this->pdo->prepare("SELECT 1 FROM users u JOIN user_company_access a ON a.user_id=u.id WHERE u.id=:user AND a.company_id=:company AND u.status IN ('active','confirmed') LIMIT 1");$q->execute(['user'=>$userId,'company'=>$companyId]);if(!$q->fetchColumn())throw new InvalidArgumentException('Assigned user must be active and have access to this company.');}
    private function companyId(mixed $raw,Authorization $auth):int{$id=$this->positiveId($raw,'Company');if(!$auth->canAccessCompany($id))throw new InvalidArgumentException('Company is outside your allowed company scope.');$q=$this->pdo->prepare('SELECT 1 FROM companies WHERE id=:id AND active=1');$q->execute(['id'=>$id]);if(!$q->fetchColumn())throw new InvalidArgumentException('Company was not found or is archived.');return$id;}
    private function positiveId(mixed $value,string $label):int{$id=filter_var($value,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException($label.' must be a positive ID.');return$id;}
    private function dateTime(mixed $value):?string{if($value===null||$value==='')return null;if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$value))throw new InvalidArgumentException('Activity due date must use YYYY-MM-DD.');$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException('Activity due date is invalid.');return$value.' 00:00:00';}
    private function message(int $id):array{$q=$this->pdo->prepare('SELECT m.id,m.company_id,m.contact_id,m.author_user_id,m.message_type,m.body,m.created_at,c.name AS author_name FROM contact_messages m JOIN users u ON u.id=m.author_user_id JOIN contacts c ON c.id=u.contact_id WHERE m.id=:id');$q->execute(['id'=>$id]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Message was saved but could not be reloaded.');foreach(['id','company_id','contact_id','author_user_id']as$key)$r[$key]=(int)$r[$key];return$r;}
    private function activity(int $id):array{$q=$this->pdo->prepare('SELECT a.id,a.company_id,a.contact_id,a.activity_type,a.summary,a.due_at,a.assigned_user_id,a.state,a.created_by,a.completed_by,a.created_at,a.completed_at,assigned.name AS assigned_name FROM contact_activities a JOIN users u ON u.id=a.assigned_user_id JOIN contacts assigned ON assigned.id=u.contact_id WHERE a.id=:id');$q->execute(['id'=>$id]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Activity was saved but could not be reloaded.');foreach(['id','company_id','contact_id','assigned_user_id','created_by']as$key)$r[$key]=(int)$r[$key];if($r['completed_by']!==null)$r['completed_by']=(int)$r['completed_by'];return$r;}
    private function audit(int $company,int $actor,int $record,string $model,string $event,?array $old,array $new,string $remote):void{$ip=filter_var($remote,FILTER_VALIDATE_IP)?inet_pton($remote):null;$q=$this->pdo->prepare('INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) VALUES (:company,:user,:event,:model,:record,:old_values,:new_values,:ip)');$q->bindValue(':company',$company,PDO::PARAM_INT);$q->bindValue(':user',$actor,PDO::PARAM_INT);$q->bindValue(':event',$event);$q->bindValue(':model',$model);$q->bindValue(':record',$record,PDO::PARAM_INT);$q->bindValue(':old_values',$old===null?null:json_encode($old,JSON_THROW_ON_ERROR),$old===null?PDO::PARAM_NULL:PDO::PARAM_STR);$q->bindValue(':new_values',json_encode($new,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));$q->bindValue(':ip',$ip,$ip===null?PDO::PARAM_NULL:PDO::PARAM_LOB);$q->execute();}
}
