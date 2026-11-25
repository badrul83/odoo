<?php
declare(strict_types=1);

namespace Erp\Modules\Appointments;

use DateTimeImmutable;
use DateTimeZone;
use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\UserContext;
use Erp\Modules\Calendar\CalendarRecurrence;
use InvalidArgumentException;
use PDO;
use Throwable;

final class AppointmentRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function options(UserContext $user, Authorization $auth, mixed $rawCompany): array
    {
        $companies = $this->companyRows($user, $auth);
        $requestedCompany = $rawCompany ?? $user->defaultCompanyId ?? ($companies[0]['id'] ?? null);
        $companyId = $this->company($requestedCompany, $auth);
        $this->requireCompanyPermission($auth, 'appointments.read', $companyId);
        $users = $this->pdo->prepare("SELECT u.id,c.name FROM users u JOIN contacts c ON c.id=u.contact_id JOIN user_company_access a ON a.user_id=u.id AND a.company_id=:company WHERE u.status IN ('active','confirmed') ORDER BY c.name,u.id");
        $users->execute(['company' => $companyId]);
        $resources = $this->pdo->prepare('SELECT id,name,capacity FROM appointment_resources WHERE company_id=:company AND active=1 ORDER BY name,id');
        $resources->execute(['company' => $companyId]);
        $contacts = [];
        $canReadContacts = $auth->allowsForCompany('contacts.read', $companyId);
        if ($canReadContacts) {
            $q = $this->pdo->prepare('SELECT id,name,email,phone FROM contacts WHERE active=1 AND (company_id=:company OR company_id IS NULL) ORDER BY name,id LIMIT 500');
            $q->execute(['company' => $companyId]); $contacts = $q->fetchAll();
        }
        return ['company_id' => $companyId, 'companies' => $companies, 'users' => $users->fetchAll(), 'resources' => $resources->fetchAll(), 'contacts' => $contacts, 'can_read_contacts' => $canReadContacts, 'can_read_crm' => $auth->allowsForCompany('crm.read', $companyId)];
    }

    public function types(UserContext $user, Authorization $auth, array $filters): array
    {
        $company = $this->company($filters['company_id'] ?? $user->defaultCompanyId, $auth);
        $this->requireCompanyPermission($auth, 'appointments.read', $company);
        $types = $this->pdo->prepare("SELECT t.*, (SELECT COUNT(*) FROM appointment_bookings b WHERE b.appointment_type_id=t.id AND b.company_id=t.company_id AND b.status='booked' AND b.starts_at_utc>=UTC_TIMESTAMP()) AS upcoming_count, (SELECT COUNT(*) FROM appointment_bookings b WHERE b.appointment_type_id=t.id AND b.company_id=t.company_id) AS total_count, (SELECT COUNT(*) FROM appointment_type_users x WHERE x.appointment_type_id=t.id AND x.company_id=t.company_id) AS user_count, (SELECT COUNT(*) FROM appointment_type_resources x WHERE x.appointment_type_id=t.id AND x.company_id=t.company_id) AS resource_count FROM appointment_types t WHERE t.company_id=:company AND t.active=1 ORDER BY t.name,t.id");
        $types->execute(['company' => $company]);
        $rows = array_map([$this, 'castType'], $types->fetchAll());
        foreach ($rows as &$row) { $row['public_url'] = '/book/' . $row['public_token']; unset($row['public_token']); }
        unset($row);
        return ['company_id' => $company, 'types' => $rows];
    }

    public function type(int $id, UserContext $user, Authorization $auth): array
    {
        $type = $this->typeForUser($id, $auth, false);
        if (!$type) throw new InvalidArgumentException('Appointment type was not found in an allowed company.');
        return $this->typeDetails($type);
    }

    public function availableSlots(int $id, string $date, int $guestCount, Authorization $auth): array
    {
        $type = $this->typeForUser($id, $auth, false);
        if (!$type) throw new InvalidArgumentException('Appointment type was not found in an allowed company.');
        $guestCount = $this->integer($guestCount, 'Guest count', 1, (int)$type['capacity_per_slot']);
        return ['timezone' => $type['timezone'], 'duration_minutes' => (int)$type['duration_minutes'], 'slots' => $this->slots($type, $date, $guestCount)];
    }

    public function saveType(array $input, ?int $id, UserContext $user, Authorization $auth, string $ip): array
    {
        $companyId = $this->company($input['company_id'] ?? $user->defaultCompanyId, $auth);
        $this->requireCompanyPermission($auth, 'appointments.write', $companyId);
        $values = $this->prepareType($input, $companyId, $user, $auth);
        $creating = !$id;
        $this->pdo->beginTransaction();
        try {
            if ($id) {
                $lock = $this->pdo->prepare('SELECT * FROM appointment_types WHERE id=:id AND company_id=:company AND active=1 FOR UPDATE');
                $lock->execute(['id' => $id, 'company' => $companyId]); $current = $lock->fetch();
                if (!$current) throw new InvalidArgumentException('Appointment type was not found in this company.');
                $before = $this->auditTypeValues($this->typeDetails($current));
                $sql = 'UPDATE appointment_types SET name=:name,duration_minutes=:duration,slot_interval_minutes=:slot,prebooking_minutes=:notice,booking_horizon_days=:horizon,date_start=:date_start,date_end=:date_end,cancellation_cutoff_minutes=:cancel_cutoff,assignment_target=:target,assignment_method=:method,location_type=:location_type,location=:location,capacity_per_slot=:capacity,allow_guests=:guests,timezone=:timezone,intro_message=:intro,confirmation_message=:confirmation,published=:published,updated_by=:actor WHERE id=:id AND company_id=:company';
                $this->pdo->prepare($sql)->execute($this->typeSqlValues($values) + ['id' => $id, 'actor' => $user->id]);
            } else {
                $token = bin2hex(random_bytes(32));
                $sql = 'INSERT INTO appointment_types (company_id,name,public_token,duration_minutes,slot_interval_minutes,prebooking_minutes,booking_horizon_days,date_start,date_end,cancellation_cutoff_minutes,assignment_target,assignment_method,location_type,location,capacity_per_slot,allow_guests,timezone,intro_message,confirmation_message,published,created_by,updated_by) VALUES (:company,:name,:token,:duration,:slot,:notice,:horizon,:date_start,:date_end,:cancel_cutoff,:target,:method,:location_type,:location,:capacity,:guests,:timezone,:intro,:confirmation,:published,:created_by,:updated_by)';
                $this->pdo->prepare($sql)->execute($this->typeSqlValues($values) + ['token' => $token, 'created_by' => $user->id, 'updated_by' => $user->id]); $id = (int)$this->pdo->lastInsertId();
            }
            $this->replaceTypeLinks($id, $companyId, $values['target'], $values['users'], $values['resources'], $values['availability'], $values['questions']);
            $this->audit($companyId, $user->id, $id, 'appointment.type', $creating ? 'created' : 'updated', $creating ? null : $before, $this->auditTypeValues($values), $ip);
            $this->pdo->commit();
            $saved = $this->typeDetails($this->typeById($id, $companyId));
            return $saved;
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
    }

    public function resources(UserContext $user, Authorization $auth, int $companyId): array
    {
        $company = $this->company($companyId, $auth);
        $this->requireCompanyPermission($auth, 'appointments.read', $company);
        $q = $this->pdo->prepare('SELECT id,company_id,name,capacity,timezone,description,active FROM appointment_resources WHERE company_id=:company ORDER BY active DESC,name,id');
        $q->execute(['company' => $company]); return ['company_id' => $company, 'resources' => $q->fetchAll()];
    }

    public function saveResource(array $input, ?int $id, UserContext $user, Authorization $auth, string $ip): array
    {
        $company = $this->company($input['company_id'] ?? $user->defaultCompanyId, $auth);
        $this->requireCompanyPermission($auth, 'appointments.write', $company);
        $name = $this->text($input['name'] ?? '', 'Resource name', 120, true);
        $capacity = $this->integer($input['capacity'] ?? 1, 'Capacity', 1, 500);
        $timezone = $this->timezone($input['timezone'] ?? 'Asia/Dhaka');
        $description = $this->text($input['description'] ?? '', 'Description', 1000, false);
        if ($id) {
            $q = $this->pdo->prepare('SELECT * FROM appointment_resources WHERE id=:id AND company_id=:company'); $q->execute(['id' => $id, 'company' => $company]); $current = $q->fetch();
            if (!$current) throw new InvalidArgumentException('Resource was not found in this company.');
            $sql = 'UPDATE appointment_resources SET name=:name,capacity=:capacity,timezone=:timezone,description=:description,active=:active WHERE id=:id AND company_id=:company';
            $this->pdo->prepare($sql)->execute(['name'=>$name,'capacity'=>$capacity,'timezone'=>$timezone,'description'=>$description,'active'=>!empty($input['active'])?1:0,'id'=>$id,'company'=>$company]);
            $record = $id; $event = 'updated'; $before = ['name'=>$current['name'],'capacity'=>(int)$current['capacity'],'active'=>(int)$current['active']];
        } else {
            $q = $this->pdo->prepare('INSERT INTO appointment_resources (company_id,name,capacity,timezone,description,created_by) VALUES (:company,:name,:capacity,:timezone,:description,:creator)');
            $q->execute(['company'=>$company,'name'=>$name,'capacity'=>$capacity,'timezone'=>$timezone,'description'=>$description,'creator'=>$user->id]);
            $record=(int)$this->pdo->lastInsertId(); $event='created'; $before=null;
        }
        $this->audit($company,$user->id,$record,'appointment.resource',$event,$before,['name'=>$name,'capacity'=>$capacity,'timezone'=>$timezone,'active'=>!empty($input['active'])],$ip);
        $saved=$this->pdo->prepare('SELECT id,name,capacity,timezone,description,active FROM appointment_resources WHERE id=:id AND company_id=:company');$saved->execute(['id'=>$record,'company'=>$company]);return $saved->fetch();
    }

    public function bookings(UserContext $user, Authorization $auth, array $filters): array
    {
        $company=$this->company($filters['company_id']??$user->defaultCompanyId,$auth);$this->requireCompanyPermission($auth,'appointments.read',$company);$where=['b.company_id=:company'];$params=['company'=>$company];
        $status=(string)($filters['status']??'upcoming');
        if(!in_array($status,['upcoming','all','booked','cancelled','completed','no_show'],true))throw new InvalidArgumentException('Choose a valid booking status.');
        if($status==='upcoming'){$where[]="b.status='booked' AND b.starts_at_utc>=UTC_TIMESTAMP()";}elseif($status!=='all'){$where[]='b.status=:status';$params['status']=$status;}
        if(!empty($filters['booking_id'])){$where[]='b.id=:booking_id';$params['booking_id']=$this->positiveId($filters['booking_id'],'Booking');}
        if(!empty($filters['contact_id'])){if(!$auth->allowsForCompany('contacts.read',$company))throw new AccessDeniedException('Contact access is required to filter appointments in this company.');$where[]='b.contact_id=:contact';$params['contact']=$this->positiveId($filters['contact_id'],'Contact');}
        if(!empty($filters['q'])){$text=trim((string)$filters['q']);if(mb_strlen($text)>100)throw new InvalidArgumentException('Search must be 100 characters or fewer.');$like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$text).'%';$where[]='(b.booking_name LIKE :q_name ESCAPE \'!\' OR b.email LIKE :q_email ESCAPE \'!\' OR t.name LIKE :q_type ESCAPE \'!\' OR c.name LIKE :q_contact ESCAPE \'!\')';$params+=['q_name'=>$like,'q_email'=>$like,'q_type'=>$like,'q_contact'=>$like];}
        $clause=implode(' AND ',$where);
        $count=$this->pdo->prepare('SELECT COUNT(*) FROM appointment_bookings b JOIN appointment_types t ON t.id=b.appointment_type_id LEFT JOIN contacts c ON c.id=b.contact_id WHERE '.$clause);
        $count->execute($params);
        $total=(int)$count->fetchColumn();
        $limit=max(1,min(300,(int)($filters['limit']??300)));
        $offset=max(0,min(1000000,(int)($filters['offset']??0)));
        $query='SELECT b.id,b.company_id,b.appointment_type_id,t.name AS appointment_type,b.contact_id,c.name AS contact_name,b.booking_name,b.email,b.phone,b.assigned_user_id,uc.name AS assigned_user,b.resource_id,r.name AS resource,b.starts_at_utc,b.ends_at_utc,b.timezone,b.guest_count,b.status,b.source,b.notes,b.cancellation_reason FROM appointment_bookings b JOIN appointment_types t ON t.id=b.appointment_type_id LEFT JOIN contacts c ON c.id=b.contact_id LEFT JOIN users u ON u.id=b.assigned_user_id LEFT JOIN contacts uc ON uc.id=u.contact_id LEFT JOIN appointment_resources r ON r.id=b.resource_id WHERE '.$clause.' ORDER BY b.starts_at_utc DESC,b.id DESC LIMIT :limit OFFSET :offset';
        $statement=$this->pdo->prepare($query);
        foreach($params as $key=>$value)$statement->bindValue(':'.$key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
        $statement->bindValue(':limit',$limit,PDO::PARAM_INT);$statement->bindValue(':offset',$offset,PDO::PARAM_INT);$statement->execute();
        return ['company_id'=>$company,'bookings'=>$statement->fetchAll(),'total'=>$total,'limit'=>$limit,'offset'=>$offset];
    }

    public function booking(int $id, Authorization $auth, bool $allowWrite = false): array
    {
        $query=$this->pdo->prepare('SELECT b.*,t.name AS appointment_type,c.name AS contact_name,uc.name AS assigned_user,r.name AS resource FROM appointment_bookings b JOIN appointment_types t ON t.id=b.appointment_type_id LEFT JOIN contacts c ON c.id=b.contact_id LEFT JOIN users u ON u.id=b.assigned_user_id LEFT JOIN contacts uc ON uc.id=u.contact_id LEFT JOIN appointment_resources r ON r.id=b.resource_id WHERE b.id=:id');$query->execute(['id'=>$id]);$booking=$query->fetch();
        if(!$booking||!$auth->canAccessCompany((int)$booking['company_id']))throw new InvalidArgumentException('Booking was not found in an allowed company.');
        $companyId = (int)$booking['company_id'];
        if (!$auth->allowsForCompany('appointments.read', $companyId) && !($allowWrite && $auth->allowsForCompany('appointments.write', $companyId))) throw new AccessDeniedException('You do not have permission to read Appointments in this company.');
        $answers=$this->pdo->prepare('SELECT q.question,q.answer_type,q.is_required,a.answer_text FROM appointment_answers a JOIN appointment_questions q ON q.id=a.question_id WHERE a.booking_id=:booking AND a.company_id=:company ORDER BY q.sequence,q.id');$answers->execute(['booking'=>$id,'company'=>$booking['company_id']]);$booking['answers']=$answers->fetchAll();return $booking;
    }

    public function createInternalBooking(array $input, UserContext $user, Authorization $auth, string $ip): array
    {
        $typeId=$this->positiveId($input['appointment_type_id']??null,'Appointment type');$type=$this->typeForUser($typeId,$auth,false);if(!$type)throw new InvalidArgumentException('Appointment type is not available in an allowed company.');
        $requestedCompanyId = $this->positiveId($input['company_id'] ?? null, 'Company');
        if ($requestedCompanyId !== (int)$type['company_id']) throw new InvalidArgumentException('Booking company must match the selected appointment type company.');
        $this->requireCompanyPermission($auth, 'appointments.write', (int)$type['company_id']);
        return $this->createBooking($type,$input,$user->id,$ip,'internal',$auth);
    }

    public function updateBookingStatus(int $id, string $status, string $reason, UserContext $user, Authorization $auth, string $ip): array
    {
        if(!in_array($status,['cancelled','completed','no_show','booked'],true))throw new InvalidArgumentException('Choose a valid appointment status.');
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare('SELECT * FROM appointment_bookings WHERE id=:id FOR UPDATE');$q->execute(['id'=>$id]);$current=$q->fetch();
            if(!$current||!$auth->canAccessCompany((int)$current['company_id']))throw new InvalidArgumentException('Booking was not found in an allowed company.');
            $this->requireCompanyPermission($auth, 'appointments.write', (int)$current['company_id']);
            if($current['status']!=='booked'&&$status!==$current['status'])throw new InvalidArgumentException('Only booked appointments can change status.');
            if($status==='booked'&&$current['status']!=='booked')throw new InvalidArgumentException('A cancelled, completed or missed appointment cannot be reopened.');
            $reason=$this->text($reason,'Cancellation reason',500,false);
            $update=$this->pdo->prepare('UPDATE appointment_bookings SET status=:status,cancellation_reason=:reason,cancelled_by=:actor,cancelled_at=IF(:is_cancelled=1,UTC_TIMESTAMP(),NULL) WHERE id=:id');$update->execute(['status'=>$status,'reason'=>$status==='cancelled'?$reason:'','actor'=>$status==='cancelled'?$user->id:null,'is_cancelled'=>$status==='cancelled'?1:0,'id'=>$id]);
            $this->syncCalendarEvent(array_replace($current, ['status'=>$status,'cancellation_reason'=>$reason]));
            $this->audit((int)$current['company_id'],$user->id,$id,'appointment.booking','status_changed',['status'=>$current['status']],['status'=>$status,'reason'=>$reason],$ip);
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
        return $this->booking($id,$auth,true);
    }

    public function publicType(string $token): array
    {
        $type=$this->typeByToken($token);if(!$type||!(int)$type['published']||!(int)$type['active'])throw new InvalidArgumentException('This appointment link is not available.');
        $details=$this->typeDetails($type,true);$allowed=['name','duration_minutes','assignment_method','location_type','location','capacity_per_slot','allow_guests','timezone','intro_message','confirmation_message'];
        return ['type'=>array_intersect_key($details,array_flip($allowed)),'questions'=>$this->questions((int)$type['id'],(int)$type['company_id'])];
    }

    public function publicSlots(string $token, string $date, int $guestCount=1): array
    {
        $type=$this->typeByToken($token);if(!$type||!(int)$type['published']||!(int)$type['active'])throw new InvalidArgumentException('This appointment link is not available.');
        $guestCount=$this->integer($guestCount,'Guest count',1,(int)$type['capacity_per_slot']);
        $slots=$this->slots($type,$date,$guestCount,true);
        if($type['assignment_method']==='automatic')foreach($slots as &$slot){$slot['label']=explode(' · ',$slot['label'],2)[0];unset($slot['provider_key'],$slot['provider_name'],$slot['assigned_user_id'],$slot['resource_id']);}unset($slot);
        return ['timezone'=>$type['timezone'],'duration_minutes'=>(int)$type['duration_minutes'],'slots'=>$slots];
    }

    public function createPublicBooking(string $token, array $input, string $ip): array
    {
        $type=$this->typeByToken($token);if(!$type||!(int)$type['published']||!(int)$type['active'])throw new InvalidArgumentException('This appointment link is not available.');
        if(trim((string)($input['website']??''))!=='')throw new InvalidArgumentException('The booking request could not be accepted.');
        return $this->createBooking($type,$input,null,$ip,'public',null);
    }

    public function publicManageBooking(string $token): array
    {
        return $this->publicManageBookingRow($token);
    }

    public function publicRescheduleSlots(string $token, string $date): array
    {
        $booking = $this->publicManageBookingRow($token);
        if (!$booking['can_reschedule']) throw new InvalidArgumentException('The rescheduling window has closed. Please contact the company.');
        $type = $this->typeById((int)$booking['appointment_type_id'], (int)$booking['company_id']);
        if (!(int)$type['active'] || !(int)$type['published']) throw new InvalidArgumentException('This appointment type is no longer available for rescheduling.');
        $slots = $this->slots($type, $date, (int)$booking['guest_count'], true, (int)$booking['id']);
        $earliest = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp() + (int)$type['cancellation_cutoff_minutes'] * 60;
        $slots = array_values(array_filter($slots, static fn(array $slot): bool => (new DateTimeImmutable($slot['starts_at_utc'], new DateTimeZone('UTC')))->getTimestamp() >= $earliest));
        if ($type['assignment_method'] === 'automatic') foreach ($slots as &$slot) {
            $slot['label'] = explode(' · ', $slot['label'], 2)[0];
            unset($slot['provider_key'], $slot['provider_name'], $slot['assigned_user_id'], $slot['resource_id']);
        }
        unset($slot);
        return ['timezone' => $type['timezone'], 'duration_minutes' => (int)$type['duration_minutes'], 'slots' => $slots];
    }

    public function reschedulePublicBooking(string $token, array $input, string $ip): array
    {
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare("SELECT b.*,t.cancellation_cutoff_minutes,t.active AS type_active,t.published AS type_published FROM appointment_bookings b JOIN appointment_types t ON t.id=b.appointment_type_id AND t.company_id=b.company_id WHERE b.booking_token=:token AND b.source='public' FOR UPDATE");
            $query->execute(['token' => $token]);
            $booking = $query->fetch();
            if (!$booking || $booking['status'] !== 'booked') throw new InvalidArgumentException('This appointment is no longer available to reschedule.');
            $this->assertRescheduleWindow($booking);
            if (!(int)$booking['type_active'] || !(int)$booking['type_published']) throw new InvalidArgumentException('This appointment type is no longer available for rescheduling.');
            $type = $this->typeById((int)$booking['appointment_type_id'], (int)$booking['company_id']);
            $typeLock = $this->pdo->prepare('SELECT id FROM appointment_types WHERE id=:id AND company_id=:company AND active=1 AND published=1 FOR UPDATE');
            $typeLock->execute(['id' => $type['id'], 'company' => $booking['company_id']]);
            if (!$typeLock->fetchColumn()) throw new InvalidArgumentException('This appointment type is no longer available for rescheduling.');
            $this->assertRescheduleWindow($booking);
            $this->lockProviders($type);
            $startUtc = $this->utcDate(trim((string)($input['starts_at_utc'] ?? '')));
            $earliestStart = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . (int)$type['cancellation_cutoff_minutes'] . ' minutes')->getTimestamp();
            if ((new DateTimeImmutable($startUtc, new DateTimeZone('UTC')))->getTimestamp() < $earliestStart) throw new InvalidArgumentException('Choose a time outside the cancellation cutoff window.');
            $timezone = $this->timezone($type['timezone']);
            $localDate = (new DateTimeImmutable($startUtc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
            $requestedProvider = trim((string)($input['provider_key'] ?? ''));
            $slot = null;
            foreach ($this->slots($type, $localDate, (int)$booking['guest_count'], true, (int)$booking['id']) as $candidate) {
                if ($candidate['starts_at_utc'] === $startUtc && ($type['assignment_method'] === 'automatic' || $candidate['provider_key'] === $requestedProvider)) { $slot = $candidate; break; }
            }
            if (!$slot) throw new InvalidArgumentException('That appointment slot is no longer available. Choose another time.');
            $update = $this->pdo->prepare("UPDATE appointment_bookings SET assigned_user_id=:user,resource_id=:resource,starts_at_utc=:starts,ends_at_utc=:ends,timezone=:timezone WHERE id=:id AND company_id=:company AND status='booked'");
            $update->execute(['user' => $slot['assigned_user_id'], 'resource' => $slot['resource_id'], 'starts' => $slot['starts_at_utc'], 'ends' => $slot['ends_at_utc'], 'timezone' => $timezone, 'id' => $booking['id'], 'company' => $booking['company_id']]);
            $this->syncCalendarEvent(array_replace($booking, ['status'=>'booked','starts_at_utc'=>$slot['starts_at_utc'],'ends_at_utc'=>$slot['ends_at_utc'],'timezone'=>$timezone,'assigned_user_id'=>$slot['assigned_user_id'],'resource_id'=>$slot['resource_id']]), $type);
            $this->audit((int)$booking['company_id'], null, (int)$booking['id'], 'appointment.booking', 'public_rescheduled', ['starts_at_utc' => $booking['starts_at_utc'], 'ends_at_utc' => $booking['ends_at_utc'], 'assigned_user_id' => $booking['assigned_user_id'], 'resource_id' => $booking['resource_id']], ['starts_at_utc' => $slot['starts_at_utc'], 'ends_at_utc' => $slot['ends_at_utc'], 'assigned_user_id' => $slot['assigned_user_id'], 'resource_id' => $slot['resource_id'], 'contact_id' => $booking['contact_id']], $ip);
            $this->pdo->commit();
            return ['id' => (int)$booking['id'], 'booking_name' => $booking['booking_name'], 'appointment_type' => $type['name'], 'starts_at_utc' => $slot['starts_at_utc'], 'ends_at_utc' => $slot['ends_at_utc'], 'timezone' => $timezone, 'guest_count' => (int)$booking['guest_count'], 'contact_id' => $booking['contact_id'] === null ? null : (int)$booking['contact_id'], 'manage_url' => '/book/manage/' . $token, 'cancel_url' => '/book/cancel/' . $token];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function cancelPublicBooking(string $token, string $reason, string $ip): void
    {
        $this->pdo->beginTransaction();
        try{$q=$this->pdo->prepare('SELECT b.*,t.cancellation_cutoff_minutes,t.name AS appointment_type,t.location FROM appointment_bookings b JOIN appointment_types t ON t.id=b.appointment_type_id AND t.company_id=b.company_id WHERE b.booking_token=:token FOR UPDATE');$q->execute(['token'=>$token]);$row=$q->fetch();if(!$row||$row['status']!=='booked')throw new InvalidArgumentException('The appointment was not found or is no longer booked.');$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$start=new DateTimeImmutable($row['starts_at_utc'],new DateTimeZone('UTC'));if($start->getTimestamp()-$now->getTimestamp()<(int)$row['cancellation_cutoff_minutes']*60)throw new InvalidArgumentException('The cancellation window has closed. Please contact the company.');$reason=$this->text($reason,'Cancellation reason',500,false);$update=$this->pdo->prepare("UPDATE appointment_bookings SET status='cancelled',cancellation_reason=:reason,cancelled_at=UTC_TIMESTAMP() WHERE id=:id");$update->execute(['reason'=>$reason,'id'=>$row['id']]);$this->syncCalendarEvent(array_replace($row,['status'=>'cancelled','cancellation_reason'=>$reason]));$this->audit((int)$row['company_id'],null,(int)$row['id'],'appointment.booking','public_cancelled',['status'=>'booked'],['status'=>'cancelled','reason'=>$reason],$ip);$this->pdo->commit();}
        catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
    }

    public function publicCancellation(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new InvalidArgumentException('Cancellation link is not available.');
        $q = $this->pdo->prepare("SELECT b.booking_name,b.starts_at_utc,b.timezone,b.status,t.name AS appointment_type,t.cancellation_cutoff_minutes FROM appointment_bookings b JOIN appointment_types t ON t.id=b.appointment_type_id AND t.company_id=b.company_id WHERE b.booking_token=:token AND b.source='public' LIMIT 1");
        $q->execute(['token' => $token]); $booking = $q->fetch();
        if (!$booking || $booking['status'] !== 'booked') throw new InvalidArgumentException('This appointment is no longer available to cancel.');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC')); $start = new DateTimeImmutable($booking['starts_at_utc'], new DateTimeZone('UTC'));
        $booking['can_cancel'] = $start->getTimestamp() - $now->getTimestamp() >= (int)$booking['cancellation_cutoff_minutes'] * 60;
        unset($booking['status'], $booking['cancellation_cutoff_minutes']);
        return $booking;
    }

    private function publicManageBookingRow(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new InvalidArgumentException('Appointment management link is not available.');
        $query = $this->pdo->prepare("SELECT b.id,b.company_id,b.appointment_type_id,b.booking_name,b.starts_at_utc,b.ends_at_utc,b.timezone,b.guest_count,b.status,t.name AS appointment_type,t.cancellation_cutoff_minutes,t.active AS type_active,t.published AS type_published FROM appointment_bookings b JOIN appointment_types t ON t.id=b.appointment_type_id AND t.company_id=b.company_id WHERE b.booking_token=:token AND b.source='public' LIMIT 1");
        $query->execute(['token' => $token]);
        $booking = $query->fetch();
        if (!$booking || $booking['status'] !== 'booked') throw new InvalidArgumentException('This appointment is no longer available to manage.');
        $start = new DateTimeImmutable($booking['starts_at_utc'], new DateTimeZone('UTC'));
        $remaining = $start->getTimestamp() - (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp();
        $cutoffSeconds = (int)$booking['cancellation_cutoff_minutes'] * 60;
        $booking['can_cancel'] = $remaining >= $cutoffSeconds;
        $booking['can_reschedule'] = $booking['can_cancel'] && (int)$booking['type_active'] === 1 && (int)$booking['type_published'] === 1;
        $booking['guest_count'] = (int)$booking['guest_count'];
        unset($booking['status'], $booking['cancellation_cutoff_minutes'], $booking['type_active'], $booking['type_published']);
        return $booking;
    }

    private function assertRescheduleWindow(array $booking): void
    {
        $start = new DateTimeImmutable($booking['starts_at_utc'], new DateTimeZone('UTC'));
        $remaining = $start->getTimestamp() - (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp();
        if ($remaining < (int)$booking['cancellation_cutoff_minutes'] * 60) throw new InvalidArgumentException('The rescheduling window has closed. Please contact the company.');
    }

    private function createBooking(array $type,array $input,?int $actor,string $ip,string $source,?Authorization $auth): array
    {
        $typeId=(int)$type['id'];$companyId=(int)$type['company_id'];$guest=$this->integer($input['guest_count']??1,'Guest count',1,(int)$type['capacity_per_slot']);
        if(!(int)$type['allow_guests']&&$guest>1)throw new InvalidArgumentException('Guests are not enabled for this appointment type.');
        $name=$this->text($input['name']??$input['booking_name']??'','Name',190,true);$email=$this->text($input['email']??'','Email',190,$source==='public');$phone=$this->text($input['phone']??'','Phone',80,false);$notes=$this->text($input['notes']??'','Notes',2000,false);
        if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email address.');
        if($source==='public'&&$email==='')throw new InvalidArgumentException('Email is required for a public booking.');
        $startRaw=trim((string)($input['starts_at_utc']??''));$startUtc=$this->utcDate($startRaw);$timezone=$this->timezone($type['timezone']);$local=(new DateTimeImmutable($startUtc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone));$date=$local->format('Y-m-d');
        $requestedProvider=(string)($input['provider_key']??'');$answers=$input['answers']??[];if(!is_array($answers))throw new InvalidArgumentException('Appointment answers must be a list.');
        $this->pdo->beginTransaction();
        try{
            $lock=$this->pdo->prepare('SELECT * FROM appointment_types WHERE id=:id AND company_id=:company AND active=1'.($source==='public'?' AND published=1':'').' FOR UPDATE');$lock->execute(['id'=>$typeId,'company'=>$companyId]);$lockedType=$lock->fetch();if(!$lockedType)throw new InvalidArgumentException('Appointment type is no longer available.');$type=$lockedType;$guest=$this->integer($input['guest_count']??1,'Guest count',1,(int)$type['capacity_per_slot']);if(!(int)$type['allow_guests']&&$guest>1)throw new InvalidArgumentException('Guests are not enabled for this appointment type.');$timezone=$this->timezone($type['timezone']);$local=(new DateTimeImmutable($startUtc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone));$date=$local->format('Y-m-d');
            if ($source === 'internal') {
                $requestedDate = $this->date($input['appointment_date'] ?? null, 'Appointment date');
                if ($requestedDate === null) throw new InvalidArgumentException('Appointment date is required.');
                if ($requestedDate !== $date) throw new InvalidArgumentException('Selected appointment time does not match the appointment date.');
            }
            $this->lockProviders($type);
            $candidates=$this->slots($type,$date,$guest,$source==='public');$slot=null;
            foreach($candidates as $candidate){if($candidate['starts_at_utc']===$startUtc&&($type['assignment_method']==='automatic'||$candidate['provider_key']===$requestedProvider)){$slot=$candidate;break;}}
            if(!$slot)throw new InvalidArgumentException('That appointment slot is no longer available. Choose another time.');
            if($source==='internal'&&$requestedProvider!==''&&$type['assignment_method']==='visitor'&&$slot['provider_key']!==$requestedProvider)throw new InvalidArgumentException('Choose an assigned user or resource from the available list.');
            $contactId=null;
            if($source==='public'){$contactId=$this->findOrCreatePublicContact($companyId,$name,$email,$phone,$ip);}
            elseif(!empty($input['contact_id'])){if(!$auth||!$auth->allowsForCompany('contacts.read',$companyId))throw new AccessDeniedException('Contact access is required to link a Contact in this company.');$contactId=$this->contactId($input['contact_id'],$companyId);}
            $bookingToken=bin2hex(random_bytes(32));$start=$slot['starts_at_utc'];$end=$slot['ends_at_utc'];
            $insert=$this->pdo->prepare('INSERT INTO appointment_bookings (company_id,appointment_type_id,contact_id,assigned_user_id,resource_id,booking_name,email,phone,starts_at_utc,ends_at_utc,timezone,guest_count,status,source,notes,booking_token,created_by) VALUES (:company,:type,:contact,:user,:resource,:name,:email,:phone,:starts,:ends,:timezone,:guests,\'booked\',:source,:notes,:token,:creator)');
            $insert->execute(['company'=>$companyId,'type'=>$typeId,'contact'=>$contactId,'user'=>$slot['assigned_user_id'],'resource'=>$slot['resource_id'],'name'=>$name,'email'=>$email,'phone'=>$phone,'starts'=>$start,'ends'=>$end,'timezone'=>$timezone,'guests'=>$guest,'source'=>$source,'notes'=>$notes,'token'=>$bookingToken,'creator'=>$actor]);$bookingId=(int)$this->pdo->lastInsertId();
            $this->syncCalendarEvent(['id'=>$bookingId,'company_id'=>$companyId,'appointment_type_id'=>$typeId,'contact_id'=>$contactId,'assigned_user_id'=>$slot['assigned_user_id'],'created_by'=>$actor,'booking_name'=>$name,'starts_at_utc'=>$start,'ends_at_utc'=>$end,'timezone'=>$timezone,'guest_count'=>$guest,'notes'=>$notes,'status'=>'booked'], $type);
            $this->saveAnswers($typeId,$companyId,$bookingId,$answers,$source==='public');
            $this->audit($companyId,$actor,$bookingId,'appointment.booking','created',null,['appointment_type_id'=>$typeId,'contact_id'=>$contactId,'assigned_user_id'=>$slot['assigned_user_id'],'resource_id'=>$slot['resource_id'],'starts_at_utc'=>$start,'source'=>$source],$ip);
            $this->pdo->commit();
            $booking=['id'=>$bookingId,'booking_token'=>$bookingToken,'appointment_type'=>$type['name'],'booking_name'=>$name,'email'=>$email,'phone'=>$phone,'starts_at_utc'=>$start,'ends_at_utc'=>$end,'timezone'=>$timezone,'assigned_user'=>$slot['provider_name'],'status'=>'booked','contact_id'=>$contactId,'confirmation_message'=>$type['confirmation_message']];
            if($source==='public'){$booking['cancel_url']='/book/cancel/'.$bookingToken;$booking['manage_url']='/book/manage/'.$bookingToken;}
            return $booking;
        }catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
    }

    /** Keep each appointment's canonical Calendar entry inside the same booking transaction. */
    private function syncCalendarEvent(array $booking, ?array $type = null): void
    {
        $companyId = (int)$booking['company_id'];
        $bookingId = (int)$booking['id'];
        $type ??= $this->typeById((int)$booking['appointment_type_id'], $companyId);
        $status = match ((string)$booking['status']) {
            'booked' => 'scheduled',
            'cancelled' => 'cancelled',
            'completed' => 'completed',
            'no_show' => 'no_show',
            default => throw new InvalidArgumentException('Appointment has an unsupported Calendar status.'),
        };
        $title = mb_substr((string)$type['name'] . ' · ' . (string)$booking['booking_name'], 0, 255);
        $notes = trim((string)($booking['notes'] ?? ''));
        $description = 'Guests: ' . max(1, (int)($booking['guest_count'] ?? 1));
        if ($notes !== '') $description .= "\n\n" . $notes;
        $description = mb_substr($description, 0, 65535);
        $organizerId = !empty($booking['assigned_user_id']) ? (int)$booking['assigned_user_id'] : (!empty($booking['created_by']) ? (int)$booking['created_by'] : null);
        $contactId = !empty($booking['contact_id']) ? (int)$booking['contact_id'] : null;
        $actorId = !empty($booking['created_by']) ? (int)$booking['created_by'] : null;
        $upsert = $this->pdo->prepare("INSERT INTO calendar_events (company_id,title,description,starts_at_utc,ends_at_utc,timezone,location,organizer_user_id,contact_id,source_model,source_record_id,status,created_by,updated_by) VALUES (:company,:title,:description,:starts,:ends,:timezone,:location,:organizer,:contact,'appointment',:source_id,:status,:created_by,:updated_by) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),title=VALUES(title),description=VALUES(description),starts_at_utc=VALUES(starts_at_utc),ends_at_utc=VALUES(ends_at_utc),timezone=VALUES(timezone),location=VALUES(location),organizer_user_id=VALUES(organizer_user_id),contact_id=VALUES(contact_id),status=VALUES(status),updated_by=VALUES(updated_by)");
        $upsert->execute([
            'company' => $companyId,
            'title' => $title,
            'description' => $description,
            'starts' => $booking['starts_at_utc'],
            'ends' => $booking['ends_at_utc'],
            'timezone' => $booking['timezone'],
            'location' => (string)($type['location'] ?? ''),
            'organizer' => $organizerId,
            'contact' => $contactId,
            'source_id' => $bookingId,
            'status' => $status,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
        $eventId = (int)$this->pdo->lastInsertId();
        $userIds = array_values(array_unique(array_filter([$organizerId], static fn(?int $id): bool => $id !== null)));
        $contactIds = $contactId === null ? [] : [$contactId];
        $this->syncCalendarAttendees($eventId, $companyId, $userIds, $contactIds);
    }

    private function syncCalendarAttendees(int $eventId, int $companyId, array $userIds, array $contactIds): void
    {
        $userUpsert = $this->pdo->prepare("INSERT INTO calendar_event_user_attendees (company_id,event_id,user_id,response_status) VALUES (:company,:event,:user,'needs_action') ON DUPLICATE KEY UPDATE response_status=response_status");
        foreach ($userIds as $userId) $userUpsert->execute(['company' => $companyId, 'event' => $eventId, 'user' => $userId]);
        $contactUpsert = $this->pdo->prepare("INSERT INTO calendar_event_contact_attendees (company_id,event_id,contact_id,response_status) VALUES (:company,:event,:contact,'needs_action') ON DUPLICATE KEY UPDATE response_status=response_status");
        foreach ($contactIds as $contactId) $contactUpsert->execute(['company' => $companyId, 'event' => $eventId, 'contact' => $contactId]);
        $this->deleteStaleAttendees('calendar_event_user_attendees', 'user_id', $eventId, $companyId, $userIds);
        $this->deleteStaleAttendees('calendar_event_contact_attendees', 'contact_id', $eventId, $companyId, $contactIds);
    }

    private function deleteStaleAttendees(string $table, string $column, int $eventId, int $companyId, array $ids): void
    {
        if (!$ids) {
            $query = $this->pdo->prepare("DELETE FROM {$table} WHERE event_id=:event AND company_id=:company");
            $query->execute(['event' => $eventId, 'company' => $companyId]);
            return;
        }
        $slots = []; $params = ['event' => $eventId, 'company' => $companyId];
        foreach (array_values($ids) as $index => $id) { $key = 'attendee_' . $index; $slots[] = ':' . $key; $params[$key] = $id; }
        $query = $this->pdo->prepare("DELETE FROM {$table} WHERE event_id=:event AND company_id=:company AND {$column} NOT IN (" . implode(',', $slots) . ')');
        $query->execute($params);
    }

    private function slots(array $type,string $date,int $guestCount,bool $public=false,?int $ignoreBookingId=null):array
    {
        $timezone=new DateTimeZone($this->timezone($type['timezone']));$day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$timezone);$errors=DateTimeImmutable::getLastErrors();if(!$day||($errors&&($errors['warning_count']||$errors['error_count']))||$day->format('Y-m-d')!==$date)throw new InvalidArgumentException('Choose a valid appointment date.');
        $now=new DateTimeImmutable('now',$timezone);$dayStart=$day->setTime(0,0);if($dayStart<$now->setTime(0,0))throw new InvalidArgumentException('Choose today or a future date.');
        if($type['date_start']&&$date<$type['date_start'])return[];if($type['date_end']&&$date>$type['date_end'])return[];if($day>$now->modify('+'.(int)$type['booking_horizon_days'].' days'))return[];
        $weekday=(int)$day->format('N')-1;$rules=$this->pdo->prepare('SELECT start_time,end_time FROM appointment_availabilities WHERE appointment_type_id=:type AND company_id=:company AND weekday=:weekday ORDER BY start_time');$rules->execute(['type'=>$type['id'],'company'=>$type['company_id'],'weekday'=>$weekday]);$providers=$this->providers($type);$slots=[];$earliest=$now->modify('+'.(int)$type['prebooking_minutes'].' minutes');$utc=new DateTimeZone('UTC');$localDayStart=$day->setTime(0,0);$calendarDayStartUtc=$localDayStart->setTimezone($utc)->format('Y-m-d H:i:s');$calendarDayEndUtc=$localDayStart->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');$calendarBusyByUser=[];$calendarBusyByResource=[];
        foreach($rules->fetchAll() as $rule){$start=$day->setTime((int)substr($rule['start_time'],0,2),(int)substr($rule['start_time'],3,2));$limit=$day->setTime((int)substr($rule['end_time'],0,2),(int)substr($rule['end_time'],3,2));while($start->modify('+'.(int)$type['duration_minutes'].' minutes')<=$limit){if($start>=$earliest){$startUtc=$start->setTimezone($utc)->format('Y-m-d H:i:s');$endUtc=$start->modify('+'.(int)$type['duration_minutes'].' minutes')->setTimezone($utc)->format('Y-m-d H:i:s');foreach($providers as $provider){if($this->hasCapacity($type,$provider,$startUtc,$endUtc,$guestCount,$ignoreBookingId)&&!$this->hasCalendarConflict($provider,$startUtc,$endUtc,$calendarDayStartUtc,$calendarDayEndUtc,$calendarBusyByUser,(int)$type['company_id'])&&!$this->hasCalendarResourceConflict($provider,$startUtc,$endUtc,$calendarDayStartUtc,$calendarDayEndUtc,$calendarBusyByResource,(int)$type['company_id'])){$slots[]=['starts_at_utc'=>$startUtc,'ends_at_utc'=>$endUtc,'provider_key'=>$provider['key'],'provider_name'=>$provider['name'],'assigned_user_id'=>$provider['user_id'],'resource_id'=>$provider['resource_id'],'label'=>$start->format('H:i').' · '.$provider['name']];if($type['assignment_method']==='automatic')break;}}} $start=$start->modify('+'.(int)$type['slot_interval_minutes'].' minutes');if(count($slots)>=500)break 2;}}
        return $slots;
    }

    /** Exclude busy, scheduled manual Calendar events and recurring occurrences from user slots. */
    private function hasCalendarConflict(array $provider,string $startsAtUtc,string $endsAtUtc,string $dayStartUtc,string $dayEndUtc,array &$busyByUser,int $companyId):bool
    {
        if($provider['user_id']===null)return false;
        $userId=(int)$provider['user_id'];
        if(!array_key_exists($userId,$busyByUser)){
            $query=$this->pdo->prepare("SELECT e.* FROM calendar_events e WHERE e.company_id=:company AND e.source_model='manual' AND e.status='scheduled' AND ((e.starts_at_utc<:base_until AND ((e.recurrence_frequency IS NULL AND e.ends_at_utc>:base_from) OR (e.recurrence_frequency IS NOT NULL AND e.recurrence_until_date>=DATE_SUB(DATE(:recurrence_day),INTERVAL 367 DAY)))) OR EXISTS (SELECT 1 FROM calendar_event_occurrence_exceptions x WHERE x.company_id=e.company_id AND x.event_id=e.id AND x.status='scheduled' AND ((x.occurrence_start_utc>=DATE_SUB(:exception_anchor_from,INTERVAL 367 DAY) AND x.occurrence_start_utc<:exception_anchor_until) OR (x.has_overrides=1 AND x.starts_at_utc<:override_until AND x.ends_at_utc>:override_from)))) AND (e.organizer_user_id=:organizer OR EXISTS (SELECT 1 FROM calendar_event_user_attendees a WHERE a.company_id=e.company_id AND a.event_id=e.id AND a.user_id=:attendee AND a.response_status<>'declined') OR EXISTS (SELECT 1 FROM calendar_occurrence_user_attendees xa JOIN calendar_event_occurrence_exceptions x ON x.company_id=xa.company_id AND x.event_id=xa.event_id AND x.occurrence_start_utc=xa.occurrence_start_utc WHERE xa.company_id=e.company_id AND xa.event_id=e.id AND xa.user_id=:exception_user AND xa.response_status<>'declined' AND x.status='scheduled')) ORDER BY e.starts_at_utc,e.id");
            $query->execute(['company'=>$companyId,'base_until'=>$dayEndUtc,'base_from'=>$dayStartUtc,'recurrence_day'=>$dayStartUtc,'exception_anchor_from'=>$dayStartUtc,'exception_anchor_until'=>$dayEndUtc,'override_until'=>$dayEndUtc,'override_from'=>$dayStartUtc,'organizer'=>$userId,'attendee'=>$userId,'exception_user'=>$userId]);
            $busyByUser[$userId]=$this->expandCalendarBusy($query->fetchAll(),$dayStartUtc,$dayEndUtc,'user',$userId);
        }
        foreach($busyByUser[$userId] as [$eventStart,$eventEnd])if($eventStart<$endsAtUtc&&$eventEnd>$startsAtUtc)return true;
        return false;
    }

    /** Exclude busy, scheduled manual Calendar events and recurrence instances linked to a resource. */
    private function hasCalendarResourceConflict(array $provider,string $startsAtUtc,string $endsAtUtc,string $dayStartUtc,string $dayEndUtc,array &$busyByResource,int $companyId):bool
    {
        if($provider['resource_id']===null)return false;
        $resourceId=(int)$provider['resource_id'];
        if(!array_key_exists($resourceId,$busyByResource)){
            $query=$this->pdo->prepare("SELECT e.* FROM calendar_events e WHERE e.company_id=:company AND e.source_model='manual' AND e.status='scheduled' AND ((e.starts_at_utc<:base_until AND ((e.recurrence_frequency IS NULL AND e.ends_at_utc>:base_from) OR (e.recurrence_frequency IS NOT NULL AND e.recurrence_until_date>=DATE_SUB(DATE(:recurrence_day),INTERVAL 367 DAY)))) OR EXISTS (SELECT 1 FROM calendar_event_occurrence_exceptions x WHERE x.company_id=e.company_id AND x.event_id=e.id AND x.status='scheduled' AND ((x.occurrence_start_utc>=DATE_SUB(:exception_anchor_from,INTERVAL 367 DAY) AND x.occurrence_start_utc<:exception_anchor_until) OR (x.has_overrides=1 AND x.starts_at_utc<:override_until AND x.ends_at_utc>:override_from)))) AND (EXISTS (SELECT 1 FROM calendar_event_resources er WHERE er.company_id=e.company_id AND er.event_id=e.id AND er.resource_id=:base_resource) OR EXISTS (SELECT 1 FROM calendar_occurrence_resources xr JOIN calendar_event_occurrence_exceptions x ON x.company_id=xr.company_id AND x.event_id=xr.event_id AND x.occurrence_start_utc=xr.occurrence_start_utc WHERE xr.company_id=e.company_id AND xr.event_id=e.id AND xr.resource_id=:exception_resource AND x.status='scheduled')) ORDER BY e.starts_at_utc,e.id");
            $query->execute(['company'=>$companyId,'base_until'=>$dayEndUtc,'base_from'=>$dayStartUtc,'recurrence_day'=>$dayStartUtc,'exception_anchor_from'=>$dayStartUtc,'exception_anchor_until'=>$dayEndUtc,'override_until'=>$dayEndUtc,'override_from'=>$dayStartUtc,'base_resource'=>$resourceId,'exception_resource'=>$resourceId]);
            $busyByResource[$resourceId]=$this->expandCalendarBusy($query->fetchAll(),$dayStartUtc,$dayEndUtc,'resource',$resourceId);
        }
        foreach($busyByResource[$resourceId] as [$eventStart,$eventEnd])if($eventStart<$endsAtUtc&&$eventEnd>$startsAtUtc)return true;
        return false;
    }

    /** @return list<array{0:string,1:string}> */
    private function expandCalendarBusy(array $events,string $dayStartUtc,string $dayEndUtc,string $scope,int $providerId):array
    {
        $intervals=[];
        $eventIds=array_values(array_unique(array_map(static fn(array $event):int=>(int)$event['id'],$events)));
        $baseUsers=[];$baseResources=[];$exceptions=[];
        if($eventIds){
            $slots=[];$params=['company'=>(int)($events[0]['company_id']??0)];foreach($eventIds as $index=>$eventId){$key='event_'.$index;$slots[]=':'.$key;$params[$key]=$eventId;}
            $users=$this->pdo->prepare('SELECT event_id,user_id,response_status FROM calendar_event_user_attendees WHERE company_id=:company AND event_id IN ('.implode(',',$slots).') ORDER BY event_id,user_id');$users->execute($params);foreach($users->fetchAll() as $row){$eventId=(int)$row['event_id'];$baseUsers[$eventId]['ids'][]=(int)$row['user_id'];$baseUsers[$eventId]['responses'][(int)$row['user_id']]=$row['response_status'];}
            $resources=$this->pdo->prepare('SELECT event_id,resource_id FROM calendar_event_resources WHERE company_id=:company AND event_id IN ('.implode(',',$slots).') ORDER BY event_id,resource_id');$resources->execute($params);foreach($resources->fetchAll() as $row)$baseResources[(int)$row['event_id']][]=(int)$row['resource_id'];
            $anchorFrom=(new DateTimeImmutable($dayStartUtc,new DateTimeZone('UTC')))->modify('-367 days')->format('Y-m-d H:i:s');
            $exceptionQuery=$this->pdo->prepare('SELECT * FROM calendar_event_occurrence_exceptions WHERE company_id=:company AND event_id IN ('.implode(',',$slots).') AND ((occurrence_start_utc>=:anchor_from AND occurrence_start_utc<:anchor_until) OR (has_overrides=1 AND starts_at_utc<:override_until AND ends_at_utc>:override_from)) ORDER BY event_id,occurrence_start_utc');
            $exceptionQuery->execute($params+['anchor_from'=>$anchorFrom,'anchor_until'=>$dayEndUtc,'override_until'=>$dayEndUtc,'override_from'=>$dayStartUtc]);
            foreach($exceptionQuery->fetchAll() as $exception){$eventId=(int)$exception['event_id'];$key=['company'=>$params['company'],'event'=>$eventId,'occurrence'=>$exception['occurrence_start_utc']];$userRows=$this->pdo->prepare('SELECT user_id,response_status FROM calendar_occurrence_user_attendees WHERE company_id=:company AND event_id=:event AND occurrence_start_utc=:occurrence ORDER BY user_id');$userRows->execute($key);$userList=$userRows->fetchAll();$exception['attendee_user_ids']=array_map(static fn(array $row):int=>(int)$row['user_id'],$userList);$exception['attendee_user_responses']=array_column($userList,'response_status','user_id');$resourceRows=$this->pdo->prepare('SELECT resource_id FROM calendar_occurrence_resources WHERE company_id=:company AND event_id=:event AND occurrence_start_utc=:occurrence ORDER BY resource_id');$resourceRows->execute($key);$exception['resource_ids']=array_map('intval',$resourceRows->fetchAll(PDO::FETCH_COLUMN));$exceptions[$eventId][]=$exception;}
        }
        foreach($events as $event){
            $eventId=(int)$event['id'];$event['attendee_user_ids']=$baseUsers[$eventId]['ids']??[];$event['attendee_user_responses']=$baseUsers[$eventId]['responses']??[];$event['resource_ids']=$baseResources[$eventId]??[];
            if(empty($event['recurrence_frequency'])){$occurrences=[['starts_at_utc'=>$event['starts_at_utc'],'ends_at_utc'=>$event['ends_at_utc'],'status'=>$event['status'],'organizer_user_id'=>$event['organizer_user_id'],'attendee_user_ids'=>$event['attendee_user_ids'],'attendee_user_responses'=>$event['attendee_user_responses'],'resource_ids'=>$event['resource_ids']]];}
            else {
                $weekdays=$event['recurrence_weekdays']===null?[]:json_decode((string)$event['recurrence_weekdays'],true);
                $event['recurrence_weekdays']=is_array($weekdays)?array_map('intval',$weekdays):[];
                $occurrences=CalendarRecurrence::occurrences($event,$dayStartUtc,$dayEndUtc,$exceptions[$eventId]??[]);
            }
            foreach($occurrences as $occurrence){
                $occurrence=array_merge($event,$occurrence);
                if(($occurrence['status']??'scheduled')!=='scheduled')continue;
                $busy=$scope==='user'
                    ? ((int)($occurrence['organizer_user_id']??0)===$providerId || (isset($occurrence['attendee_user_responses'][$providerId]) && $occurrence['attendee_user_responses'][$providerId]!=='declined'))
                    : in_array($providerId,array_map('intval',$occurrence['resource_ids']??[]),true);
                if($busy)$intervals[]=[$occurrence['starts_at_utc'],$occurrence['ends_at_utc']];
            }
        }
        return $intervals;
    }

    private function providers(array $type):array
    {
        if($type['assignment_target']==='user'){$q=$this->pdo->prepare("SELECT u.id,c.name FROM appointment_type_users x JOIN users u ON u.id=x.user_id JOIN contacts c ON c.id=u.contact_id WHERE x.appointment_type_id=:type AND x.company_id=:company AND u.status IN ('active','confirmed') ORDER BY c.name,u.id");$q->execute(['type'=>$type['id'],'company'=>$type['company_id']]);return array_map(static fn($r)=>['key'=>'user:'.$r['id'],'name'=>$r['name'],'user_id'=>(int)$r['id'],'resource_id'=>null],$q->fetchAll());}
        $q=$this->pdo->prepare('SELECT r.id,r.name,r.capacity FROM appointment_type_resources x JOIN appointment_resources r ON r.id=x.resource_id AND r.company_id=x.company_id WHERE x.appointment_type_id=:type AND x.company_id=:company AND r.active=1 ORDER BY r.name,r.id');$q->execute(['type'=>$type['id'],'company'=>$type['company_id']]);return array_map(static fn($r)=>['key'=>'resource:'.$r['id'],'name'=>$r['name'],'user_id'=>null,'resource_id'=>(int)$r['id'],'capacity'=>(int)$r['capacity']],$q->fetchAll());
    }

    private function hasCapacity(array $type,array $provider,string $start,string $end,int $guests,?int $ignoreBookingId=null):bool
    {
        $exclude = $ignoreBookingId === null ? '' : ' AND id<>:ignore_booking';
        $params = ['current_type'=>$type['id'],'company'=>$type['company_id'],'ends'=>$end,'starts'=>$start];
        if ($ignoreBookingId !== null) $params['ignore_booking'] = $ignoreBookingId;
        if($provider['user_id']!==null){$q=$this->pdo->prepare("SELECT COUNT(DISTINCT appointment_type_id),COALESCE(SUM(guest_count),0),COALESCE(SUM(appointment_type_id<>:current_type),0) FROM appointment_bookings WHERE company_id=:company AND assigned_user_id=:provider AND status='booked' AND starts_at_utc<:ends AND ends_at_utc>:starts{$exclude}");$q->execute($params+['provider'=>$provider['user_id']]);$row=$q->fetch(PDO::FETCH_NUM);$capacity=(int)$type['capacity_per_slot'];}
        else{$q=$this->pdo->prepare("SELECT COUNT(DISTINCT appointment_type_id),COALESCE(SUM(guest_count),0),COALESCE(SUM(appointment_type_id<>:current_type),0) FROM appointment_bookings WHERE company_id=:company AND resource_id=:provider AND status='booked' AND starts_at_utc<:ends AND ends_at_utc>:starts{$exclude}");$q->execute($params+['provider'=>$provider['resource_id']]);$row=$q->fetch(PDO::FETCH_NUM);$capacity=min((int)$type['capacity_per_slot'],(int)$provider['capacity']);}
        return (int)$row[2]===0&&(int)$row[1]+$guests<=$capacity;
    }

    private function lockProviders(array $type):void
    {
        if($type['assignment_target']==='user'){$q=$this->pdo->prepare('SELECT u.id FROM appointment_type_users x JOIN users u ON u.id=x.user_id WHERE x.appointment_type_id=:type AND x.company_id=:company ORDER BY u.id FOR UPDATE');}
        else{$q=$this->pdo->prepare('SELECT r.id FROM appointment_type_resources x JOIN appointment_resources r ON r.id=x.resource_id AND r.company_id=x.company_id WHERE x.appointment_type_id=:type AND x.company_id=:company ORDER BY r.id FOR UPDATE');}
        $q->execute(['type'=>$type['id'],'company'=>$type['company_id']]);$q->fetchAll(PDO::FETCH_COLUMN);
    }

    private function prepareType(array $input,int $companyId,UserContext $user,Authorization $auth):array
    {
        $name=$this->text($input['name']??'','Appointment title',120,true);$duration=$this->integer($input['duration_minutes']??30,'Duration',5,1440);$slot=$this->integer($input['slot_interval_minutes']??$duration,'Slot interval',5,1440);$notice=$this->integer($input['prebooking_minutes']??60,'Pre-booking time',0,525600);$horizon=$this->integer($input['booking_horizon_days']??60,'Booking horizon',1,730);$dateStart=$this->date($input['date_start']??null,'Start date');$dateEnd=$this->date($input['date_end']??null,'End date');if($dateStart&&$dateEnd&&$dateStart>$dateEnd)throw new InvalidArgumentException('Start date must be on or before End date.');$cutoff=$this->integer($input['cancellation_cutoff_minutes']??0,'Cancellation cutoff',0,525600);$target=(string)($input['assignment_target']??'user');$method=(string)($input['assignment_method']??'automatic');if(!in_array($target,['user','resource'],true)||!in_array($method,['automatic','visitor'],true))throw new InvalidArgumentException('Choose a valid assignment target and method.');$locationType=(string)($input['location_type']??'online');if(!in_array($locationType,['online','physical','phone'],true))throw new InvalidArgumentException('Choose a valid meeting location type.');$capacity=$this->integer($input['capacity_per_slot']??1,'Capacity per slot',1,500);$timezone=$this->timezone($input['timezone']??'Asia/Dhaka');$location=$this->text($input['location']??'','Location',255,false);$intro=$this->text($input['intro_message']??'','Introduction',2000,false);$confirmation=$this->text($input['confirmation_message']??'','Confirmation message',2000,false);
        $users=$this->ids($input['user_ids']??[],'Users');$resources=$this->ids($input['resource_ids']??[],'Resources');if($target==='user'&&!$users)throw new InvalidArgumentException('Select at least one active user.');if($target==='resource'&&!$resources)throw new InvalidArgumentException('Select at least one active resource.');$this->validateProviders($companyId,$target,$users,$resources);
        $availability=$input['availability']??[];if(!is_array($availability)||count($availability)<1||count($availability)>100)throw new InvalidArgumentException('Add at least one weekly availability window.');$normalized=[];foreach($availability as $index=>$row){if(!is_array($row))throw new InvalidArgumentException('Availability window '.($index+1).' is invalid.');$weekday=$this->integer($row['weekday']??null,'Weekday',0,6);$start=$this->time($row['start_time']??null,'Start time');$end=$this->time($row['end_time']??null,'End time');if($start>=$end)throw new InvalidArgumentException('Each availability window must end after it starts.');$normalized[]=['weekday'=>$weekday,'start_time'=>$start,'end_time'=>$end];}
        $questions=$input['questions']??[];if(!is_array($questions)||count($questions)>30)throw new InvalidArgumentException('A maximum of 30 booking questions is allowed.');$cleanQuestions=[];foreach($questions as $index=>$q){if(!is_array($q))throw new InvalidArgumentException('Question '.($index+1).' is invalid.');$question=$this->text($q['question']??'','Question',255,true);$answerType=(string)($q['answer_type']??'text');if(!in_array($answerType,['text','email','phone','number'],true))throw new InvalidArgumentException('Choose a supported answer type.');$cleanQuestions[]=['id'=>isset($q['id'])?$this->positiveId($q['id'],'Question'):null,'question'=>$question,'answer_type'=>$answerType,'is_required'=>!empty($q['is_required'])?1:0,'sequence'=>($index+1)*10];}
        return ['company'=>$companyId,'name'=>$name,'duration'=>$duration,'slot'=>$slot,'notice'=>$notice,'horizon'=>$horizon,'date_start'=>$dateStart,'date_end'=>$dateEnd,'cancel_cutoff'=>$cutoff,'target'=>$target,'method'=>$method,'location_type'=>$locationType,'location'=>$location,'capacity'=>$capacity,'guests'=>!empty($input['allow_guests'])?1:0,'timezone'=>$timezone,'intro'=>$intro,'confirmation'=>$confirmation,'published'=>!empty($input['published'])?1:0,'users'=>$users,'resources'=>$resources,'availability'=>$normalized,'questions'=>$cleanQuestions];
    }

    private function replaceTypeLinks(int $id,int $company,string $target,array $users,array $resources,array $availability,array $questions):void
    {
        foreach(['appointment_type_users','appointment_type_resources','appointment_availabilities'] as $table){$q=$this->pdo->prepare("DELETE FROM {$table} WHERE appointment_type_id=:type AND company_id=:company");$q->execute(['type'=>$id,'company'=>$company]);}
        $q=$this->pdo->prepare('INSERT INTO appointment_type_users (company_id,appointment_type_id,user_id) VALUES (:company,:type,:user)');foreach($users as $user)$q->execute(['company'=>$company,'type'=>$id,'user'=>$user]);
        $q=$this->pdo->prepare('INSERT INTO appointment_type_resources (company_id,appointment_type_id,resource_id) VALUES (:company,:type,:resource)');foreach($resources as $resource)$q->execute(['company'=>$company,'type'=>$id,'resource'=>$resource]);
        $q=$this->pdo->prepare('INSERT INTO appointment_availabilities (company_id,appointment_type_id,weekday,start_time,end_time) VALUES (:company,:type,:weekday,:start,:end)');foreach($availability as $row)$q->execute(['company'=>$company,'type'=>$id,'weekday'=>$row['weekday'],'start'=>$row['start_time'],'end'=>$row['end_time']]);
        $this->pdo->prepare('UPDATE appointment_questions SET active=0 WHERE appointment_type_id=:type AND company_id=:company')->execute(['type'=>$id,'company'=>$company]);
        foreach($questions as $row){if($row['id']){$update=$this->pdo->prepare('UPDATE appointment_questions SET question=:question,answer_type=:answer_type,is_required=:required,sequence=:sequence,active=1 WHERE id=:id AND appointment_type_id=:type AND company_id=:company');$update->execute(['question'=>$row['question'],'answer_type'=>$row['answer_type'],'required'=>$row['is_required'],'sequence'=>$row['sequence'],'id'=>$row['id'],'type'=>$id,'company'=>$company]);if(!$update->rowCount()){$check=$this->pdo->prepare('SELECT id FROM appointment_questions WHERE id=:id AND appointment_type_id=:type AND company_id=:company');$check->execute(['id'=>$row['id'],'type'=>$id,'company'=>$company]);if(!$check->fetchColumn())throw new InvalidArgumentException('A booking question is not part of this appointment type.');}}
            else{$insert=$this->pdo->prepare('INSERT INTO appointment_questions (company_id,appointment_type_id,question,answer_type,is_required,sequence) VALUES (:company,:type,:question,:answer_type,:required,:sequence)');$insert->execute(['company'=>$company,'type'=>$id,'question'=>$row['question'],'answer_type'=>$row['answer_type'],'required'=>$row['is_required'],'sequence'=>$row['sequence']]);}}
    }

    private function typeDetails(array $type,bool $public=false):array
    {
        $id=(int)$type['id'];$company=(int)$type['company_id'];$users=$this->pdo->prepare('SELECT u.id,c.name FROM appointment_type_users x JOIN users u ON u.id=x.user_id JOIN contacts c ON c.id=u.contact_id WHERE x.appointment_type_id=:type AND x.company_id=:company ORDER BY c.name,u.id');$users->execute(['type'=>$id,'company'=>$company]);$resources=$this->pdo->prepare('SELECT r.id,r.name,r.capacity FROM appointment_type_resources x JOIN appointment_resources r ON r.id=x.resource_id AND r.company_id=x.company_id WHERE x.appointment_type_id=:type AND x.company_id=:company ORDER BY r.name,r.id');$resources->execute(['type'=>$id,'company'=>$company]);$availability=$this->pdo->prepare('SELECT id,weekday,start_time,end_time FROM appointment_availabilities WHERE appointment_type_id=:type AND company_id=:company ORDER BY weekday,start_time,id');$availability->execute(['type'=>$id,'company'=>$company]);$result=$this->castType($type);$result['users']=$users->fetchAll();$result['resources']=$resources->fetchAll();$result['availability']=$availability->fetchAll();$result['questions']=$this->questions($id,$company);$result['public_url']='/book/'.$type['public_token'];if($public){unset($result['company_id'],$result['public_token'],$result['created_by'],$result['updated_by'],$result['active'],$result['published']);}return $result;
    }

    private function typeSqlValues(array $values): array
    {
        return [
            'company' => $values['company'], 'name' => $values['name'], 'duration' => $values['duration'],
            'slot' => $values['slot'], 'notice' => $values['notice'], 'horizon' => $values['horizon'],
            'date_start' => $values['date_start'], 'date_end' => $values['date_end'], 'cancel_cutoff' => $values['cancel_cutoff'],
            'target' => $values['target'], 'method' => $values['method'], 'location_type' => $values['location_type'],
            'location' => $values['location'], 'capacity' => $values['capacity'], 'guests' => $values['guests'],
            'timezone' => $values['timezone'], 'intro' => $values['intro'], 'confirmation' => $values['confirmation'],
            'published' => $values['published'],
        ];
    }

    private function questions(int $type,int $company):array{$q=$this->pdo->prepare('SELECT id,question,answer_type,is_required,sequence FROM appointment_questions WHERE appointment_type_id=:type AND company_id=:company AND active=1 ORDER BY sequence,id');$q->execute(['type'=>$type,'company'=>$company]);return array_map(static function($row){foreach(['id','is_required','sequence'] as $k)$row[$k]=(int)$row[$k];return$row;},$q->fetchAll());}
    private function typeForUser(int $id,Authorization $auth,bool $lock):?array{$q=$this->pdo->prepare('SELECT * FROM appointment_types WHERE id=:id AND active=1'.($lock?' FOR UPDATE':''));$q->execute(['id'=>$id]);$row=$q->fetch();return $row&&$auth->allowsForCompany('appointments.read',(int)$row['company_id'])?$row:null;}
    private function typeById(int $id,int $company):array{$q=$this->pdo->prepare('SELECT * FROM appointment_types WHERE id=:id AND company_id=:company');$q->execute(['id'=>$id,'company'=>$company]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Saved appointment type could not be loaded.');return$row;}
    private function typeByToken(string $token):?array{if(!preg_match('/^[a-f0-9]{64}$/',$token))return null;$q=$this->pdo->prepare('SELECT * FROM appointment_types WHERE public_token=:token');$q->execute(['token'=>$token]);return$q->fetch()?:null;}
    private function validateProviders(int $company,string $target,array $users,array $resources):void{if($target==='user'&&$users){$p=implode(',',array_fill(0,count($users),'?'));$q=$this->pdo->prepare("SELECT u.id FROM users u JOIN user_company_access a ON a.user_id=u.id AND a.company_id=? WHERE u.status IN ('active','confirmed') AND u.id IN ({$p})");$q->execute(array_merge([$company],$users));if(count($q->fetchAll(PDO::FETCH_COLUMN))!==count($users))throw new InvalidArgumentException('Each appointment user must be active and have access to this company.');}if($target==='resource'&&$resources){$p=implode(',',array_fill(0,count($resources),'?'));$q=$this->pdo->prepare("SELECT id FROM appointment_resources WHERE company_id=? AND active=1 AND id IN ({$p})");$q->execute(array_merge([$company],$resources));if(count($q->fetchAll(PDO::FETCH_COLUMN))!==count($resources))throw new InvalidArgumentException('Each appointment resource must be active and belong to this company.');}}
    private function companyRows(UserContext $user,Authorization $auth):array
    {
        if($user->superuser)$rows=$this->pdo->query('SELECT id,name FROM companies WHERE active=1 ORDER BY name,id')->fetchAll();
        elseif(!$user->companyIds)return[];
        else{$holders=[];$params=[];foreach($user->companyIds as $i=>$id){$holders[]=':c'.$i;$params['c'.$i]=$id;}$q=$this->pdo->prepare('SELECT id,name FROM companies WHERE active=1 AND id IN ('.implode(',',$holders).') ORDER BY name,id');$q->execute($params);$rows=$q->fetchAll();}
        return array_values(array_filter($rows,static fn(array $row):bool=>$auth->allowsForCompany('appointments.read',(int)$row['id'])));
    }
    private function company(mixed $value,Authorization $auth):int{$id=$this->positiveId($value,'Company');if(!$auth->canAccessCompany($id))throw new InvalidArgumentException('You are not allowed to access this company.');$q=$this->pdo->prepare('SELECT id FROM companies WHERE id=:id AND active=1');$q->execute(['id'=>$id]);if(!$q->fetchColumn())throw new InvalidArgumentException('Company is unavailable.');return$id;}
    private function requireCompanyPermission(Authorization $auth,string $permission,int $companyId):void{if(!$auth->allowsForCompany($permission,$companyId))throw new AccessDeniedException('You do not have permission to use '.$permission.' for this company.');}
    private function contactId(mixed $value,int $company):int{$id=$this->positiveId($value,'Contact');$q=$this->pdo->prepare('SELECT id FROM contacts WHERE id=:id AND active=1 AND (company_id=:company OR company_id IS NULL)');$q->execute(['id'=>$id,'company'=>$company]);if(!$q->fetchColumn())throw new InvalidArgumentException('Contact must be active and belong to this company or be shared.');return$id;}
    private function findOrCreatePublicContact(int $company,string $name,string $email,string $phone,string $ip):int{$q=$this->pdo->prepare('SELECT id FROM contacts WHERE active=1 AND LOWER(email)=LOWER(:email) AND (company_id=:company_filter OR company_id IS NULL) ORDER BY (company_id=:company_order) DESC,id LIMIT 1');$q->execute(['email'=>$email,'company_filter'=>$company,'company_order'=>$company]);$id=$q->fetchColumn();if($id!==false)return(int)$id;$q=$this->pdo->prepare("INSERT INTO contacts (company_id,contact_type,name,email,phone,customer) VALUES (:company,'person',:name,:email,:phone,0)");$q->execute(['company'=>$company,'name'=>$name,'email'=>$email,'phone'=>$phone]);$id=(int)$this->pdo->lastInsertId();$this->audit($company,null,$id,'contacts','created_from_public_appointment',null,['name'=>$name,'email'=>$email,'phone'=>$phone],$ip);return$id;}
    private function saveAnswers(int $type,int $company,int $booking,array $answers,bool $public):void{$questions=$this->questions($type,$company);$provided=[];foreach($answers as $row){if(!is_array($row)||!isset($row['question_id']))throw new InvalidArgumentException('Each answer must include a question ID.');$id=$this->positiveId($row['question_id'],'Question');if(isset($provided[$id]))throw new InvalidArgumentException('Each booking question can be answered only once.');$provided[$id]=$this->text($row['answer']??'','Answer',2000,false);}foreach($questions as $q){$answer=$provided[$q['id']]??'';if($q['is_required']&&$answer==='')throw new InvalidArgumentException('Answer required: '.$q['question']);if($answer!==''&&$q['answer_type']==='email'&&!filter_var($answer,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email address for: '.$q['question']);if($answer!==''&&$q['answer_type']==='number'&&!is_numeric($answer))throw new InvalidArgumentException('Enter a number for: '.$q['question']);$insert=$this->pdo->prepare('INSERT INTO appointment_answers (company_id,booking_id,question_id,answer_text) VALUES (:company,:booking,:question,:answer)');$insert->execute(['company'=>$company,'booking'=>$booking,'question'=>$q['id'],'answer'=>$answer]);}foreach(array_keys($provided) as $id)if(!array_filter($questions,static fn($q)=>(int)$q['id']===(int)$id))throw new InvalidArgumentException('An answer refers to a question that is not active for this appointment type.');}
    private function audit(int $company,?int $actor,int $record,string $model,string $event,?array $old,?array $new,string $remote):void{$ip=filter_var($remote,FILTER_VALIDATE_IP)?inet_pton($remote):null;$q=$this->pdo->prepare('INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) VALUES (:company,:user,:event,:model,:record,:old,:new,:ip)');$q->bindValue(':company',$company,PDO::PARAM_INT);$q->bindValue(':user',$actor,$actor===null?PDO::PARAM_NULL:PDO::PARAM_INT);$q->bindValue(':event',$event);$q->bindValue(':model',$model);$q->bindValue(':record',$record,PDO::PARAM_INT);$q->bindValue(':old',$old===null?null:json_encode($old,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$old===null?PDO::PARAM_NULL:PDO::PARAM_STR);$q->bindValue(':new',$new===null?null:json_encode($new,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$new===null?PDO::PARAM_NULL:PDO::PARAM_STR);$q->bindValue(':ip',$ip,$ip===null?PDO::PARAM_NULL:PDO::PARAM_LOB);$q->execute();}
    private function auditTypeValues(array $v):array{return['name'=>$v['name']??null,'duration_minutes'=>$v['duration']??$v['duration_minutes']??null,'slot_interval_minutes'=>$v['slot']??$v['slot_interval_minutes']??null,'assignment_target'=>$v['target']??$v['assignment_target']??null,'published'=>$v['published']??null];}
    private function castType(array $row):array{foreach(['id','company_id','duration_minutes','slot_interval_minutes','prebooking_minutes','booking_horizon_days','cancellation_cutoff_minutes','capacity_per_slot','allow_guests','published','active'] as $k)if(isset($row[$k]))$row[$k]=(int)$row[$k];return$row;}
    private function timezone(mixed $v):string{$value=trim((string)$v);if(!in_array($value,timezone_identifiers_list(),true))throw new InvalidArgumentException('Choose a supported time zone.');return$value;}
    private function date(mixed $v,string $label):?string{if($v===null||$v==='')return null;$value=(string)$v;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);$e=DateTimeImmutable::getLastErrors();if(!$d||($e&&($e['warning_count']||$e['error_count']))||$d->format('Y-m-d')!==$value)throw new InvalidArgumentException($label.' must use YYYY-MM-DD.');return$value;}
    private function time(mixed $v,string $label):string{$value=(string)$v;if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',$value))throw new InvalidArgumentException($label.' must use 24-hour HH:MM.');return strlen($value)===5?$value.':00':$value;}
    private function utcDate(string $value):string{$dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',str_replace('T',' ',$value),new DateTimeZone('UTC'));if(!$dt||$dt->format('Y-m-d H:i:s')!==str_replace('T',' ',$value))throw new InvalidArgumentException('Choose an available appointment time.');return$dt->format('Y-m-d H:i:s');}
    private function ids(mixed $values,string $label):array{if(!is_array($values))throw new InvalidArgumentException($label.' must be a list.');if(count($values)>100)throw new InvalidArgumentException('A maximum of 100 '.$label.' can be selected.');$ids=[];foreach($values as $value)$ids[]=$this->positiveId($value,$label);return array_values(array_unique($ids));}
    private function positiveId(mixed $value,string $label):int{$id=filter_var($value,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException($label.' must be a positive ID.');return(int)$id;}
    private function integer(mixed $value,string $label,int $min,int $max):int{$n=filter_var($value,FILTER_VALIDATE_INT);if($n===false||$n<$min||$n>$max)throw new InvalidArgumentException($label.' must be between '.$min.' and '.$max.'.');return(int)$n;}
    private function text(mixed $value,string $label,int $max,bool $required):string{$s=trim((string)($value??''));if(($required&&$s==='')||mb_strlen($s)>$max)throw new InvalidArgumentException($label.($required?' is required and':'').' must be '.$max.' characters or fewer.');return$s;}
}
