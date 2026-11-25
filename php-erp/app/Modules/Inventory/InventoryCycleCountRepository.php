<?php
declare(strict_types=1);

namespace Erp\Modules\Inventory;

use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\UserContext;
use Erp\Modules\Accounting\AccountingDateGuard;
use InvalidArgumentException;
use PDO;
use Throwable;

/** Company-scoped physical stock counts that apply differences through ordinary stock moves. */
final class InventoryCycleCountRepository
{
    private const MAX_LINES = 5000;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function search(UserContext $user, Authorization $authorization, array $filters): array
    {
        $companyId = $this->resolveCompany($user, $authorization, $filters['company_id'] ?? null);
        $state = (string)($filters['state'] ?? 'all');
        if (!in_array($state, ['all', 'counting', 'done', 'cancelled'], true)) throw new InvalidArgumentException('Cycle count status filter is invalid.');
        $where = ['c.company_id=:company'];
        $params = ['company' => $companyId];
        if ($state !== 'all') { $where[] = 'c.state=:state'; $params['state'] = $state; }
        $queryText = trim((string)($filters['q'] ?? ''));
        if (mb_strlen($queryText) > 120) throw new InvalidArgumentException('Cycle count search text must be 120 characters or fewer.');
        if ($queryText !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $queryText) . '%';
            $where[] = '(c.reference LIKE :q_reference ESCAPE \'!\' OR c.note LIKE :q_note ESCAPE \'!\' OR l.complete_name LIKE :q_location ESCAPE \'!\')';
            $params += ['q_reference' => $like, 'q_note' => $like, 'q_location' => $like];
        }
        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM inventory_cycle_counts c JOIN stock_locations l ON l.id=c.location_id WHERE '.$whereSql);
        $count->execute($params);
        $limit = max(1, min(100, (int)($filters['limit'] ?? 50)));
        $offset = max(0, min(1000000, (int)($filters['offset'] ?? 0)));
        $query = $this->pdo->prepare(
            'SELECT c.id,c.company_id,c.reference,c.location_id,l.complete_name AS location_name,c.assigned_to,assignee.name AS assigned_to_name,' .
            'c.scheduled_at,c.show_expected_quantity,c.state,c.note,c.accounting_move_id,' .
            'am.name AS accounting_move_name,am.state AS accounting_move_state,c.created_by,creator.name AS created_by_name,c.validated_by,validator.name AS validated_by_name,' .
            'c.validated_at,c.created_at,COALESCE(cl.line_count,0) AS line_count,COALESCE(cl.counted_line_count,0) AS counted_line_count,' .
            "CASE WHEN c.show_expected_quantity=0 AND c.state='counting' THEN 0 ELSE COALESCE(cl.difference_line_count,0) END AS difference_line_count " .
            'FROM inventory_cycle_counts c JOIN stock_locations l ON l.id=c.location_id ' .
            'JOIN users cu ON cu.id=c.created_by JOIN contacts creator ON creator.id=cu.contact_id ' .
            'LEFT JOIN users au ON au.id=c.assigned_to LEFT JOIN contacts assignee ON assignee.id=au.contact_id ' .
            'LEFT JOIN users vu ON vu.id=c.validated_by LEFT JOIN contacts validator ON validator.id=vu.contact_id ' .
            'LEFT JOIN accounting_moves am ON am.id=c.accounting_move_id ' .
            'LEFT JOIN (SELECT cycle_count_id,COUNT(*) AS line_count,SUM(counted_quantity IS NOT NULL) AS counted_line_count,' .
            'SUM(difference_quantity IS NOT NULL AND difference_quantity<>0) AS difference_line_count ' .
            'FROM inventory_cycle_count_lines GROUP BY cycle_count_id) cl ON cl.cycle_count_id=c.id ' .
            'WHERE '.$whereSql.' ORDER BY c.created_at DESC,c.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) $query->bindValue(':'.$key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->bindValue(':offset', $offset, PDO::PARAM_INT);
        $query->execute();
        $records = $query->fetchAll();
        foreach ($records as &$row) {
            foreach (['id','company_id','location_id','created_by','line_count','counted_line_count','difference_line_count','show_expected_quantity'] as $field) $row[$field] = (int)$row[$field];
            if ($row['assigned_to'] !== null) $row['assigned_to'] = (int)$row['assigned_to'];
            if ($row['accounting_move_id'] !== null) $row['accounting_move_id'] = (int)$row['accounting_move_id'];
            if ($row['validated_by'] !== null) $row['validated_by'] = (int)$row['validated_by'];
            if (!$authorization->allowsForCompany('accounting.read', (int)$row['company_id'])) {
                $row['accounting_move_name'] = null;
                $row['accounting_move_state'] = null;
            }
        }
        unset($row);
        return ['records' => $records, 'total' => (int)$count->fetchColumn(), 'limit' => $limit, 'offset' => $offset, 'company_id' => $companyId];
    }

    public function find(int $id, UserContext $user, Authorization $authorization): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT c.id,c.company_id,c.reference,c.location_id,l.complete_name AS location_name,l.location_usage,' .
            'l.inventory_frequency_days,l.last_inventory_date,l.next_inventory_date,c.assigned_to,assignee.name AS assigned_to_name,' .
            'c.scheduled_at,c.show_expected_quantity,c.state,c.note,' .
            'c.accounting_move_id,am.name AS accounting_move_name,am.state AS accounting_move_state,c.created_by,creator.name AS created_by_name,' .
            'c.validated_by,validator.name AS validated_by_name,c.validated_at,c.created_at,c.updated_at ' .
            'FROM inventory_cycle_counts c JOIN stock_locations l ON l.id=c.location_id ' .
            'JOIN users cu ON cu.id=c.created_by JOIN contacts creator ON creator.id=cu.contact_id ' .
            'LEFT JOIN users au ON au.id=c.assigned_to LEFT JOIN contacts assignee ON assignee.id=au.contact_id ' .
            'LEFT JOIN users vu ON vu.id=c.validated_by LEFT JOIN contacts validator ON validator.id=vu.contact_id ' .
            'LEFT JOIN accounting_moves am ON am.id=c.accounting_move_id WHERE c.id=:id LIMIT 1'
        );
        $query->execute(['id' => $id]);
        $count = $query->fetch();
        if (!$count || !$authorization->canAccessCompany((int)$count['company_id'])) return null;
        $this->requireCompanyPermission($authorization, 'inventory.read', (int)$count['company_id']);
        if (!$authorization->allowsForCompany('accounting.read', (int)$count['company_id'])) {
            $count['accounting_move_name'] = null;
            $count['accounting_move_state'] = null;
        }
        foreach (['id','company_id','location_id','created_by'] as $field) $count[$field] = (int)$count[$field];
        if ($count['accounting_move_id'] !== null) $count['accounting_move_id'] = (int)$count['accounting_move_id'];
        if ($count['assigned_to'] !== null) $count['assigned_to'] = (int)$count['assigned_to'];
        $count['show_expected_quantity'] = (bool)$count['show_expected_quantity'];
        $count['inventory_frequency_days'] = (int)$count['inventory_frequency_days'];
        if ($count['validated_by'] !== null) $count['validated_by'] = (int)$count['validated_by'];
        $lines = $this->pdo->prepare(
            'SELECT l.id,l.product_id,p.name AS product_name,p.internal_reference,p.tracking,p.uom_id,u.abbreviation AS uom,' .
            'l.lot_id,COALESCE(lot.lot_code,l.lot_code_snapshot) AS lot_code,COALESCE(lot.expiration_date,l.expiration_date_snapshot) AS expiration_date,' .
            'l.theoretical_quantity,l.reserved_quantity,l.counted_quantity,l.difference_quantity,l.adjustment_move_id,sm.reference AS adjustment_reference,' .
            'sm.state AS adjustment_state,l.adjustment_move_line_id ' .
            'FROM inventory_cycle_count_lines l JOIN inventory_cycle_counts c ON c.id=l.cycle_count_id ' .
            'JOIN products p ON p.id=l.product_id JOIN units_of_measure u ON u.id=p.uom_id ' .
            'LEFT JOIN stock_lots lot ON lot.id=l.lot_id LEFT JOIN stock_moves sm ON sm.id=l.adjustment_move_id ' .
            'WHERE l.cycle_count_id=:count ORDER BY p.name,l.product_id,l.dimension_key,l.id'
        );
        $lines->execute(['count' => $id]);
        $count['lines'] = $lines->fetchAll();
        foreach ($count['lines'] as &$line) {
            foreach (['id','product_id','uom_id'] as $field) $line[$field] = (int)$line[$field];
            if ($line['lot_id'] !== null) $line['lot_id'] = (int)$line['lot_id'];
            if ($line['adjustment_move_id'] !== null) $line['adjustment_move_id'] = (int)$line['adjustment_move_id'];
            if ($line['adjustment_move_line_id'] !== null) $line['adjustment_move_line_id'] = (int)$line['adjustment_move_line_id'];
            if (!$count['show_expected_quantity'] && $count['state'] === 'counting') {
                $line['theoretical_quantity'] = null;
                $line['reserved_quantity'] = null;
                $line['difference_quantity'] = null;
            }
        }
        unset($line);
        return $count;
    }

    public function options(UserContext $user, Authorization $authorization, mixed $rawCompanyId): array
    {
        $companyId = $this->resolveCompany($user, $authorization, $rawCompanyId);
        $locations = $this->pdo->prepare(
            "SELECT id,complete_name,location_usage,inventory_frequency_days,last_inventory_date,next_inventory_date " .
            "FROM stock_locations WHERE company_id=:company AND active=1 AND location_usage='internal' ORDER BY complete_name,id"
        );
        $locations->execute(['company' => $companyId]);
        $users = $this->pdo->prepare(
            "SELECT u.id,c.name FROM users u JOIN contacts c ON c.id=u.contact_id " .
            "JOIN user_company_access a ON a.user_id=u.id AND a.company_id=:company " .
            "WHERE u.status IN ('active','confirmed') ORDER BY c.name,u.id"
        );
        $users->execute(['company' => $companyId]);
        return ['company_id' => $companyId, 'locations' => $locations->fetchAll(), 'assignees' => $users->fetchAll()];
    }

    public function saveLocationSchedule(int $locationId, array $input, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['inventory_frequency_days']);
        if ($unknown) throw new InvalidArgumentException('Unsupported location schedule fields: '.implode(', ', $unknown));
        $frequency = filter_var($input['inventory_frequency_days'] ?? null, FILTER_VALIDATE_INT);
        if ($frequency === false || $frequency < 0 || $frequency > 3650) throw new InvalidArgumentException('Inventory Frequency must be a whole number from 0 to 3,650 days.');
        $this->pdo->beginTransaction();
        try {
            $locationQuery = $this->pdo->prepare("SELECT id,company_id,complete_name,inventory_frequency_days,last_inventory_date,next_inventory_date FROM stock_locations WHERE id=:id AND active=1 AND location_usage='internal' FOR UPDATE");
            $locationQuery->execute(['id' => $locationId]);
            $location = $locationQuery->fetch();
            if (!$location || !$authorization->canAccessCompany((int)$location['company_id'])) throw new InvalidArgumentException('Internal location was not found or is outside your allowed companies.');
            $this->requireCompanyPermission($authorization, 'inventory.write', (int)$location['company_id']);
            $nextDate = null;
            if ($frequency > 0) {
                $baseDate = (string)($location['last_inventory_date'] ?? $this->companyToday((int)$location['company_id']));
                $nextDate = (new \DateTimeImmutable($baseDate))->modify('+'.$frequency.' days')->format('Y-m-d');
            }
            $this->pdo->prepare('UPDATE stock_locations SET inventory_frequency_days=:frequency,next_inventory_date=:next_date WHERE id=:id AND company_id=:company')
                ->execute(['frequency' => $frequency, 'next_date' => $nextDate, 'id' => $locationId, 'company' => $location['company_id']]);
            $this->audit((int)$location['company_id'], $actorId, $locationId, 'stock_location', 'cycle_count_schedule_updated', [
                'inventory_frequency_days' => (int)$location['inventory_frequency_days'], 'next_inventory_date' => $location['next_inventory_date'],
            ], ['inventory_frequency_days' => $frequency, 'next_inventory_date' => $nextDate], $remoteAddress);
            $this->pdo->commit();
            return ['id' => $locationId, 'name' => $location['complete_name'], 'inventory_frequency_days' => $frequency,
                'last_inventory_date' => $location['last_inventory_date'], 'next_inventory_date' => $nextDate];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function create(array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id','location_id','assigned_to','scheduled_at','show_expected_quantity','note']);
        if ($unknown) throw new InvalidArgumentException('Unsupported cycle count fields: '.implode(', ', $unknown));
        $companyId = $this->positiveId($input['company_id'] ?? null, 'Company');
        $this->requireCompanyPermission($authorization, 'inventory.write', $companyId);
        $locationId = $this->positiveId($input['location_id'] ?? null, 'Location');
        $note = $this->text('Count note', $input['note'] ?? null, 2000) ?? 'Physical Inventory';
        $scheduledAt = $this->date($input['scheduled_at'] ?? null, 'Scheduled Date');
        $assignedTo = ($input['assigned_to'] ?? null) === null || ($input['assigned_to'] ?? '') === '' ? null : $this->positiveId($input['assigned_to'], 'Assigned User');
        $showExpected = $this->boolean($input['show_expected_quantity'] ?? true, 'Show Expected Quantity');
        $this->pdo->beginTransaction();
        try {
            $company = $this->pdo->prepare('SELECT id FROM companies WHERE id=:company AND active=1 FOR UPDATE');
            $company->execute(['company' => $companyId]);
            if (!$company->fetchColumn()) throw new InvalidArgumentException('The selected company is inactive or unavailable.');
            $location = $this->pdo->prepare('SELECT id FROM stock_locations WHERE id=:location AND company_id=:company AND active=1 AND location_usage=\'internal\' FOR UPDATE');
            $location->execute(['location' => $locationId, 'company' => $companyId]);
            if (!$location->fetchColumn()) throw new InvalidArgumentException('Cycle counts require an active Internal location in the selected company.');
            if ($assignedTo !== null) {
                $assignee = $this->pdo->prepare("SELECT u.id FROM users u JOIN user_company_access a ON a.user_id=u.id WHERE u.id=:user AND a.company_id=:company AND u.status IN ('active','confirmed') FOR UPDATE");
                $assignee->execute(['user' => $assignedTo, 'company' => $companyId]);
                if (!$assignee->fetchColumn()) throw new InvalidArgumentException('Assigned User must be active and allowed in the selected company.');
            }
            $positionCount = $this->pdo->prepare('SELECT COUNT(*) FROM stock_quants WHERE company_id=:company AND location_id=:location');
            $positionCount->execute(['company' => $companyId, 'location' => $locationId]);
            $positionCountValue = (int)$positionCount->fetchColumn();
            if ($positionCountValue > self::MAX_LINES) throw new InvalidArgumentException('This location has more than 5,000 stock positions. Choose a smaller Internal location for the count.');
            $temporaryReference = 'IC/NEW/'.bin2hex(random_bytes(8));
            $this->pdo->prepare('INSERT INTO inventory_cycle_counts (company_id,reference,location_id,assigned_to,scheduled_at,show_expected_quantity,state,note,created_by) VALUES (:company,:reference,:location,:assigned_to,:scheduled_at,:show_expected,\'counting\',:note,:actor)')
                ->execute(['company' => $companyId, 'reference' => $temporaryReference, 'location' => $locationId, 'assigned_to' => $assignedTo,
                    'scheduled_at' => $scheduledAt, 'show_expected' => (int)$showExpected, 'note' => $note, 'actor' => $actorId]);
            $id = (int)$this->pdo->lastInsertId();
            $reference = 'IC/'.gmdate('Y').'/'.str_pad((string)$id, 8, '0', STR_PAD_LEFT);
            $this->pdo->prepare('UPDATE inventory_cycle_counts SET reference=:reference WHERE id=:id')->execute(['reference' => $reference, 'id' => $id]);
            $positions = $this->pdo->prepare(
                'SELECT q.product_id,q.lot_id,q.lot_key,q.quantity,q.reserved_quantity,p.tracking,p.product_type,' .
                'lot.lot_code,lot.expiration_date FROM stock_quants q JOIN products p ON p.id=q.product_id ' .
                'LEFT JOIN stock_lots lot ON lot.id=q.lot_id ' .
                'WHERE q.company_id=:company AND q.location_id=:location ' .
                'ORDER BY q.product_id,q.lot_key FOR UPDATE'
            );
            $positions->execute(['company' => $companyId, 'location' => $locationId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO inventory_cycle_count_lines (cycle_count_id,product_id,lot_id,lot_code_snapshot,expiration_date_snapshot,dimension_key,theoretical_quantity,reserved_quantity,created_by) ' .
                'VALUES (:count,:product,:lot,:lot_code,:expiration,:dimension,:quantity,:reserved,:actor)'
            );
            foreach ($positions->fetchAll() as $position) {
                if ($position['product_type'] !== 'goods') throw new InvalidArgumentException('A non-Goods product has stock in this location; resolve that data before counting.');
                $lotId = $position['lot_id'] === null ? null : (int)$position['lot_id'];
                $lotCode = $position['lot_code'] === null ? null : (string)$position['lot_code'];
                if ($position['tracking'] !== 'none' && ($lotId === null || $lotCode === null)) throw new InvalidArgumentException('A tracked stock position is missing its registered lot or serial. Correct it before counting.');
                if ($position['tracking'] === 'none' && $lotId !== null) throw new InvalidArgumentException('An untracked product has a lot-linked stock position. Correct it before counting.');
                $dimension = $lotId === null ? 'NONE' : 'LOT:'.$lotId;
                $insert->execute(['count' => $id, 'product' => $position['product_id'], 'lot' => $lotId, 'lot_code' => $lotCode,
                    'expiration' => $position['expiration_date'], 'dimension' => $dimension, 'quantity' => $position['quantity'],
                    'reserved' => $position['reserved_quantity'], 'actor' => $actorId]);
            }
            $this->audit($companyId, $actorId, $id, 'inventory_cycle_count', 'created', null, [
                'reference' => $reference, 'location_id' => $locationId, 'assigned_to' => $assignedTo, 'scheduled_at' => $scheduledAt,
                'show_expected_quantity' => $showExpected, 'line_count' => $positionCountValue, 'state' => 'counting', 'note' => $note,
            ], $remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $this->find($id, $user, $authorization) ?? throw new InvalidArgumentException('Created cycle count could not be reloaded.');
    }

    public function addLine(int $countId, array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['product_id','lot_code','expiration_date','counted_quantity']);
        if ($unknown) throw new InvalidArgumentException('Unsupported cycle count line fields: '.implode(', ', $unknown));
        $this->pdo->beginTransaction();
        try {
            $count = $this->lockedCount($countId, $authorization, 'inventory.write');
            if ($count['state'] !== 'counting') throw new InvalidArgumentException('Only an in-progress cycle count can accept a line.');
            $lineCount = $this->pdo->prepare('SELECT COUNT(*) FROM inventory_cycle_count_lines WHERE cycle_count_id=:count');
            $lineCount->execute(['count' => $countId]);
            if ((int)$lineCount->fetchColumn() >= self::MAX_LINES) throw new InvalidArgumentException('A cycle count can contain up to 5,000 stock positions.');
            $productId = $this->positiveId($input['product_id'] ?? null, 'Product');
            $productQuery = $this->pdo->prepare('SELECT id,company_id,name,product_type,tracking,uom_id FROM products WHERE id=:product AND company_id=:company AND active=1 FOR UPDATE');
            $productQuery->execute(['product' => $productId, 'company' => $count['company_id']]);
            $product = $productQuery->fetch();
            if (!$product || $product['product_type'] !== 'goods') throw new InvalidArgumentException('Choose an active Goods product belonging to this company.');
            $lotCode = $this->text('Lot or serial code', $input['lot_code'] ?? null, 120);
            if ($product['tracking'] === 'none' && $lotCode !== null) throw new InvalidArgumentException('This product is not tracked by lot or serial.');
            if ($product['tracking'] !== 'none' && $lotCode === null) throw new InvalidArgumentException('Enter a lot or serial code for this tracked product.');
            $lotId = null;
            $expiration = $this->date($input['expiration_date'] ?? null);
            if ($product['tracking'] === 'none' && $expiration !== null) throw new InvalidArgumentException('Expiration date is only available for a lot- or serial-tracked product.');
            if ($lotCode !== null) {
                $lot = $this->pdo->prepare('SELECT id,expiration_date FROM stock_lots WHERE company_id=:company AND product_id=:product AND lot_code=:code AND active=1 FOR UPDATE');
                $lot->execute(['company' => $count['company_id'], 'product' => $productId, 'code' => $lotCode]);
                $lotRow = $lot->fetch();
                if ($lotRow) {
                    $lotId = (int)$lotRow['id'];
                    if ($expiration !== null && $lotRow['expiration_date'] !== $expiration) throw new InvalidArgumentException('Expiration date must match the registered lot.');
                    $expiration = $lotRow['expiration_date'];
                }
            }
            $dimension = $lotId !== null ? 'LOT:'.$lotId : ($lotCode === null ? 'NONE' : 'CODE:'.$lotCode);
            $lotKey = $lotId !== null ? 'LOT'.$lotId : 'NONE';
            $quant = $this->pdo->prepare('SELECT quantity,reserved_quantity FROM stock_quants WHERE company_id=:company AND product_id=:product AND location_id=:location AND lot_key=:lot_key FOR UPDATE');
            $quant->execute(['company' => $count['company_id'], 'product' => $productId, 'location' => $count['location_id'], 'lot_key' => $lotKey]);
            $position = $quant->fetch() ?: ['quantity' => '0.000000', 'reserved_quantity' => '0.000000'];
            $counted = $this->quantity($input['counted_quantity'] ?? null, 'Counted Quantity', true);
            if ($product['tracking'] === 'serial' && !$this->isZeroOrOne($counted)) throw new InvalidArgumentException('A serial-tracked position can only count as zero or one.');
            if ($this->compare($counted, (string)$position['reserved_quantity']) < 0) throw new InvalidArgumentException('Counted Quantity cannot be less than the quantity reserved for open operations.');
            $insert = $this->pdo->prepare(
                'INSERT INTO inventory_cycle_count_lines (cycle_count_id,product_id,lot_id,lot_code_snapshot,expiration_date_snapshot,dimension_key,theoretical_quantity,reserved_quantity,counted_quantity,difference_quantity,created_by) ' .
                'VALUES (:count,:product,:lot,:lot_code,:expiration,:dimension,:theoretical,:reserved,:counted,:difference,:actor)'
            );
            $difference = $this->subtract($counted, (string)$position['quantity']);
            $insert->execute(['count' => $countId, 'product' => $productId, 'lot' => $lotId, 'lot_code' => $lotCode, 'expiration' => $expiration,
                'dimension' => $dimension, 'theoretical' => $position['quantity'], 'reserved' => $position['reserved_quantity'],
                'counted' => $counted, 'difference' => $difference, 'actor' => $actorId]);
            $lineId = (int)$this->pdo->lastInsertId();
            $this->audit((int)$count['company_id'], $actorId, $lineId, 'inventory_cycle_count_line', 'added', null, [
                'cycle_count_id' => $countId, 'product_id' => $productId, 'lot_id' => $lotId, 'lot_code' => $lotCode,
                'theoretical_quantity' => $position['quantity'], 'reserved_quantity' => $position['reserved_quantity'], 'counted_quantity' => $counted, 'difference_quantity' => $difference,
            ], $remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $this->find($countId, $user, $authorization) ?? throw new InvalidArgumentException('Cycle count could not be reloaded.');
    }

    public function setLineCounted(int $countId, int $lineId, array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['counted_quantity']);
        if ($unknown) throw new InvalidArgumentException('Unsupported cycle count line fields: '.implode(', ', $unknown));
        $counted = $this->quantity($input['counted_quantity'] ?? null, 'Counted Quantity', true);
        $this->pdo->beginTransaction();
        try {
            $count = $this->lockedCount($countId, $authorization, 'inventory.write');
            if ($count['state'] !== 'counting') throw new InvalidArgumentException('Only an in-progress cycle count can be edited.');
            $lineQuery = $this->pdo->prepare('SELECT l.* ,p.tracking FROM inventory_cycle_count_lines l JOIN products p ON p.id=l.product_id WHERE l.id=:line AND l.cycle_count_id=:count FOR UPDATE');
            $lineQuery->execute(['line' => $lineId, 'count' => $countId]);
            $line = $lineQuery->fetch();
            if (!$line || $line['adjustment_move_id'] !== null) throw new InvalidArgumentException('Cycle count line was not found or has already been applied.');
            if ($line['tracking'] === 'serial' && !$this->isZeroOrOne($counted)) throw new InvalidArgumentException('A serial-tracked position can only count as zero or one.');
            if ($this->compare($counted, (string)$line['reserved_quantity']) < 0) throw new InvalidArgumentException('Counted Quantity cannot be less than the quantity reserved for open operations.');
            $difference = $this->subtract($counted, (string)$line['theoretical_quantity']);
            $this->pdo->prepare('UPDATE inventory_cycle_count_lines SET counted_quantity=:counted,difference_quantity=:difference WHERE id=:line AND cycle_count_id=:count')
                ->execute(['counted' => $counted, 'difference' => $difference, 'line' => $lineId, 'count' => $countId]);
            $this->audit((int)$count['company_id'], $actorId, $lineId, 'inventory_cycle_count_line', 'counted', [
                'counted_quantity' => $line['counted_quantity'], 'difference_quantity' => $line['difference_quantity'],
            ], ['counted_quantity' => $counted, 'difference_quantity' => $difference], $remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $this->find($countId, $user, $authorization) ?? throw new InvalidArgumentException('Cycle count could not be reloaded.');
    }

    public function validate(int $id, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $this->pdo->beginTransaction();
        try {
            $count = $this->lockedCount($id, $authorization, 'inventory.validate');
            $companyId = (int)$count['company_id'];
            $this->requireCompanyPermission($authorization, 'inventory.write', $companyId);
            if ($count['state'] !== 'counting') throw new InvalidArgumentException('Only an in-progress cycle count can be validated.');
            $today = $this->companyToday($companyId);
            if ($count['scheduled_at'] !== null && (string)$count['scheduled_at'] > $today) throw new InvalidArgumentException('This cycle count is scheduled for a future date and cannot be validated yet.');
            $linesQuery = $this->pdo->prepare(
                'SELECT l.*,p.name AS product_name,p.tracking,p.product_type,p.uom_id,p.company_id AS product_company_id ' .
                'FROM inventory_cycle_count_lines l JOIN products p ON p.id=l.product_id ' .
                'WHERE l.cycle_count_id=:count ORDER BY l.product_id,l.dimension_key,l.id FOR UPDATE'
            );
            $linesQuery->execute(['count' => $id]);
            $lines = $linesQuery->fetchAll();
            if (!$lines) throw new InvalidArgumentException('Add or capture at least one stock position before validating this count.');
            foreach ($lines as $line) {
                if ($line['counted_quantity'] === null) throw new InvalidArgumentException('Enter Counted Quantity for every cycle count line before validating.');
                if ($line['product_type'] !== 'goods' || (int)$line['product_company_id'] !== $companyId) throw new InvalidArgumentException('A cycle count line contains a product outside this company or not configured as Goods.');
            }
            $quantQuery = $this->pdo->prepare(
                'SELECT q.product_id,q.lot_id,q.quantity,q.reserved_quantity,lot.lot_code FROM stock_quants q ' .
                'LEFT JOIN stock_lots lot ON lot.id=q.lot_id WHERE q.company_id=:company AND q.location_id=:location ' .
                'ORDER BY q.product_id,q.lot_key FOR UPDATE'
            );
            $quantQuery->execute(['company' => $companyId, 'location' => $count['location_id']]);
            $current = [];
            foreach ($quantQuery->fetchAll() as $position) {
                $lotId = $position['lot_id'] === null ? null : (int)$position['lot_id'];
                $lotCode = $position['lot_code'] === null ? null : (string)$position['lot_code'];
                $dimension = $lotId !== null ? 'LOT:'.$lotId : ($lotCode === null ? 'NONE' : 'CODE:'.$lotCode);
                $current[(int)$position['product_id'].'|'.$dimension] = $position;
            }
            $lineByKey = [];
            $matched = [];
            foreach ($lines as $index => $line) {
                $key = (int)$line['product_id'].'|'.(string)$line['dimension_key'];
                if (isset($lineByKey[$key])) throw new InvalidArgumentException('This cycle count contains a duplicate product and lot/serial position.');
                $lineByKey[$key] = $index;
                $position = $current[$key] ?? null;
                if ($position === null && $line['lot_id'] === null && $line['lot_code_snapshot'] !== null) {
                    $alias = (int)$line['product_id'].'|CODE:'.(string)$line['lot_code_snapshot'];
                    $position = $current[$alias] ?? null;
                    if ($position === null) {
                        foreach ($current as $currentKey => $candidate) {
                            if ((int)$candidate['product_id'] === (int)$line['product_id'] && (string)($candidate['lot_code'] ?? '') === (string)$line['lot_code_snapshot']) {
                                $position = $candidate; $key = $currentKey; break;
                            }
                        }
                    }
                }
                $position ??= ['quantity' => '0.000000', 'reserved_quantity' => '0.000000', 'lot_id' => $line['lot_id']];
                if ($this->compare((string)$position['quantity'], (string)$line['theoretical_quantity']) !== 0
                    || $this->compare((string)$position['reserved_quantity'], (string)$line['reserved_quantity']) !== 0) {
                    throw new InvalidArgumentException('Stock changed after this count began. Cancel it and start a fresh count before applying adjustments.');
                }
                if ($this->compare((string)$line['counted_quantity'], (string)$position['reserved_quantity']) < 0) throw new InvalidArgumentException('Counted Quantity cannot be less than the currently reserved quantity.');
                if ($line['tracking'] === 'serial' && !$this->isZeroOrOne((string)$line['counted_quantity'])) throw new InvalidArgumentException('A serial-tracked position can only count as zero or one.');
                if ($position['lot_id'] !== null && $line['lot_id'] === null && $line['lot_code_snapshot'] === null) throw new InvalidArgumentException('A tracked stock position needs a lot or serial code.');
                $matched[$index] = $position;
                if (isset($current[$key])) unset($current[$key]);
                elseif ($line['lot_id'] === null && $line['lot_code_snapshot'] !== null) {
                    foreach ($current as $currentKey => $candidate) if ((int)$candidate['product_id'] === (int)$line['product_id'] && (string)($candidate['lot_code'] ?? '') === (string)$line['lot_code_snapshot']) { unset($current[$currentKey]); break; }
                }
                $difference = $this->subtract((string)$line['counted_quantity'], (string)$line['theoretical_quantity']);
                $this->pdo->prepare('UPDATE inventory_cycle_count_lines SET difference_quantity=:difference WHERE id=:line')->execute(['difference' => $difference, 'line' => $line['id']]);
                $lines[$index]['difference_quantity'] = $difference;
            }
            if ($current) throw new InvalidArgumentException('New stock positions appeared after this count began. Cancel it and start a fresh count to include them.');

            $inventoryLocationQuery = $this->pdo->prepare('SELECT id FROM stock_locations WHERE company_id=:company AND location_usage=\'inventory\' AND warehouse_id IS NULL AND name=\'Inventory Adjustment\' AND active=1 ORDER BY id LIMIT 1 FOR UPDATE');
            $inventoryLocationQuery->execute(['company' => $companyId]);
            $inventoryLocationId = (int)$inventoryLocationQuery->fetchColumn();
            if ($inventoryLocationId < 1) throw new InvalidArgumentException('This company needs an active Inventory Adjustment virtual location before applying cycle counts.');
            $groups = [];
            foreach ($lines as $index => $line) {
                $difference = (string)$line['difference_quantity'];
                if ($this->compare($difference, '0') === 0) continue;
                $direction = $this->compare($difference, '0') > 0 ? 'increase' : 'decrease';
                $key = (int)$line['product_id'].'|'.$direction;
                $groups[$key] ??= ['product_id' => (int)$line['product_id'], 'direction' => $direction, 'lines' => []];
                $groups[$key]['lines'][] = $line;
            }
            $stockMoves = new StockMoveRepository($this->pdo);
            foreach ($groups as $group) {
                $first = $group['lines'][0];
                $demand = $this->sumAbsoluteDifferences($id, (int)$group['product_id'], $group['direction']);
                $moveLines = [];
                foreach ($group['lines'] as $line) {
                    $moveLines[] = [
                        'lot_id' => $line['lot_id'] === null ? null : (int)$line['lot_id'],
                        'lot_code' => $line['lot_code_snapshot'],
                        'expiration_date' => $line['expiration_date_snapshot'],
                        'quantity' => $this->absolute((string)$line['difference_quantity']),
                    ];
                }
                $increase = $group['direction'] === 'increase';
                $moveInput = [
                    'company_id' => $companyId, 'product_id' => (int)$group['product_id'], 'uom_id' => (int)$first['uom_id'],
                    'source_location_id' => $increase ? $inventoryLocationId : (int)$count['location_id'],
                    'destination_location_id' => $increase ? (int)$count['location_id'] : $inventoryLocationId,
                    'demand_quantity' => $demand, 'origin_model' => 'inventory', 'origin_record_id' => $id,
                    'origin_reference' => $count['reference'], 'lines' => $moveLines,
                ];
                $move = $stockMoves->saveDraft(null, $moveInput, $user, $authorization, $actorId, $remoteAddress);
                $this->pdo->prepare('UPDATE inventory_cycle_count_lines SET adjustment_move_id=:move WHERE id=:line')
                    ->execute(['move' => $move['id'], 'line' => $first['id']]);
                foreach ($group['lines'] as $lineIndex => $line) {
                    $moveLine = $move['lines'][$lineIndex] ?? null;
                    if (!$moveLine) throw new \RuntimeException('Stock adjustment detail line could not be linked to its count line.');
                    $this->pdo->prepare('UPDATE inventory_cycle_count_lines SET adjustment_move_id=:move,adjustment_move_line_id=:move_line WHERE id=:line')
                        ->execute(['move' => $move['id'], 'move_line' => $moveLine['id'], 'line' => $line['id']]);
                }
                $stockMoves->confirm((int)$move['id'], $user, $authorization, $actorId, $remoteAddress);
                $stockMoves->validate((int)$move['id'], $user, $authorization, $actorId, $remoteAddress);
            }
            $accountingMove = $this->postPerpetualAdjustment($id, $count, $inventoryLocationId, $authorization, $actorId, $remoteAddress, $today);
            $this->pdo->prepare("UPDATE inventory_cycle_counts SET state='done',accounting_move_id=:accounting_move,validated_by=:actor,validated_at=UTC_TIMESTAMP() WHERE id=:id AND state='counting'")
                ->execute(['accounting_move' => $accountingMove['id'] ?? null, 'actor' => $actorId, 'id' => $id]);
            $this->pdo->prepare(
                'UPDATE stock_locations SET last_inventory_date=:count_date,' .
                'next_inventory_date=CASE WHEN inventory_frequency_days>0 THEN DATE_ADD(:next_date,INTERVAL inventory_frequency_days DAY) ELSE NULL END ' .
                'WHERE id=:location AND company_id=:company'
            )->execute(['count_date' => $today, 'next_date' => $today, 'location' => $count['location_id'], 'company' => $companyId]);
            $this->audit($companyId, $actorId, $id, 'inventory_cycle_count', 'validated', [
                'state' => 'counting', 'line_count' => count($lines),
            ], ['state' => 'done', 'line_count' => count($lines), 'adjustment_count' => count($groups), 'accounting_move_id' => $accountingMove['id'] ?? null], $remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $this->find($id, $user, $authorization) ?? throw new InvalidArgumentException('Validated cycle count could not be reloaded.');
    }

    public function cancel(int $id, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $this->pdo->beginTransaction();
        try {
            $count = $this->lockedCount($id, $authorization, 'inventory.write');
            if ($count['state'] !== 'counting') throw new InvalidArgumentException('Only an in-progress cycle count can be cancelled.');
            $linked = $this->pdo->prepare('SELECT COUNT(*) FROM inventory_cycle_count_lines WHERE cycle_count_id=:count AND adjustment_move_id IS NOT NULL');
            $linked->execute(['count' => $id]);
            if ((int)$linked->fetchColumn() > 0) throw new InvalidArgumentException('A cycle count with applied stock adjustments cannot be cancelled; use a new reversing count.');
            $this->pdo->prepare("UPDATE inventory_cycle_counts SET state='cancelled' WHERE id=:id AND state='counting'")->execute(['id' => $id]);
            $this->audit((int)$count['company_id'], $actorId, $id, 'inventory_cycle_count', 'cancelled', ['state' => 'counting'], ['state' => 'cancelled'], $remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $this->find($id, $user, $authorization) ?? throw new InvalidArgumentException('Cancelled cycle count could not be reloaded.');
    }

    private function postPerpetualAdjustment(int $countId, array $count, int $inventoryLocationId, Authorization $authorization, int $actorId, string $remoteAddress, string $accountingDate): ?array
    {
        $query = $this->pdo->prepare(
            "SELECT s.journal_id,COALESCE(o.valuation_account_id,s.valuation_account_id) AS valuation_account_id," .
            "COALESCE(il.inventory_loss_account_id,o.variation_account_id,s.variation_account_id) AS variation_account_id," .
            "CAST(ROUND(SUM(adjustment.valuation_value),cur.decimal_places) AS DECIMAL(20,6)) AS difference_value,cur.currency_code,cur.decimal_places " .
            'FROM (SELECT DISTINCT cl.adjustment_move_id,cl.product_id,sm.valuation_value ' .
            'FROM inventory_cycle_count_lines cl JOIN stock_moves sm ON sm.id=cl.adjustment_move_id ' .
            'WHERE cl.cycle_count_id=:count AND cl.adjustment_move_id IS NOT NULL) adjustment ' .
            'JOIN products p ON p.id=adjustment.product_id JOIN companies co ON co.id=p.company_id JOIN currencies cur ON cur.currency_code=co.currency_code ' .
            'LEFT JOIN inventory_accounting_settings s ON s.company_id=p.company_id ' .
            'LEFT JOIN product_category_accounting_overrides o ON o.company_id=p.company_id AND o.category_id=p.category_id ' .
            'LEFT JOIN stock_locations il ON il.id=:inventory_location AND il.company_id=p.company_id ' .
            "WHERE COALESCE(o.valuation_method,s.valuation_method,'periodic')='perpetual' " .
            'GROUP BY s.journal_id,COALESCE(o.valuation_account_id,s.valuation_account_id),COALESCE(il.inventory_loss_account_id,o.variation_account_id,s.variation_account_id),cur.currency_code,cur.decimal_places'
        );
        $query->execute(['count' => $countId, 'inventory_location' => $inventoryLocationId]);
        $groups = [];
        foreach ($query->fetchAll() as $row) {
            if ($this->compare((string)$row['difference_value'], '0') === 0) continue;
            if (!$row['journal_id'] || !$row['valuation_account_id'] || !$row['variation_account_id']) {
                throw new InvalidArgumentException('Perpetual inventory adjustment needs a General Journal, Valuation Account, and Variation Account for each product category.');
            }
            $journal = $this->pdo->prepare("SELECT id FROM accounting_journals WHERE id=:id AND company_id=:company AND journal_type='general' AND active=1");
            $journal->execute(['id' => $row['journal_id'], 'company' => $count['company_id']]);
            if (!$journal->fetchColumn()) throw new InvalidArgumentException('Configure an active General Journal for perpetual inventory adjustments.');
            $valuation = $this->account((int)$row['valuation_account_id'], (int)$count['company_id'], ['asset_current','asset_non_current'], 'Stock Valuation Account');
            $variation = $this->account((int)$row['variation_account_id'], (int)$count['company_id'], ['asset_current','expense','expense_other','expense_direct_cost'], 'Inventory Variation Account');
            if ($valuation === $variation) throw new InvalidArgumentException('Stock Valuation and Inventory Variation Accounts must be different.');
            $groups[] = $row + ['journal_id' => (int)$row['journal_id'], 'valuation_account_id' => $valuation, 'variation_account_id' => $variation];
        }
        if (!$groups) return null;
        $companyId = (int)$count['company_id'];
        if (!$authorization->allowsForCompany('accounting.post', $companyId)) throw new AccessDeniedException('Accounting posting access is required because this perpetual cycle count creates a General Journal entry.');
        AccountingDateGuard::lockAndAssertOpen($this->pdo, $companyId, $accountingDate);
        $currency = (string)$groups[0]['currency_code'];
        foreach ($groups as $group) if ($group['currency_code'] !== $currency) throw new \RuntimeException('Cycle count adjustment journals must use one company currency.');
        $name = 'ICJ/'.gmdate('Y').'/'.str_pad((string)$countId, 8, '0', STR_PAD_LEFT);
        $reference = 'Inventory Cycle Count · '.$count['reference'];
        $this->pdo->prepare(
            "INSERT INTO accounting_moves (company_id,name,journal_id,move_type,reference_model,reference_id,reference,accounting_date,currency_code,state,total_debit,total_credit,created_by,posted_by,posted_at) " .
            "VALUES (:company,:name,:journal,'inventory_adjustment','inventory_cycle_count',:count,:reference,:date,:currency,'draft',0,0,:actor,:poster,UTC_TIMESTAMP())"
        )->execute(['company' => $companyId, 'name' => $name, 'journal' => $groups[0]['journal_id'], 'count' => $countId, 'reference' => $reference,
            'date' => $accountingDate, 'currency' => $currency, 'actor' => $actorId, 'poster' => $actorId]);
        $accountingMoveId = (int)$this->pdo->lastInsertId();
        $lineInsert = $this->pdo->prepare(
            'INSERT INTO accounting_move_lines (move_id,sequence,account_id,contact_id,label,debit,credit,currency_code,amount_currency) ' .
            'VALUES (:move,:sequence,:account,NULL,:label,:debit,:credit,:currency,:amount_currency)'
        );
        $sequence = 10;
        foreach ($groups as $group) {
            $delta = (string)$group['difference_value'];
            $amount = $this->absolute($delta);
            $positive = $this->compare($delta, '0') > 0;
            $debitAccount = $positive ? $group['valuation_account_id'] : $group['variation_account_id'];
            $creditAccount = $positive ? $group['variation_account_id'] : $group['valuation_account_id'];
            $lineInsert->execute(['move' => $accountingMoveId, 'sequence' => $sequence, 'account' => $debitAccount, 'label' => mb_substr($reference, 0, 190),
                'debit' => $amount, 'credit' => '0.000000', 'currency' => $currency, 'amount_currency' => $amount]);
            $sequence += 10;
            $lineInsert->execute(['move' => $accountingMoveId, 'sequence' => $sequence, 'account' => $creditAccount, 'label' => mb_substr($reference, 0, 190),
                'debit' => '0.000000', 'credit' => $amount, 'currency' => $currency, 'amount_currency' => '-'.$amount]);
            $sequence += 10;
        }
        $totals = $this->pdo->prepare('SELECT CAST(SUM(debit) AS DECIMAL(20,6)) AS debit_total,CAST(SUM(credit) AS DECIMAL(20,6)) AS credit_total FROM accounting_move_lines WHERE move_id=:move');
        $totals->execute(['move' => $accountingMoveId]);
        $amounts = $totals->fetch();
        if ($this->compare((string)$amounts['debit_total'], (string)$amounts['credit_total']) !== 0) throw new \RuntimeException('Perpetual cycle count journal entry is not balanced.');
        $this->pdo->prepare("UPDATE accounting_moves SET total_debit=:debit,total_credit=:credit,state='posted' WHERE id=:move AND state='draft'")
            ->execute(['debit' => $amounts['debit_total'], 'credit' => $amounts['credit_total'], 'move' => $accountingMoveId]);
        $this->audit($companyId, $actorId, $accountingMoveId, 'accounting_move', 'inventory_adjustment_posted', null, [
            'name' => $name, 'reference_model' => 'inventory_cycle_count', 'reference_id' => $countId,
            'total_debit' => $amounts['debit_total'], 'total_credit' => $amounts['credit_total'],
        ], $remoteAddress);
        return ['id' => $accountingMoveId, 'name' => $name];
    }

    private function account(int $id, int $companyId, array $allowedTypes, string $label): int
    {
        $query = $this->pdo->prepare('SELECT id,account_type FROM accounting_accounts WHERE id=:id AND company_id=:company AND active=1');
        $query->execute(['id' => $id, 'company' => $companyId]);
        $account = $query->fetch();
        if (!$account || !in_array($account['account_type'], $allowedTypes, true)) throw new InvalidArgumentException($label.' must be an active compatible account in this company.');
        return (int)$account['id'];
    }

    private function sumAbsoluteDifferences(int $countId, int $productId, string $direction): string
    {
        $operator = $direction === 'increase' ? '>' : '<';
        $query = $this->pdo->prepare('SELECT CAST(SUM(ABS(difference_quantity)) AS DECIMAL(20,6)) FROM inventory_cycle_count_lines WHERE cycle_count_id=:count AND product_id=:product AND difference_quantity '.$operator.' 0');
        $query->execute(['count' => $countId, 'product' => $productId]);
        $value = (string)$query->fetchColumn();
        return $value === '' ? '0.000000' : $value;
    }

    private function lockedCount(int $id, Authorization $authorization, string $permission): array
    {
        $query = $this->pdo->prepare('SELECT * FROM inventory_cycle_counts WHERE id=:id FOR UPDATE');
        $query->execute(['id' => $id]);
        $count = $query->fetch();
        if (!$count || !$authorization->canAccessCompany((int)$count['company_id'])) throw new InvalidArgumentException('Cycle count was not found or is outside your allowed companies.');
        $this->requireCompanyPermission($authorization, $permission, (int)$count['company_id']);
        return $count;
    }

    private function resolveCompany(UserContext $user, Authorization $authorization, mixed $rawCompanyId): int
    {
        $companyId = $rawCompanyId === null || $rawCompanyId === '' ? null : $this->positiveId($rawCompanyId, 'Company');
        $companyId ??= $user->defaultCompanyId ?? (count($user->companyIds) === 1 ? $user->companyIds[0] : null);
        if ($companyId === null || !$authorization->canAccessCompany($companyId)) throw new InvalidArgumentException('Choose a company available to your account.');
        $this->requireCompanyPermission($authorization, 'inventory.read', $companyId);
        $active = $this->pdo->prepare('SELECT 1 FROM companies WHERE id=:company AND active=1');
        $active->execute(['company' => $companyId]);
        if (!$active->fetchColumn()) throw new InvalidArgumentException('The selected company is inactive or unavailable.');
        return $companyId;
    }

    private function requireCompanyPermission(Authorization $authorization, string $permission, int $companyId): void
    {
        if (!$authorization->canAccessCompany($companyId) || !$authorization->allowsForCompany($permission, $companyId)) {
            throw new AccessDeniedException('You do not have '.$permission.' access for this company.');
        }
    }

    private function audit(int $companyId, int $actorId, int $recordId, string $model, string $event, ?array $old, array $new, string $remoteAddress): void
    {
        $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        $query = $this->pdo->prepare('INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) VALUES (:company,:user,:event,:model,:record,:old_values,:new_values,:ip)');
        $query->bindValue(':company', $companyId, PDO::PARAM_INT); $query->bindValue(':user', $actorId, PDO::PARAM_INT);
        $query->bindValue(':event', $event); $query->bindValue(':model', $model); $query->bindValue(':record', $recordId, PDO::PARAM_INT);
        $query->bindValue(':old_values', $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $old === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $query->bindValue(':new_values', json_encode($new, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $query->bindValue(':ip', $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_LOB); $query->execute();
    }

    private function positiveId(mixed $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new InvalidArgumentException($label.' must be a positive integer.');
        return $id;
    }

    private function text(string $label, mixed $value, int $maxLength): ?string
    {
        if ($value === null) return null;
        if (!is_string($value) && !is_numeric($value)) throw new InvalidArgumentException($label.' must be text.');
        $value = trim((string)$value);
        if ($value === '') return null;
        if (mb_strlen($value) > $maxLength) throw new InvalidArgumentException($label.' must be '.$maxLength.' characters or fewer.');
        return $value;
    }

    private function date(mixed $value, string $label = 'Expiration Date'): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) throw new InvalidArgumentException($label.' must use YYYY-MM-DD.');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException($label.' is invalid.');
        return $value;
    }

    private function boolean(mixed $value, string $label): bool
    {
        if (is_bool($value)) return $value;
        if ($value === 1 || $value === '1') return true;
        if ($value === 0 || $value === '0') return false;
        throw new InvalidArgumentException($label.' must be true or false.');
    }

    private function companyToday(int $companyId): string
    {
        $query = $this->pdo->prepare('SELECT timezone FROM companies WHERE id=:company AND active=1');
        $query->execute(['company' => $companyId]);
        $timezone = (string)$query->fetchColumn();
        if ($timezone === '') $timezone = 'UTC';
        try { return (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d'); }
        catch (\Throwable) { return gmdate('Y-m-d'); }
    }

    private function quantity(mixed $value, string $label, bool $zeroAllowed = false): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) throw new InvalidArgumentException($label.' must be a decimal quantity.');
        $text = trim((string)$value);
        if (preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/D', $text) !== 1) throw new InvalidArgumentException($label.' must be a non-negative decimal with up to six fractional digits.');
        if (!$zeroAllowed && $this->compare($text, '0') === 0) throw new InvalidArgumentException($label.' must be greater than zero.');
        return $text;
    }

    private function compare(string $left, string $right): int
    {
        $query = $this->pdo->prepare('SELECT CASE WHEN CAST(:left_less AS DECIMAL(20,6))<CAST(:right_less AS DECIMAL(20,6)) THEN -1 WHEN CAST(:left_more AS DECIMAL(20,6))>CAST(:right_more AS DECIMAL(20,6)) THEN 1 ELSE 0 END');
        $query->execute(['left_less' => $left, 'right_less' => $right, 'left_more' => $left, 'right_more' => $right]);
        return (int)$query->fetchColumn();
    }

    private function subtract(string $left, string $right): string
    {
        $query = $this->pdo->prepare('SELECT CAST(CAST(:left AS DECIMAL(20,6))-CAST(:right AS DECIMAL(20,6)) AS DECIMAL(20,6))');
        $query->execute(['left' => $left, 'right' => $right]);
        return (string)$query->fetchColumn();
    }

    private function absolute(string $value): string
    {
        $query = $this->pdo->prepare('SELECT CAST(ABS(CAST(:value AS DECIMAL(20,6))) AS DECIMAL(20,6))');
        $query->execute(['value' => $value]);
        return (string)$query->fetchColumn();
    }

    private function isZeroOrOne(string $value): bool
    {
        $query = $this->pdo->prepare('SELECT CAST(:value AS DECIMAL(20,6))=0 OR CAST(:value AS DECIMAL(20,6))=1');
        $query->execute(['value' => $value]);
        return (bool)$query->fetchColumn();
    }
}
