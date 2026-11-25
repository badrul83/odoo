<?php
declare(strict_types=1);

namespace Erp\Modules\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDO;
use Throwable;

final class CalendarRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function options(mixed $rawCompanyId, UserContext $user, Authorization $auth): array
    {
        $companies = $this->companiesFor($user, $auth);
        $companyId = $this->companyId($rawCompanyId ?? $user->defaultCompanyId ?? ($companies[0]['id'] ?? null), $auth);
        $this->assertReadable($companyId, $auth);
        $company = $this->companyRow($companyId);
        $users = $this->pdo->prepare("SELECT u.id,c.name,c.email FROM users u JOIN contacts c ON c.id=u.contact_id JOIN user_company_access a ON a.user_id=u.id AND a.company_id=:company WHERE u.status IN ('active','confirmed') ORDER BY c.name,u.id");
        $users->execute(['company' => $companyId]);
        $contacts = $this->pdo->prepare('SELECT id,name,email,phone FROM contacts WHERE active=1 AND (company_id=:company OR company_id IS NULL) ORDER BY name,id');
        $contacts->execute(['company' => $companyId]);
        $resources = [];
        if ($this->canReadAppointmentResources($companyId, $auth)) {
            $resourceQuery = $this->pdo->prepare('SELECT id,name,capacity FROM appointment_resources WHERE company_id=:company AND active=1 ORDER BY name,id');
            $resourceQuery->execute(['company' => $companyId]);
            $resources = $resourceQuery->fetchAll();
            foreach ($resources as &$resource) { $resource['id'] = (int)$resource['id']; $resource['capacity'] = (int)$resource['capacity']; }
            unset($resource);
        }
        return ['company' => $company, 'companies' => $companies, 'users' => $users->fetchAll(), 'contacts' => $contacts->fetchAll(), 'resources' => $resources];
    }

    public function events(UserContext $user, Authorization $auth, array $filters): array
    {
        $companyId = $this->companyId($filters['company_id'] ?? $user->defaultCompanyId, $auth);
        $this->assertReadable($companyId, $auth);
        $company = $this->companyRow($companyId);
        $timezone = new DateTimeZone((string)$company['timezone']);
        $now = new DateTimeImmutable('now', $timezone);
        $from = $this->date($filters['from_date'] ?? $now->modify('first day of this month')->format('Y-m-d'), 'Start date');
        $until = $this->date($filters['until_date'] ?? $now->modify('first day of next month')->format('Y-m-d'), 'End date');
        if ($until <= $from) throw new InvalidArgumentException('End date must be after start date.');
        $fromUtc = (new DateTimeImmutable($from . ' 00:00:00', $timezone))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $untilUtc = (new DateTimeImmutable($until . ' 00:00:00', $timezone))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $recurrenceFrom = (new DateTimeImmutable($from, $timezone))->modify('-367 days')->format('Y-m-d');
        $status = (string)($filters['status'] ?? 'all');
        if (!in_array($status, ['all','scheduled','cancelled','completed','no_show'], true)) throw new InvalidArgumentException('Choose a valid Calendar status.');
        $contactId = $filters['contact_id'] ?? null;
        if ($contactId !== null && $contactId !== '') $contactId = $this->positiveId($contactId, 'Contact'); else $contactId = null;
        $conditions = ['e.company_id=:company', '((e.starts_at_utc<:until_utc AND ((e.recurrence_frequency IS NULL AND e.ends_at_utc>:from_utc) OR (e.recurrence_frequency IS NOT NULL AND e.recurrence_until_date>=:recurrence_from))) OR EXISTS (SELECT 1 FROM calendar_event_occurrence_exceptions ox WHERE ox.company_id=e.company_id AND ox.event_id=e.id AND COALESCE(ox.starts_at_utc,ox.occurrence_start_utc)<:exception_until AND COALESCE(ox.ends_at_utc,DATE_ADD(ox.occurrence_start_utc,INTERVAL 1 SECOND))>:exception_from))'];
        $params = ['company' => $companyId, 'from_utc' => $fromUtc, 'until_utc' => $untilUtc, 'recurrence_from' => $recurrenceFrom, 'exception_from' => $fromUtc, 'exception_until' => $untilUtc];
        if ($contactId !== null) {
            $contactCheck = $this->pdo->prepare('SELECT 1 FROM contacts WHERE id=:contact AND active=1 AND (company_id=:company OR company_id IS NULL)');
            $contactCheck->execute(['contact' => $contactId, 'company' => $companyId]);
            if (!$contactCheck->fetchColumn()) throw new InvalidArgumentException('Contact is outside the selected company scope.');
        }
        $query = $this->pdo->prepare(
            'SELECT e.*,c.name AS contact_name,oc.name AS organizer_name,' .
            '(SELECT COUNT(*) FROM calendar_event_user_attendees ua WHERE ua.event_id=e.id AND ua.company_id=e.company_id) AS user_attendee_count,' .
            '(SELECT COUNT(*) FROM calendar_event_contact_attendees ca WHERE ca.event_id=e.id AND ca.company_id=e.company_id) AS contact_attendee_count,' .
            '(SELECT ua.response_status FROM calendar_event_user_attendees ua WHERE ua.event_id=e.id AND ua.company_id=e.company_id AND ua.user_id=:current_user) AS my_response_status ' .
            'FROM calendar_events e LEFT JOIN contacts c ON c.id=e.contact_id LEFT JOIN users u ON u.id=e.organizer_user_id LEFT JOIN contacts oc ON oc.id=u.contact_id WHERE ' . implode(' AND ', $conditions) . ' ORDER BY e.starts_at_utc,e.id'
        );
        $params['current_user'] = $user->id;
        $query->execute($params);
        $rows = $query->fetchAll();
        $rowIds = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        $exceptionsByEvent = $this->occurrenceExceptions($rowIds, $companyId, $user->id, $auth, $fromUtc, $untilUtc);
        $baseContactAttendees = $contactId !== null ? $this->baseContactAttendees($rowIds, $companyId) : [];
        $resourceNames = [];
        $resourceIds = [];
        if ($rows && $this->canReadAppointmentResources($companyId, $auth)) {
            $eventIds = array_map(static fn(array $row): int => (int)$row['id'], $rows);
            $eventSlots = [];
            $eventParams = ['company' => $companyId];
            foreach ($eventIds as $index => $eventId) { $key = 'event_' . $index; $eventSlots[] = ':' . $key; $eventParams[$key] = $eventId; }
            $resourceQuery = $this->pdo->prepare('SELECT er.event_id,r.id,r.name FROM calendar_event_resources er JOIN appointment_resources r ON r.id=er.resource_id AND r.company_id=er.company_id WHERE er.company_id=:company AND er.event_id IN (' . implode(',', $eventSlots) . ') ORDER BY er.event_id,r.name,r.id');
            $resourceQuery->execute($eventParams);
            foreach ($resourceQuery->fetchAll() as $resource) {
                $eventId = (int)$resource['event_id'];
                $resourceIds[$eventId][] = (int)$resource['id'];
                $resourceNames[$eventId][] = (string)$resource['name'];
            }
        }
        $expandedRows = [];
        foreach ($rows as $row) {
            $row['id'] = (int)$row['id']; $row['company_id'] = (int)$row['company_id'];
            $row['organizer_user_id'] = $row['organizer_user_id'] === null ? null : (int)$row['organizer_user_id'];
            $row['contact_id'] = $row['contact_id'] === null ? null : (int)$row['contact_id'];
            $row['source_record_id'] = $row['source_record_id'] === null ? null : (int)$row['source_record_id'];
            $row['user_attendee_count'] = (int)$row['user_attendee_count']; $row['contact_attendee_count'] = (int)$row['contact_attendee_count'];
            $row['all_day'] = (bool)$row['all_day'];
            $weekdays = $row['recurrence_weekdays'] === null ? [] : json_decode((string)$row['recurrence_weekdays'], true);
            $row['recurrence_weekdays'] = is_array($weekdays) ? array_map('intval', $weekdays) : [];
            $row['recurrent'] = $row['recurrence_frequency'] !== null;
            $row['resource_ids'] = $resourceIds[(int)$row['id']] ?? [];
            $row['resource_names'] = implode(', ', $resourceNames[(int)$row['id']] ?? []);
            $instances = $row['recurrent']
                ? CalendarRecurrence::occurrences($row, $fromUtc, $untilUtc, $exceptionsByEvent[(int)$row['id']] ?? [])
                : [['starts_at_utc'=>$row['starts_at_utc'],'ends_at_utc'=>$row['ends_at_utc'],'recurrence_index'=>null,'occurrence_start_utc'=>null]];
            foreach ($instances as $instance) {
                $occurrence = $row;
                foreach (['status','title','description','all_day','timezone','location','organizer_user_id','organizer_name','contact_id','contact_name','user_attendee_count','contact_attendee_count','my_response_status','attendee_user_ids','attendee_contact_ids','resource_ids','resource_names'] as $field) {
                    if (array_key_exists($field, $instance)) $occurrence[$field] = $instance[$field];
                }
                $occurrence['starts_at_utc'] = $instance['starts_at_utc'];
                $occurrence['ends_at_utc'] = $instance['ends_at_utc'];
                $occurrence['recurrence_index'] = $instance['recurrence_index'];
                $occurrence['occurrence_start_utc'] = $instance['occurrence_start_utc'];
                $occurrence['is_recurrence_occurrence'] = $row['recurrent'];
                $occurrence['is_occurrence_exception'] = (bool)($instance['is_occurrence_exception'] ?? false);
                if ($status !== 'all' && $occurrence['status'] !== $status) continue;
                $attendeeContacts = $occurrence['attendee_contact_ids'] ?? ($baseContactAttendees[(int)$row['id']] ?? []);
                if ($contactId !== null && (int)($occurrence['contact_id'] ?? 0) !== $contactId && !in_array($contactId, $attendeeContacts, true)) continue;
                $occurrence['starts_at_local'] = $this->toLocal((string)$occurrence['starts_at_utc'], (string)$occurrence['timezone']);
                $occurrence['ends_at_local'] = $this->toLocal((string)$occurrence['ends_at_utc'], (string)$occurrence['timezone']);
                $occurrence['start_date_local'] = substr($occurrence['starts_at_local'], 0, 10);
                $occurrence['end_date_local'] = $occurrence['all_day']
                    ? (new DateTimeImmutable(substr($occurrence['ends_at_local'], 0, 10), new DateTimeZone((string)$occurrence['timezone'])))->modify('-1 day')->format('Y-m-d')
                    : substr($occurrence['ends_at_local'], 0, 10);
                $expandedRows[] = $occurrence;
            }
        }
        usort($expandedRows, static fn(array $a, array $b): int => [$a['starts_at_utc'], $a['id']] <=> [$b['starts_at_utc'], $b['id']]);
        return ['company' => $company, 'events' => $expandedRows, 'total' => count($expandedRows), 'from_date' => $from, 'until_date' => $until];
    }

    /** Load normalized one-occurrence overrides for a bounded set of events. */
    private function occurrenceExceptions(array $eventIds, int $companyId, int $currentUserId, Authorization $auth, string $rangeStartUtc, string $rangeEndUtc): array
    {
        if (!$eventIds) return [];
        $anchorFromUtc = (new DateTimeImmutable($rangeStartUtc,new DateTimeZone('UTC')))->modify('-367 days')->format('Y-m-d H:i:s');
        $slots = []; $params = ['company' => $companyId, 'current_user' => $currentUserId, 'anchor_from' => $anchorFromUtc, 'anchor_until' => $rangeEndUtc, 'override_from' => $rangeStartUtc, 'override_until' => $rangeEndUtc];
        foreach (array_values(array_unique($eventIds)) as $index => $eventId) { $key = 'event_' . $index; $slots[] = ':' . $key; $params[$key] = $eventId; }
        $query = $this->pdo->prepare('SELECT x.*,c.name AS contact_name,oc.name AS organizer_name,(SELECT a.response_status FROM calendar_occurrence_user_attendees a WHERE a.company_id=x.company_id AND a.event_id=x.event_id AND a.occurrence_start_utc=x.occurrence_start_utc AND a.user_id=:current_user) AS my_response_status FROM calendar_event_occurrence_exceptions x LEFT JOIN contacts c ON c.id=x.contact_id LEFT JOIN users u ON u.id=x.organizer_user_id LEFT JOIN contacts oc ON oc.id=u.contact_id WHERE x.company_id=:company AND x.event_id IN (' . implode(',', $slots) . ') AND ((x.occurrence_start_utc>=:anchor_from AND x.occurrence_start_utc<:anchor_until) OR (x.has_overrides=1 AND x.starts_at_utc<:override_until AND x.ends_at_utc>:override_from)) ORDER BY x.event_id,x.occurrence_start_utc');
        $query->execute($params);
        $exceptions = $query->fetchAll();
        if (!$exceptions) return [];
        $result = [];
        foreach ($exceptions as $exception) {
            $eventId = (int)$exception['event_id'];
            $exception['has_overrides'] = (bool)$exception['has_overrides'];
            $exception['all_day'] = $exception['all_day'] === null ? false : (bool)$exception['all_day'];
            $exception['organizer_user_id'] = $exception['organizer_user_id'] === null ? null : (int)$exception['organizer_user_id'];
            $exception['contact_id'] = $exception['contact_id'] === null ? null : (int)$exception['contact_id'];
            $exception['attendee_user_ids'] = []; $exception['attendee_user_responses'] = []; $exception['attendee_contact_ids'] = []; $exception['resource_ids'] = []; $exception['resource_names'] = '';
            $result[$eventId][] = $exception;
        }
        foreach ($result as $eventId => &$eventExceptions) {
            foreach ($eventExceptions as &$exception) {
                $key = ['company' => $companyId, 'event' => $eventId, 'occurrence' => $exception['occurrence_start_utc']];
                $userQuery = $this->pdo->prepare('SELECT user_id,response_status FROM calendar_occurrence_user_attendees WHERE company_id=:company AND event_id=:event AND occurrence_start_utc=:occurrence ORDER BY user_id');
                $userQuery->execute($key); $userRows = $userQuery->fetchAll();
                $exception['attendee_user_ids'] = array_map(static fn(array $row): int => (int)$row['user_id'],$userRows);
                $exception['attendee_user_responses'] = array_column($userRows,'response_status','user_id');
                $contactQuery = $this->pdo->prepare('SELECT contact_id FROM calendar_occurrence_contact_attendees WHERE company_id=:company AND event_id=:event AND occurrence_start_utc=:occurrence ORDER BY contact_id');
                $contactQuery->execute($key); $exception['attendee_contact_ids'] = array_map('intval', $contactQuery->fetchAll(PDO::FETCH_COLUMN));
                if ($auth->allowsForCompany('appointments.read', $companyId) || $auth->allowsForCompany('appointments.write', $companyId)) {
                    $resourceQuery = $this->pdo->prepare('SELECT er.resource_id,r.name FROM calendar_occurrence_resources er JOIN appointment_resources r ON r.id=er.resource_id AND r.company_id=er.company_id WHERE er.company_id=:company AND er.event_id=:event AND er.occurrence_start_utc=:occurrence ORDER BY r.name,r.id');
                    $resourceQuery->execute($key); $resources = $resourceQuery->fetchAll();
                    $exception['resource_ids'] = array_map(static fn(array $resource): int => (int)$resource['resource_id'], $resources);
                    $exception['resource_names'] = implode(', ', array_column($resources, 'name'));
                }
            }
            unset($exception);
        }
        unset($eventExceptions);
        return $result;
    }

    private function baseContactAttendees(array $eventIds, int $companyId): array
    {
        if (!$eventIds) return [];
        $slots = []; $params = ['company' => $companyId];
        foreach (array_values(array_unique($eventIds)) as $index => $eventId) { $key = 'event_' . $index; $slots[] = ':' . $key; $params[$key] = $eventId; }
        $query = $this->pdo->prepare('SELECT event_id,contact_id FROM calendar_event_contact_attendees WHERE company_id=:company AND event_id IN (' . implode(',', $slots) . ') ORDER BY event_id,contact_id');
        $query->execute($params); $result = [];
        foreach ($query->fetchAll() as $row) $result[(int)$row['event_id']][] = (int)$row['contact_id'];
        return $result;
    }

    public function saveOccurrence(array $input, int $id, UserContext $user, Authorization $auth, string $ip): void
    {
        $companyId = $this->eventCompany($id, $auth);
        if (!$auth->allowsForCompany('calendar.write', $companyId)) throw new AccessDeniedException('You do not have Calendar edit access for this company.');
        if (isset($input['company_id']) && $input['company_id'] !== '') {
            $requestedCompanyId = $this->companyId($input['company_id'], $auth);
            if ($requestedCompanyId !== $companyId) throw new InvalidArgumentException('The occurrence must stay in its Calendar event company.');
        }
        $occurrenceStart = $this->utcDateTime($input['occurrence_start_utc'] ?? null, 'Occurrence start');
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare("SELECT * FROM calendar_events WHERE id=:id AND company_id=:company AND source_model='manual' AND status='scheduled' FOR UPDATE");
            $select->execute(['id'=>$id,'company'=>$companyId]); $event = $select->fetch();
            if (!$event || $event['recurrence_frequency'] === null) throw new InvalidArgumentException('This is not a scheduled recurring Calendar event.');
            if (!CalendarRecurrence::isOccurrence($event, $occurrenceStart)) throw new InvalidArgumentException('The selected time is not an occurrence in this recurrence series.');

            $title = $this->text($input['title'] ?? null, 'Title', 255, true) ?? '';
            $description = $this->text($input['description'] ?? null, 'Description', 10000) ?? '';
            $location = $this->text($input['location'] ?? null, 'Location', 255) ?? '';
            $timezoneName = (string)($input['timezone'] ?? $event['timezone']); $timezone = $this->timezone($timezoneName);
            $allDay = filter_var($input['all_day'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($allDay === null) throw new InvalidArgumentException('All Day must be true or false.');
            if ($allDay) {
                $startDate = $this->date($input['start_date'] ?? null, 'Start date');
                $endDate = $this->date($input['end_date'] ?? null, 'End date');
                if ($endDate < $startDate) throw new InvalidArgumentException('End date must be on or after the start date.');
                $utc = new DateTimeZone('UTC');
                $starts = (new DateTimeImmutable($startDate . ' 00:00:00', $timezone))->setTimezone($utc)->format('Y-m-d H:i:s');
                $ends = (new DateTimeImmutable($endDate . ' 00:00:00', $timezone))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
            } else {
                $starts = $this->localDateTimeToUtc($input['starts_at_local'] ?? null, $timezone, 'Start date and time');
                $ends = $this->localDateTimeToUtc($input['ends_at_local'] ?? null, $timezone, 'End date and time');
            }
            if ($ends <= $starts) throw new InvalidArgumentException('End date and time must be after the start.');
            if (strtotime($ends . ' UTC') - strtotime($starts . ' UTC') > 366 * 86400) throw new InvalidArgumentException('A Calendar event cannot be longer than 366 days.');
            $organizerId = $this->positiveId($input['organizer_user_id'] ?? null, 'Organizer'); $this->assertCompanyUser($organizerId, $companyId);
            $contactId = empty($input['contact_id']) ? null : $this->positiveId($input['contact_id'], 'Contact');
            if ($contactId !== null) $this->assertCompanyContact($contactId, $companyId);
            $userIds = $this->idList($input['attendee_user_ids'] ?? [], 'User attendees', 100); $contactIds = $this->idList($input['attendee_contact_ids'] ?? [], 'Contact attendees', 100);
            $userIds[] = $organizerId; if ($contactId !== null) $contactIds[] = $contactId;
            $userIds = array_values(array_unique($userIds)); $contactIds = array_values(array_unique($contactIds));
            foreach ($userIds as $attendeeId) $this->assertCompanyUser($attendeeId, $companyId);
            foreach ($contactIds as $attendeeId) $this->assertCompanyContact($attendeeId, $companyId);

            $hasResourceInput = array_key_exists('resource_ids', $input);
            if ($hasResourceInput && !$this->canReadAppointmentResources($companyId, $auth)) throw new AccessDeniedException('Appointment resource access is required to change occurrence resources.');
            $existing = $this->occurrenceExceptionRow($id, $companyId, $occurrenceStart);
            if ($hasResourceInput) $resourceIds = $this->idList($input['resource_ids'], 'Calendar resources', 100);
            elseif ($existing && (bool)$existing['has_overrides']) $resourceIds = $this->occurrenceResourceIds($id, $companyId, $occurrenceStart);
            else $resourceIds = $this->eventResourceIds($id, $companyId);
            foreach ($resourceIds as $resourceId) $this->assertCompanyResource($resourceId, $companyId);

            $old = $existing ? $this->occurrenceSnapshot($id, $companyId, $occurrenceStart) : null;
            $upsert = $this->pdo->prepare("INSERT INTO calendar_event_occurrence_exceptions(company_id,event_id,occurrence_start_utc,status,has_overrides,has_attendee_overrides,title,description,starts_at_utc,ends_at_utc,all_day,timezone,location,organizer_user_id,contact_id,updated_by) VALUES(:company,:event,:occurrence,'scheduled',1,1,:title,:description,:starts,:ends,:all_day,:timezone,:location,:organizer,:contact,:actor) ON DUPLICATE KEY UPDATE status='scheduled',has_overrides=1,has_attendee_overrides=1,title=VALUES(title),description=VALUES(description),starts_at_utc=VALUES(starts_at_utc),ends_at_utc=VALUES(ends_at_utc),all_day=VALUES(all_day),timezone=VALUES(timezone),location=VALUES(location),organizer_user_id=VALUES(organizer_user_id),contact_id=VALUES(contact_id),updated_by=VALUES(updated_by)");
            $upsert->execute(['company'=>$companyId,'event'=>$id,'occurrence'=>$occurrenceStart,'title'=>$title,'description'=>$description,'starts'=>$starts,'ends'=>$ends,'all_day'=>$allDay?1:0,'timezone'=>$timezoneName,'location'=>$location,'organizer'=>$organizerId,'contact'=>$contactId,'actor'=>$user->id]);
            $inheritSeriesResponses = !$existing || !(bool)$existing['has_attendee_overrides'];
            $this->saveOccurrenceAttendees($id, $companyId, $occurrenceStart, $userIds, $contactIds, $inheritSeriesResponses);
            $this->saveOccurrenceResources($id, $companyId, $occurrenceStart, $resourceIds);
            $new = ['occurrence_start_utc'=>$occurrenceStart,'status'=>'scheduled','title'=>$title,'description'=>$description,'starts_at_utc'=>$starts,'ends_at_utc'=>$ends,'all_day'=>$allDay,'timezone'=>$timezoneName,'location'=>$location,'organizer_user_id'=>$organizerId,'contact_id'=>$contactId,'attendee_user_ids'=>$userIds,'attendee_contact_ids'=>$contactIds,'resource_ids'=>$resourceIds];
            $this->audit($companyId,$user->id,$id,'occurrence_updated',$old,$new,$ip);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function setOccurrenceStatus(int $id, string $occurrenceStart, string $status, UserContext $user, Authorization $auth, string $ip): void
    {
        if (!in_array($status, ['cancelled','completed'], true)) throw new InvalidArgumentException('Choose Cancelled or Completed for this occurrence.');
        $companyId = $this->eventCompany($id, $auth);
        if (!$auth->allowsForCompany('calendar.write', $companyId)) throw new AccessDeniedException('You do not have Calendar edit access for this company.');
        $occurrenceStart = $this->utcDateTime($occurrenceStart, 'Occurrence start');
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare("SELECT * FROM calendar_events WHERE id=:id AND company_id=:company AND source_model='manual' AND status='scheduled' FOR UPDATE");
            $query->execute(['id'=>$id,'company'=>$companyId]); $event = $query->fetch();
            if (!$event || $event['recurrence_frequency'] === null || !CalendarRecurrence::isOccurrence($event,$occurrenceStart)) throw new InvalidArgumentException('The selected time is not a scheduled occurrence in this recurrence series.');
            $old = $this->occurrenceSnapshot($id,$companyId,$occurrenceStart);
            $upsert = $this->pdo->prepare("INSERT INTO calendar_event_occurrence_exceptions(company_id,event_id,occurrence_start_utc,status,updated_by) VALUES(:company,:event,:occurrence,:status,:actor) ON DUPLICATE KEY UPDATE status=VALUES(status),updated_by=VALUES(updated_by)");
            $upsert->execute(['company'=>$companyId,'event'=>$id,'occurrence'=>$occurrenceStart,'status'=>$status,'actor'=>$user->id]);
            $this->audit($companyId,$user->id,$id,'occurrence_'.$status,$old,['occurrence_start_utc'=>$occurrenceStart,'status'=>$status],$ip);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function clearOccurrenceException(int $id, string $occurrenceStart, UserContext $user, Authorization $auth, string $ip): void
    {
        $companyId = $this->eventCompany($id, $auth);
        if (!$auth->allowsForCompany('calendar.write', $companyId)) throw new AccessDeniedException('You do not have Calendar edit access for this company.');
        $occurrenceStart = $this->utcDateTime($occurrenceStart, 'Occurrence start');
        $this->pdo->beginTransaction();
        try {
            $eventQuery = $this->pdo->prepare("SELECT * FROM calendar_events WHERE id=:id AND company_id=:company AND source_model='manual' FOR UPDATE");
            $eventQuery->execute(['id'=>$id,'company'=>$companyId]); $event = $eventQuery->fetch();
            if (!$event || $event['recurrence_frequency'] === null || !CalendarRecurrence::isOccurrence($event,$occurrenceStart)) throw new InvalidArgumentException('The selected time is not an occurrence in this recurrence series.');
            $old = $this->occurrenceSnapshot($id,$companyId,$occurrenceStart);
            if ($old === null) throw new InvalidArgumentException('This occurrence has no saved exception.');
            $key = ['company'=>$companyId,'event'=>$id,'occurrence'=>$occurrenceStart];
            foreach (['calendar_occurrence_user_attendees','calendar_occurrence_contact_attendees','calendar_occurrence_resources'] as $table) {
                $delete = $this->pdo->prepare("DELETE FROM {$table} WHERE company_id=:company AND event_id=:event AND occurrence_start_utc=:occurrence"); $delete->execute($key);
            }
            $deleteException = $this->pdo->prepare('DELETE FROM calendar_event_occurrence_exceptions WHERE company_id=:company AND event_id=:event AND occurrence_start_utc=:occurrence');
            $deleteException->execute($key);
            $this->audit($companyId,$user->id,$id,'occurrence_exception_cleared',$old,['occurrence_start_utc'=>$occurrenceStart],$ip);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function save(array $input, ?int $id, UserContext $user, Authorization $auth, string $ip): array
    {
        $companyId = $this->companyId($input['company_id'] ?? $user->defaultCompanyId, $auth);
        if (!$auth->allowsForCompany('calendar.write', $companyId)) throw new AccessDeniedException('You do not have Calendar edit access for this company.');
        $company = $this->companyRow($companyId);
        $title = $this->text($input['title'] ?? null, 'Title', 255, true) ?? '';
        $description = $this->text($input['description'] ?? null, 'Description', 10000) ?? '';
        $location = $this->text($input['location'] ?? null, 'Location', 255) ?? '';
        $timezoneName = (string)($input['timezone'] ?? $company['timezone']);
        $timezone = $this->timezone($timezoneName);
        $rawAllDay = $input['all_day'] ?? false;
        $allDay = filter_var($rawAllDay, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($allDay === null) throw new InvalidArgumentException('All Day must be true or false.');
        if ($allDay) {
            $startDate = $this->date($input['start_date'] ?? null, 'Start date');
            $endDate = $this->date($input['end_date'] ?? null, 'End date');
            if ($endDate < $startDate) throw new InvalidArgumentException('End date must be on or after the start date.');
            $startLocal = new DateTimeImmutable($startDate . ' 00:00:00', $timezone);
            $exclusiveEndLocal = (new DateTimeImmutable($endDate . ' 00:00:00', $timezone))->modify('+1 day');
            $utc = new DateTimeZone('UTC');
            $starts = $startLocal->setTimezone($utc)->format('Y-m-d H:i:s');
            $ends = $exclusiveEndLocal->setTimezone($utc)->format('Y-m-d H:i:s');
        } else {
            $starts = $this->localDateTimeToUtc($input['starts_at_local'] ?? null, $timezone, 'Start date and time');
            $ends = $this->localDateTimeToUtc($input['ends_at_local'] ?? null, $timezone, 'End date and time');
        }
        if ($ends <= $starts) throw new InvalidArgumentException('End date and time must be after the start.');
        if (strtotime($ends . ' UTC') - strtotime($starts . ' UTC') > 366 * 86400) throw new InvalidArgumentException('A Calendar event cannot be longer than 366 days.');
        $startDate = $allDay ? $startDate : (new DateTimeImmutable($starts, new DateTimeZone('UTC')))->setTimezone($timezone)->format('Y-m-d');
        $recurrence = $this->recurrence($input, $startDate, $timezone);
        $organizerId = $this->positiveId($input['organizer_user_id'] ?? null, 'Organizer');
        $this->assertCompanyUser($organizerId, $companyId);
        $contactId = empty($input['contact_id']) ? null : $this->positiveId($input['contact_id'], 'Contact');
        if ($contactId !== null) $this->assertCompanyContact($contactId, $companyId);
        $hasResourceInput = array_key_exists('resource_ids', $input);
        $resourceIds = $hasResourceInput ? $this->idList($input['resource_ids'], 'Calendar resources', 100) : [];
        if ($hasResourceInput && !$this->canReadAppointmentResources($companyId, $auth)) throw new AccessDeniedException('Appointment resource access is required to link Calendar resources.');
        foreach ($resourceIds as $resourceId) $this->assertCompanyResource($resourceId, $companyId);
        $userIds = $this->idList($input['attendee_user_ids'] ?? [], 'User attendees', 100);
        $contactIds = $this->idList($input['attendee_contact_ids'] ?? [], 'Contact attendees', 100);
        $userIds[] = $organizerId;
        if ($contactId !== null) $contactIds[] = $contactId;
        $userIds = array_values(array_unique($userIds)); $contactIds = array_values(array_unique($contactIds));
        foreach ($userIds as $attendeeId) $this->assertCompanyUser($attendeeId, $companyId);
        foreach ($contactIds as $attendeeId) $this->assertCompanyContact($attendeeId, $companyId);

        $this->pdo->beginTransaction();
        try {
            if ($id === null) {
                $query = $this->pdo->prepare("INSERT INTO calendar_events(company_id,title,description,starts_at_utc,ends_at_utc,all_day,timezone,location,organizer_user_id,contact_id,recurrence_frequency,recurrence_interval,recurrence_weekdays,recurrence_month_by,recurrence_day,recurrence_weekday,recurrence_week_ordinal,recurrence_end_type,recurrence_count,recurrence_until_date,source_model,source_record_id,status,created_by,updated_by) VALUES(:company,:title,:description,:starts,:ends,:all_day,:timezone,:location,:organizer,:contact,:recurrence_frequency,:recurrence_interval,:recurrence_weekdays,:recurrence_month_by,:recurrence_day,:recurrence_weekday,:recurrence_week_ordinal,:recurrence_end_type,:recurrence_count,:recurrence_until_date,'manual',NULL,'scheduled',:created_by,:updated_by)");
                $query->execute(['company'=>$companyId,'title'=>$title,'description'=>$description,'starts'=>$starts,'ends'=>$ends,'all_day'=>$allDay?1:0,'timezone'=>$timezoneName,'location'=>$location,'organizer'=>$organizerId,'contact'=>$contactId]+$recurrence+['created_by'=>$user->id,'updated_by'=>$user->id]);
                $eventId = (int)$this->pdo->lastInsertId();
                $this->saveAttendees($eventId, $companyId, $userIds, $contactIds);
                if ($hasResourceInput) $this->saveResources($eventId, $companyId, $resourceIds);
                $this->audit($companyId, $user->id, $eventId, 'created', null, ['title'=>$title,'contact_id'=>$contactId,'resource_ids'=>$resourceIds,'starts_at_utc'=>$starts,'ends_at_utc'=>$ends,'all_day'=>$allDay,'recurrence'=>$recurrence], $ip);
            } else {
                $select = $this->pdo->prepare('SELECT * FROM calendar_events WHERE id=:id AND company_id=:company FOR UPDATE');
                $select->execute(['id'=>$id,'company'=>$companyId]); $old = $select->fetch();
                if (!$old) throw new InvalidArgumentException('Calendar event was not found in this company.');
                $old['resource_ids'] = $this->eventResourceIds($id, $companyId);
                if ($old['source_model'] !== 'manual') throw new InvalidArgumentException('Appointment events are managed from the Appointments app.');
                if ($old['status'] !== 'scheduled') throw new InvalidArgumentException('Only scheduled Calendar events can be edited.');
                if ($old['recurrence_frequency'] !== null) {
                    $exceptions = $this->pdo->prepare('SELECT 1 FROM calendar_event_occurrence_exceptions WHERE event_id=:event AND company_id=:company LIMIT 1');
                    $exceptions->execute(['event'=>$id,'company'=>$companyId]);
                    if ($exceptions->fetchColumn()) {
                        $seriesShapeChanged = $old['starts_at_utc'] !== $starts
                            || $old['ends_at_utc'] !== $ends
                            || (int)$old['all_day'] !== ($allDay ? 1 : 0)
                            || (string)$old['timezone'] !== $timezoneName;
                        foreach (array_keys($recurrence) as $field) {
                            if (($old[$field] ?? null) != ($recurrence[$field] ?? null)) $seriesShapeChanged = true;
                        }
                        if ($seriesShapeChanged) {
                            throw new InvalidArgumentException('Reset saved occurrence changes before changing the series date, duration, time zone, or repeat rule.');
                        }
                    }
                }
                $query = $this->pdo->prepare("UPDATE calendar_events SET title=:title,description=:description,starts_at_utc=:starts,ends_at_utc=:ends,all_day=:all_day,timezone=:timezone,location=:location,organizer_user_id=:organizer,contact_id=:contact,recurrence_frequency=:recurrence_frequency,recurrence_interval=:recurrence_interval,recurrence_weekdays=:recurrence_weekdays,recurrence_month_by=:recurrence_month_by,recurrence_day=:recurrence_day,recurrence_weekday=:recurrence_weekday,recurrence_week_ordinal=:recurrence_week_ordinal,recurrence_end_type=:recurrence_end_type,recurrence_count=:recurrence_count,recurrence_until_date=:recurrence_until_date,updated_by=:actor WHERE id=:id AND company_id=:company AND source_model='manual' AND status='scheduled'");
                $query->execute(['title'=>$title,'description'=>$description,'starts'=>$starts,'ends'=>$ends,'all_day'=>$allDay?1:0,'timezone'=>$timezoneName,'location'=>$location,'organizer'=>$organizerId,'contact'=>$contactId]+$recurrence+['actor'=>$user->id,'id'=>$id,'company'=>$companyId]);
                $eventId = $id;
                if ($hasResourceInput) $this->saveResources($eventId, $companyId, $resourceIds);
                else $resourceIds = $this->eventResourceIds($eventId, $companyId);
                $this->saveAttendees($eventId, $companyId, $userIds, $contactIds);
                $this->audit($companyId, $user->id, $eventId, 'updated', $old, ['title'=>$title,'contact_id'=>$contactId,'resource_ids'=>$resourceIds,'starts_at_utc'=>$starts,'ends_at_utc'=>$ends,'all_day'=>$allDay,'recurrence'=>$recurrence], $ip);
            }
            $this->pdo->commit();
            return $this->event($eventId, $auth);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function cancel(int $id, string $status, UserContext $user, Authorization $auth, string $ip): void
    {
        if (!in_array($status, ['cancelled','completed'], true)) throw new InvalidArgumentException('Choose Cancelled or Completed.');
        $companyId = $this->eventCompany($id, $auth);
        if (!$auth->allowsForCompany('calendar.write', $companyId)) throw new AccessDeniedException('You do not have Calendar edit access for this company.');
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare("SELECT * FROM calendar_events WHERE id=:id AND company_id=:company AND source_model='manual' AND status='scheduled' FOR UPDATE");
            $query->execute(['id'=>$id,'company'=>$companyId]); $old = $query->fetch();
            if (!$old) throw new InvalidArgumentException('Only scheduled manual Calendar events can be completed or cancelled.');
            $update = $this->pdo->prepare("UPDATE calendar_events SET status=:status,updated_by=:actor WHERE id=:id AND company_id=:company AND status='scheduled'");
            $update->execute(['status'=>$status,'actor'=>$user->id,'id'=>$id,'company'=>$companyId]);
            $this->audit($companyId, $user->id, $id, $status, $old, ['status'=>$status], $ip);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function respond(int $id, string $response, UserContext $user, Authorization $auth, string $ip, ?string $occurrenceStart = null): string
    {
        if (!in_array($response, ['accepted', 'tentative', 'declined'], true)) {
            throw new InvalidArgumentException('Choose Accepted, Tentative, or Declined.');
        }
        $companyId = $this->eventCompany($id, $auth);
        $this->assertReadable($companyId, $auth);
        $this->pdo->beginTransaction();
        try {
            $eventQuery = $this->pdo->prepare('SELECT * FROM calendar_events WHERE id=:event AND company_id=:company FOR UPDATE');
            $eventQuery->execute(['event' => $id, 'company' => $companyId]);
            $event = $eventQuery->fetch();
            if ($event === false) throw new InvalidArgumentException('Calendar event was not found in this company.');
            if ($event['status'] !== 'scheduled') throw new InvalidArgumentException('Only scheduled events can receive an RSVP response.');
            if ($occurrenceStart !== null && $occurrenceStart !== '') {
                $occurrenceStart = $this->utcDateTime($occurrenceStart,'Occurrence start');
                if ($event['recurrence_frequency'] === null || !CalendarRecurrence::isOccurrence($event,$occurrenceStart)) throw new InvalidArgumentException('The selected time is not an occurrence in this recurrence series.');
                $exception = $this->occurrenceExceptionRow($id,$companyId,$occurrenceStart);
                if ($exception !== null && $exception['status'] !== 'scheduled') throw new InvalidArgumentException('Only scheduled occurrences can receive an RSVP response.');
                $useOccurrenceAttendees = $exception !== null && (bool)$exception['has_attendee_overrides'];
                $table = $useOccurrenceAttendees ? 'calendar_occurrence_user_attendees' : 'calendar_event_user_attendees';
                $attendeeQuery = $useOccurrenceAttendees
                    ? $this->pdo->prepare('SELECT response_status FROM calendar_occurrence_user_attendees WHERE event_id=:event AND company_id=:company AND occurrence_start_utc=:occurrence AND user_id=:user FOR UPDATE')
                    : $this->pdo->prepare('SELECT response_status FROM calendar_event_user_attendees WHERE event_id=:event AND company_id=:company AND user_id=:user FOR UPDATE');
                $attendeeParams = ['event'=>$id,'company'=>$companyId,'user'=>$user->id]; if ($useOccurrenceAttendees) $attendeeParams['occurrence'] = $occurrenceStart;
                $attendeeQuery->execute($attendeeParams); $previous = $attendeeQuery->fetchColumn();
                if ($previous === false) throw new AccessDeniedException('Only an invited user attendee for this Calendar occurrence can respond.');
                if ($previous === $response) { $this->pdo->commit(); return $response; }
                if (!$useOccurrenceAttendees) {
                    $upsert = $this->pdo->prepare("INSERT INTO calendar_event_occurrence_exceptions(company_id,event_id,occurrence_start_utc,status,has_overrides,has_attendee_overrides,updated_by) VALUES(:company,:event,:occurrence,'scheduled',0,1,:actor) ON DUPLICATE KEY UPDATE has_attendee_overrides=1,updated_by=VALUES(updated_by)");
                    $upsert->execute(['company'=>$companyId,'event'=>$id,'occurrence'=>$occurrenceStart,'actor'=>$user->id]);
                    $copyUsers = $this->pdo->prepare('INSERT INTO calendar_occurrence_user_attendees(company_id,event_id,occurrence_start_utc,user_id,response_status) SELECT company_id,event_id,:occurrence,user_id,response_status FROM calendar_event_user_attendees WHERE company_id=:company AND event_id=:event');
                    $copyUsers->execute(['occurrence'=>$occurrenceStart,'company'=>$companyId,'event'=>$id]);
                    $copyContacts = $this->pdo->prepare('INSERT INTO calendar_occurrence_contact_attendees(company_id,event_id,occurrence_start_utc,contact_id,response_status) SELECT company_id,event_id,:occurrence,contact_id,response_status FROM calendar_event_contact_attendees WHERE company_id=:company AND event_id=:event');
                    $copyContacts->execute(['occurrence'=>$occurrenceStart,'company'=>$companyId,'event'=>$id]);
                    $table = 'calendar_occurrence_user_attendees';
                }
                $update = $this->pdo->prepare("UPDATE {$table} SET response_status=:response WHERE event_id=:event AND company_id=:company AND occurrence_start_utc=:occurrence AND user_id=:user");
                $update->execute(['response'=>$response,'event'=>$id,'company'=>$companyId,'occurrence'=>$occurrenceStart,'user'=>$user->id]);
                $this->audit($companyId,$user->id,$id,'occurrence_attendee_response',['occurrence_start_utc'=>$occurrenceStart,'response_status'=>$previous],['occurrence_start_utc'=>$occurrenceStart,'response_status'=>$response],$ip);
            } else {
                $attendeeQuery = $this->pdo->prepare('SELECT response_status FROM calendar_event_user_attendees WHERE event_id=:event AND company_id=:company AND user_id=:user FOR UPDATE');
                $attendeeQuery->execute(['event' => $id, 'company' => $companyId, 'user' => $user->id]); $previous = $attendeeQuery->fetchColumn();
                if ($previous === false) throw new AccessDeniedException('Only an invited user attendee can respond to this Calendar event.');
                if ($previous === $response) { $this->pdo->commit(); return $response; }
                $update = $this->pdo->prepare('UPDATE calendar_event_user_attendees SET response_status=:response WHERE event_id=:event AND company_id=:company AND user_id=:user');
                $update->execute(['response' => $response, 'event' => $id, 'company' => $companyId, 'user' => $user->id]);
                $this->audit($companyId, $user->id, $id, 'attendee_response', ['response_status' => $previous], ['response_status' => $response], $ip);
            }
            $this->pdo->commit();
            return $response;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function event(int $id, Authorization $auth): array
    {
        $query = $this->pdo->prepare('SELECT e.*,c.name AS contact_name,oc.name AS organizer_name FROM calendar_events e LEFT JOIN contacts c ON c.id=e.contact_id LEFT JOIN users u ON u.id=e.organizer_user_id LEFT JOIN contacts oc ON oc.id=u.contact_id WHERE e.id=:id');
        $query->execute(['id'=>$id]); $event = $query->fetch();
        if (!$event || !$auth->canAccessCompany((int)$event['company_id']) || !$auth->allowsForCompany('calendar.read', (int)$event['company_id']) && !$auth->allowsForCompany('calendar.write', (int)$event['company_id'])) throw new AccessDeniedException('Calendar event is not available in your access scope.');
        $users=$this->pdo->prepare('SELECT user_id FROM calendar_event_user_attendees WHERE event_id=:event AND company_id=:company ORDER BY user_id'); $users->execute(['event'=>$id,'company'=>$event['company_id']]);
        $contacts=$this->pdo->prepare('SELECT contact_id FROM calendar_event_contact_attendees WHERE event_id=:event AND company_id=:company ORDER BY contact_id'); $contacts->execute(['event'=>$id,'company'=>$event['company_id']]);
        $event['attendee_user_ids']=array_map('intval',$users->fetchAll(PDO::FETCH_COLUMN)); $event['attendee_contact_ids']=array_map('intval',$contacts->fetchAll(PDO::FETCH_COLUMN));
        $event['resource_ids'] = $this->canReadAppointmentResources((int)$event['company_id'], $auth) ? $this->eventResourceIds($id, (int)$event['company_id']) : [];
        $weekdays = $event['recurrence_weekdays'] === null ? [] : json_decode((string)$event['recurrence_weekdays'], true);
        $event['recurrence_weekdays'] = is_array($weekdays) ? array_map('intval', $weekdays) : [];
        $event['recurrent'] = $event['recurrence_frequency'] !== null;
        foreach (['recurrence_interval','recurrence_day','recurrence_weekday','recurrence_week_ordinal','recurrence_count'] as $key) {
            $event[$key] = $event[$key] === null ? null : (int)$event[$key];
        }
        $event['starts_at_local']=$this->toLocal((string)$event['starts_at_utc'],(string)$event['timezone']); $event['ends_at_local']=$this->toLocal((string)$event['ends_at_utc'],(string)$event['timezone']);
        $event['all_day'] = (bool)$event['all_day'];
        $event['start_date_local'] = substr($event['starts_at_local'], 0, 10);
        $event['end_date_local'] = $event['all_day']
            ? (new DateTimeImmutable(substr($event['ends_at_local'], 0, 10), new DateTimeZone((string)$event['timezone'])))->modify('-1 day')->format('Y-m-d')
            : substr($event['ends_at_local'], 0, 10);
        return $event;
    }

    /** Validate the full recurrence form once and map every recurrence field to its column. */
    private function recurrence(array $input, string $startDate, DateTimeZone $timezone): array
    {
        $raw = $input['recurrent'] ?? false;
        $enabled = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) throw new InvalidArgumentException('Repeat must be true or false.');
        $columns = [
            'recurrence_frequency'=>null, 'recurrence_interval'=>null, 'recurrence_weekdays'=>null,
            'recurrence_month_by'=>null, 'recurrence_day'=>null, 'recurrence_weekday'=>null,
            'recurrence_week_ordinal'=>null, 'recurrence_end_type'=>null, 'recurrence_count'=>null,
            'recurrence_until_date'=>null,
        ];
        if (!$enabled) return $columns;

        $frequency = (string)($input['recurrence_frequency'] ?? '');
        if (!in_array($frequency, ['daily','weekly','monthly','yearly'], true)) throw new InvalidArgumentException('Choose a valid Calendar repeat period.');
        $interval = filter_var($input['recurrence_interval'] ?? null, FILTER_VALIDATE_INT);
        if ($interval === false || $interval === null || $interval < 1 || $interval > 99) throw new InvalidArgumentException('Repeat interval must be between 1 and 99.');
        $rule = ['recurrence_frequency'=>$frequency, 'recurrence_interval'=>(int)$interval];
        $anchorWeekday = (int)(new DateTimeImmutable($startDate, $timezone))->format('N');
        if ($frequency === 'weekly') {
            $weekdays = CalendarRecurrence::weekdays($input['recurrence_weekdays'] ?? null, $anchorWeekday, true);
            $rule['recurrence_weekdays'] = $weekdays;
            $columns['recurrence_weekdays'] = json_encode($weekdays, JSON_THROW_ON_ERROR);
        }
        if ($frequency === 'monthly') {
            $monthBy = (string)($input['recurrence_month_by'] ?? '');
            if (!in_array($monthBy, ['day','weekday'], true)) throw new InvalidArgumentException('Choose a monthly recurrence pattern.');
            $rule['recurrence_month_by'] = $monthBy;
            if ($monthBy === 'day') {
                $day = filter_var($input['recurrence_day'] ?? null, FILTER_VALIDATE_INT);
                if ($day === false || $day === null || $day < 1 || $day > 31) throw new InvalidArgumentException('Day of month must be from 1 to 31.');
                $rule['recurrence_day'] = (int)$day;
                $columns['recurrence_day'] = (int)$day;
            } else {
                $weekday = filter_var($input['recurrence_weekday'] ?? null, FILTER_VALIDATE_INT);
                $ordinal = filter_var($input['recurrence_week_ordinal'] ?? null, FILTER_VALIDATE_INT);
                if ($weekday === false || $weekday === null || $weekday < 1 || $weekday > 7 || $ordinal === false || $ordinal === null || !in_array((int)$ordinal, [-1,1,2,3,4], true)) throw new InvalidArgumentException('Choose a valid monthly weekday and position.');
                $rule['recurrence_weekday'] = (int)$weekday;
                $rule['recurrence_week_ordinal'] = (int)$ordinal;
                $columns['recurrence_weekday'] = (int)$weekday;
                $columns['recurrence_week_ordinal'] = (int)$ordinal;
            }
        }
        $rule['recurrence_end_type'] = (string)($input['recurrence_end_type'] ?? '');
        if ($rule['recurrence_end_type'] === 'count') {
            $count = filter_var($input['recurrence_count'] ?? null, FILTER_VALIDATE_INT);
            if ($count === false || $count === null || $count < 1 || $count > 5480) throw new InvalidArgumentException('Repeat count must be between 1 and 5,480.');
            $rule['recurrence_count'] = (int)$count;
            $columns['recurrence_count'] = (int)$count;
        } elseif ($rule['recurrence_end_type'] === 'date') {
            $rule['recurrence_until_date'] = $this->date($input['recurrence_until_date'] ?? null, 'Repeat until date');
        } elseif ($rule['recurrence_end_type'] !== 'forever') {
            throw new InvalidArgumentException('Choose whether the recurrence ends by count, date, or continues forever.');
        }
        $columns['recurrence_frequency'] = $frequency;
        $columns['recurrence_interval'] = (int)$interval;
        $columns['recurrence_month_by'] = $rule['recurrence_month_by'] ?? null;
        $columns['recurrence_weekday'] = $rule['recurrence_weekday'] ?? null;
        $columns['recurrence_week_ordinal'] = $rule['recurrence_week_ordinal'] ?? null;
        $columns['recurrence_end_type'] = $rule['recurrence_end_type'];
        $columns['recurrence_until_date'] = CalendarRecurrence::untilDate($startDate, $rule);
        return $columns;
    }

    private function eventCompany(int $id, Authorization $auth): int
    {
        $query = $this->pdo->prepare('SELECT company_id FROM calendar_events WHERE id=:id'); $query->execute(['id'=>$id]); $companyId=$query->fetchColumn();
        if ($companyId === false || !$auth->canAccessCompany((int)$companyId)) throw new InvalidArgumentException('Calendar event was not found in your company scope.');
        return (int)$companyId;
    }

    private function saveAttendees(int $eventId, int $companyId, array $userIds, array $contactIds): void
    {
        $existingUsers = $this->pdo->prepare('SELECT user_id FROM calendar_event_user_attendees WHERE event_id=:event AND company_id=:company');
        $existingUsers->execute(['event'=>$eventId,'company'=>$companyId]);
        $keepUsers = array_fill_keys($userIds, true);
        $deleteUser = $this->pdo->prepare('DELETE FROM calendar_event_user_attendees WHERE event_id=:event AND company_id=:company AND user_id=:user');
        foreach ($existingUsers->fetchAll(PDO::FETCH_COLUMN) as $attendeeId) if (!isset($keepUsers[(int)$attendeeId])) $deleteUser->execute(['event'=>$eventId,'company'=>$companyId,'user'=>(int)$attendeeId]);
        $users = $this->pdo->prepare("INSERT INTO calendar_event_user_attendees(company_id,event_id,user_id,response_status) VALUES(:company,:event,:user,'needs_action') ON DUPLICATE KEY UPDATE company_id=VALUES(company_id)");
        foreach ($userIds as $attendeeId) $users->execute(['company'=>$companyId,'event'=>$eventId,'user'=>$attendeeId]);

        $existingContacts = $this->pdo->prepare('SELECT contact_id FROM calendar_event_contact_attendees WHERE event_id=:event AND company_id=:company');
        $existingContacts->execute(['event'=>$eventId,'company'=>$companyId]);
        $keepContacts = array_fill_keys($contactIds, true);
        $deleteContact = $this->pdo->prepare('DELETE FROM calendar_event_contact_attendees WHERE event_id=:event AND company_id=:company AND contact_id=:contact');
        foreach ($existingContacts->fetchAll(PDO::FETCH_COLUMN) as $attendeeId) if (!isset($keepContacts[(int)$attendeeId])) $deleteContact->execute(['event'=>$eventId,'company'=>$companyId,'contact'=>(int)$attendeeId]);
        $contacts = $this->pdo->prepare("INSERT INTO calendar_event_contact_attendees(company_id,event_id,contact_id,response_status) VALUES(:company,:event,:contact,'needs_action') ON DUPLICATE KEY UPDATE company_id=VALUES(company_id)");
        foreach ($contactIds as $attendeeId) $contacts->execute(['company'=>$companyId,'event'=>$eventId,'contact'=>$attendeeId]);
    }

    private function companiesFor(UserContext $user, Authorization $auth): array
    {
        if ($user->superuser) return $this->pdo->query('SELECT id,name,timezone FROM companies WHERE active=1 ORDER BY name,id')->fetchAll();
        if (!$user->companyIds) return [];
        $params=[]; $slots=[];
        foreach ($user->companyIds as $index=>$id) { $slots[]=':company_'.$index; $params['company_'.$index]=$id; }
        $query=$this->pdo->prepare('SELECT id,name,timezone FROM companies WHERE active=1 AND id IN ('.implode(',',$slots).') ORDER BY name,id'); $query->execute($params);
        return array_values(array_filter($query->fetchAll(), static fn(array $row): bool => $auth->allowsForCompany('calendar.read',(int)$row['id']) || $auth->allowsForCompany('calendar.write',(int)$row['id'])));
    }

    private function companyId(mixed $raw, Authorization $auth): int
    {
        $id = filter_var($raw, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1 || !$auth->canAccessCompany((int)$id)) throw new InvalidArgumentException('Select an active company in your allowed company scope.');
        return (int)$id;
    }

    private function companyRow(int $companyId): array
    {
        $query=$this->pdo->prepare('SELECT id,name,timezone FROM companies WHERE id=:id AND active=1'); $query->execute(['id'=>$companyId]); $company=$query->fetch();
        if (!$company) throw new InvalidArgumentException('Company was not found or is archived.');
        $company['id']=(int)$company['id']; return $company;
    }

    private function assertReadable(int $companyId, Authorization $auth): void
    {
        if (!$auth->allowsForCompany('calendar.read',$companyId) && !$auth->allowsForCompany('calendar.write',$companyId)) throw new AccessDeniedException('You do not have Calendar access for this company.');
    }

    private function assertCompanyUser(int $userId, int $companyId): void
    {
        $query=$this->pdo->prepare("SELECT 1 FROM users u JOIN user_company_access a ON a.user_id=u.id AND a.company_id=:company WHERE u.id=:user AND u.status IN ('active','confirmed')"); $query->execute(['user'=>$userId,'company'=>$companyId]);
        if (!$query->fetchColumn()) throw new InvalidArgumentException('Select an active user who can access this company.');
    }

    private function assertCompanyContact(int $contactId, int $companyId): void
    {
        $query=$this->pdo->prepare('SELECT 1 FROM contacts WHERE id=:contact AND active=1 AND (company_id=:company OR company_id IS NULL)'); $query->execute(['contact'=>$contactId,'company'=>$companyId]);
        if (!$query->fetchColumn()) throw new InvalidArgumentException('Select an active Contact in this company or a shared Contact.');
    }

    private function canReadAppointmentResources(int $companyId, Authorization $auth): bool
    {
        return $auth->allowsForCompany('appointments.read', $companyId) || $auth->allowsForCompany('appointments.write', $companyId);
    }

    private function assertCompanyResource(int $resourceId, int $companyId): void
    {
        $query = $this->pdo->prepare('SELECT 1 FROM appointment_resources WHERE id=:resource AND company_id=:company AND active=1');
        $query->execute(['resource' => $resourceId, 'company' => $companyId]);
        if (!$query->fetchColumn()) throw new InvalidArgumentException('Select an active Appointment resource in this company.');
    }

    private function eventResourceIds(int $eventId, int $companyId): array
    {
        $query = $this->pdo->prepare('SELECT resource_id FROM calendar_event_resources WHERE event_id=:event AND company_id=:company ORDER BY resource_id');
        $query->execute(['event' => $eventId, 'company' => $companyId]);
        return array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
    }

    private function occurrenceExceptionRow(int $eventId, int $companyId, string $occurrenceStart): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM calendar_event_occurrence_exceptions WHERE company_id=:company AND event_id=:event AND occurrence_start_utc=:occurrence');
        $query->execute(['company'=>$companyId,'event'=>$eventId,'occurrence'=>$occurrenceStart]);
        $row = $query->fetch(); return $row ?: null;
    }

    private function occurrenceSnapshot(int $eventId, int $companyId, string $occurrenceStart): ?array
    {
        $row = $this->occurrenceExceptionRow($eventId,$companyId,$occurrenceStart);
        if ($row === null) return null;
        $key = ['company'=>$companyId,'event'=>$eventId,'occurrence'=>$occurrenceStart];
        $users = $this->pdo->prepare('SELECT user_id,response_status FROM calendar_occurrence_user_attendees WHERE company_id=:company AND event_id=:event AND occurrence_start_utc=:occurrence ORDER BY user_id'); $users->execute($key);
        $contacts = $this->pdo->prepare('SELECT contact_id,response_status FROM calendar_occurrence_contact_attendees WHERE company_id=:company AND event_id=:event AND occurrence_start_utc=:occurrence ORDER BY contact_id'); $contacts->execute($key);
        $row['attendees'] = $users->fetchAll(); $row['contact_attendees'] = $contacts->fetchAll(); $row['resource_ids'] = $this->occurrenceResourceIds($eventId,$companyId,$occurrenceStart);
        return $row;
    }

    private function occurrenceResourceIds(int $eventId, int $companyId, string $occurrenceStart): array
    {
        $query = $this->pdo->prepare('SELECT resource_id FROM calendar_occurrence_resources WHERE company_id=:company AND event_id=:event AND occurrence_start_utc=:occurrence ORDER BY resource_id');
        $query->execute(['company'=>$companyId,'event'=>$eventId,'occurrence'=>$occurrenceStart]);
        return array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN));
    }

    private function saveOccurrenceAttendees(int $eventId, int $companyId, string $occurrenceStart, array $userIds, array $contactIds, bool $inheritSeriesResponses): void
    {
        $key = ['company'=>$companyId,'event'=>$eventId,'occurrence'=>$occurrenceStart];
        $userResponses = []; $contactResponses = [];
        if ($inheritSeriesResponses) {
            $seriesUsers = $this->pdo->prepare('SELECT user_id,response_status FROM calendar_event_user_attendees WHERE company_id=:company AND event_id=:event'); $seriesUsers->execute(['company'=>$companyId,'event'=>$eventId]);
            foreach ($seriesUsers->fetchAll() as $row) $userResponses[(int)$row['user_id']] = $row['response_status'];
            $seriesContacts = $this->pdo->prepare('SELECT contact_id,response_status FROM calendar_event_contact_attendees WHERE company_id=:company AND event_id=:event'); $seriesContacts->execute(['company'=>$companyId,'event'=>$eventId]);
            foreach ($seriesContacts->fetchAll() as $row) $contactResponses[(int)$row['contact_id']] = $row['response_status'];
        }
        $oldUsers = $this->pdo->prepare('SELECT user_id,response_status FROM calendar_occurrence_user_attendees WHERE company_id=:company AND event_id=:event AND occurrence_start_utc=:occurrence'); $oldUsers->execute($key);
        foreach ($oldUsers->fetchAll() as $row) $userResponses[(int)$row['user_id']] = $row['response_status'];
        $oldContacts = $this->pdo->prepare('SELECT contact_id,response_status FROM calendar_occurrence_contact_attendees WHERE company_id=:company AND event_id=:event AND occurrence_start_utc=:occurrence'); $oldContacts->execute($key);
        foreach ($oldContacts->fetchAll() as $row) $contactResponses[(int)$row['contact_id']] = $row['response_status'];
        $this->pdo->prepare('DELETE FROM calendar_occurrence_user_attendees WHERE company_id=:company AND event_id=:event AND occurrence_start_utc=:occurrence')->execute($key);
        $this->pdo->prepare('DELETE FROM calendar_occurrence_contact_attendees WHERE company_id=:company AND event_id=:event AND occurrence_start_utc=:occurrence')->execute($key);
        $userInsert = $this->pdo->prepare('INSERT INTO calendar_occurrence_user_attendees(company_id,event_id,occurrence_start_utc,user_id,response_status) VALUES(:company,:event,:occurrence,:attendee,:response)');
        foreach ($userIds as $attendeeId) $userInsert->execute($key+['attendee'=>$attendeeId,'response'=>$userResponses[$attendeeId]??'needs_action']);
        $contactInsert = $this->pdo->prepare('INSERT INTO calendar_occurrence_contact_attendees(company_id,event_id,occurrence_start_utc,contact_id,response_status) VALUES(:company,:event,:occurrence,:attendee,:response)');
        foreach ($contactIds as $attendeeId) $contactInsert->execute($key+['attendee'=>$attendeeId,'response'=>$contactResponses[$attendeeId]??'needs_action']);
    }

    private function saveOccurrenceResources(int $eventId, int $companyId, string $occurrenceStart, array $resourceIds): void
    {
        $key = ['company'=>$companyId,'event'=>$eventId,'occurrence'=>$occurrenceStart];
        $this->pdo->prepare('DELETE FROM calendar_occurrence_resources WHERE company_id=:company AND event_id=:event AND occurrence_start_utc=:occurrence')->execute($key);
        $insert = $this->pdo->prepare('INSERT INTO calendar_occurrence_resources(company_id,event_id,occurrence_start_utc,resource_id) VALUES(:company,:event,:occurrence,:resource)');
        foreach ($resourceIds as $resourceId) $insert->execute($key+['resource'=>$resourceId]);
    }

    private function saveResources(int $eventId, int $companyId, array $resourceIds): void
    {
        $delete = $this->pdo->prepare('DELETE FROM calendar_event_resources WHERE event_id=:event AND company_id=:company');
        $delete->execute(['event' => $eventId, 'company' => $companyId]);
        if (!$resourceIds) return;
        $insert = $this->pdo->prepare('INSERT INTO calendar_event_resources(company_id,event_id,resource_id) VALUES(:company,:event,:resource)');
        foreach ($resourceIds as $resourceId) $insert->execute(['company' => $companyId, 'event' => $eventId, 'resource' => $resourceId]);
    }

    private function audit(int $companyId, int $actorId, int $recordId, string $action, ?array $old, array $new, string $ip): void
    {
        $ipAddress=filter_var($ip,FILTER_VALIDATE_IP)?inet_pton($ip):null;
        $query=$this->pdo->prepare('INSERT INTO audit_events(company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) VALUES(:company,:user,:event,\'calendar.event\',:record,:old,:new,:ip)');
        $query->execute(['company'=>$companyId,'user'=>$actorId,'event'=>'calendar_'.$action,'record'=>$recordId,'old'=>$old===null?null:json_encode($old,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'new'=>json_encode($new,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'ip'=>$ipAddress]);
    }

    private function idList(mixed $raw, string $label, int $limit): array
    {
        if (!is_array($raw)) throw new InvalidArgumentException($label . ' must be a list.');
        if (count($raw)>$limit) throw new InvalidArgumentException($label . ' cannot exceed ' . $limit . ' records.');
        $ids=[]; foreach($raw as $value) $ids[]=$this->positiveId($value,$label);
        return array_values(array_unique($ids));
    }

    private function positiveId(mixed $raw, string $label): int
    {
        $id=filter_var($raw,FILTER_VALIDATE_INT); if($id===false||$id<1)throw new InvalidArgumentException($label.' must be a positive ID.'); return (int)$id;
    }

    private function date(mixed $raw, string $label): string
    {
        $value=(string)$raw; $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC')); $errors=DateTimeImmutable::getLastErrors();
        if(!$date||($errors&&($errors['warning_count']||$errors['error_count']))||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException($label.' must be a valid YYYY-MM-DD date.'); return $value;
    }

    private function utcDateTime(mixed $raw, string $label): string
    {
        $value = trim((string)$raw); $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC')); $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d H:i:s') !== $value) throw new InvalidArgumentException($label . ' must be a valid UTC date and time.');
        return $value;
    }

    private function localDateTimeToUtc(mixed $raw, DateTimeZone $timezone, string $label): string
    {
        $value=trim((string)$raw); $local=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,$timezone); $errors=DateTimeImmutable::getLastErrors();
        if(!$local||($errors&&($errors['warning_count']||$errors['error_count']))||$local->format('Y-m-d\TH:i')!==$value)throw new InvalidArgumentException($label.' must be a real local date and time in the selected time zone.');
        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function toLocal(string $utc, string $timezone): string
    {
        return (new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d\TH:i');
    }

    private function timezone(string $name): DateTimeZone
    {
        try { return new DateTimeZone($name); } catch (\Throwable) { throw new InvalidArgumentException('Choose a valid IANA time zone.'); }
    }

    private function text(mixed $raw, string $label, int $maxLength, bool $required=false): ?string
    {
        $value=trim((string)($raw??'')); if($value===''&&!$required)return null;
        if($value===''||mb_strlen($value)>$maxLength)throw new InvalidArgumentException($label.' is required and must be no longer than '.$maxLength.' characters.'); return $value;
    }
}
