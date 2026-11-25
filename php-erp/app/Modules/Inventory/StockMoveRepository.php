<?php
declare(strict_types=1);

namespace Erp\Modules\Inventory;

use DateTimeImmutable;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDO;
use Throwable;

final class StockMoveRepository
{
    private const STOCK_USAGES = ['internal', 'transit'];
    private const LOCATION_USAGES = ['internal', 'transit', 'vendor', 'customer', 'inventory', 'production'];
    private const MOVE_FIELDS = [
        'company_id', 'product_id', 'uom_id', 'source_location_id', 'destination_location_id', 'demand_quantity',
        'origin_model', 'origin_record_id', 'origin_reference', 'scheduled_at', 'lines',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function search(UserContext $user, Authorization $authorization, array $filters): array
    {
        $companyId = $this->resolveCompany($user, $authorization, $filters['company_id'] ?? null);
        $where = ['m.company_id = :company_id'];
        $params = ['company_id' => $companyId];
        $state = (string)($filters['state'] ?? 'all');
        if ($state !== 'all') {
            if (!in_array($state, ['draft', 'confirmed', 'done', 'cancelled'], true)) {
                throw new InvalidArgumentException('Stock move state filter is invalid.');
            }
            $where[] = 'm.state = :state';
            $params['state'] = $state;
        }
        $productId = $filters['product_id'] ?? null;
        if ($productId !== null && $productId !== '') {
            $where[] = 'm.product_id = :product_id';
            $params['product_id'] = $this->positiveId($productId, 'Product');
        }
        $queryText = trim((string)($filters['q'] ?? ''));
        if ($queryText !== '') {
            if (mb_strlen($queryText) > 120) {
                throw new InvalidArgumentException('Stock move search text must be 120 characters or fewer.');
            }
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $queryText) . '%';
            $where[] = '(m.reference LIKE :q_ref ESCAPE \'!\' OR m.origin_reference LIKE :q_origin ESCAPE \'!\' OR p.name LIKE :q_product ESCAPE \'!\')';
            $params += ['q_ref' => $like, 'q_origin' => $like, 'q_product' => $like];
        }
        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM stock_moves m JOIN products p ON p.id = m.product_id WHERE ' . $whereSql);
        $count->execute($params);
        $limit = max(1, min(200, (int)($filters['limit'] ?? 50)));
        $offset = max(0, min(1000000, (int)($filters['offset'] ?? 0)));
        $list = $this->pdo->prepare(
            'SELECT m.id, m.company_id, c.currency_code, m.reference, m.product_id, p.name AS product_name, p.internal_reference, ' .
            'm.source_location_id, src.complete_name AS source_location, m.destination_location_id, dst.complete_name AS destination_location, ' .
            'm.demand_quantity, m.done_quantity, m.valuation_value, m.state, m.origin_model, m.origin_record_id, m.origin_reference, m.scheduled_at, m.done_at, ' .
            'm.return_of_move_id, m.return_reason, (SELECT parent.reference FROM stock_moves parent WHERE parent.id=m.return_of_move_id AND parent.company_id=m.company_id) AS return_of_reference ' .
            'FROM stock_moves m JOIN products p ON p.id = m.product_id JOIN companies c ON c.id=m.company_id ' .
            'JOIN stock_locations src ON src.id = m.source_location_id JOIN stock_locations dst ON dst.id = m.destination_location_id ' .
            'WHERE ' . $whereSql . ' ORDER BY m.created_at DESC, m.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $list->bindValue(':limit', $limit, PDO::PARAM_INT);
        $list->bindValue(':offset', $offset, PDO::PARAM_INT);
        $list->execute();
        return ['records' => $list->fetchAll(), 'total' => (int)$count->fetchColumn(), 'limit' => $limit, 'offset' => $offset, 'company_id' => $companyId];
    }

    public function find(int $id, UserContext $user, Authorization $authorization): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT m.*, p.name AS product_name, p.internal_reference, p.tracking, p.product_type, ' .
            'u.name AS uom_name, u.abbreviation AS uom_abbreviation, src.complete_name AS source_location, ' .
            'src.location_usage AS source_usage, dst.complete_name AS destination_location, dst.location_usage AS destination_usage ' .
            'FROM stock_moves m JOIN products p ON p.id = m.product_id JOIN units_of_measure u ON u.id = m.uom_id ' .
            'JOIN stock_locations src ON src.id = m.source_location_id JOIN stock_locations dst ON dst.id = m.destination_location_id ' .
            'WHERE m.id = :id LIMIT 1'
        );
        $query->execute(['id' => $id]);
        $move = $query->fetch();
        if (!$move || !$authorization->canAccessCompany((int)$move['company_id'])) {
            return null;
        }
        $lines = $this->pdo->prepare(
            'SELECT l.id, l.lot_id, COALESCE(lot.lot_code, l.lot_code_snapshot) AS lot_code, ' .
            'l.expiration_date_snapshot AS expiration_date, l.quantity, l.quantity_done,l.return_of_move_line_id,' .
            'CASE WHEN m.state=\'done\' AND dst.location_usage=\'customer\' THEN CAST(GREATEST(0,COALESCE(l.quantity_done,0)-COALESCE((SELECT SUM(CASE WHEN rm.state=\'done\' THEN COALESCE(rl.quantity_done,0) ELSE rl.quantity END) FROM stock_move_lines rl JOIN stock_moves rm ON rm.id=rl.move_id WHERE rl.return_of_move_line_id=l.id AND rm.state IN (\'confirmed\',\'done\')),0)) AS DECIMAL(20,6)) ' .
            'WHEN m.state=\'done\' AND m.origin_model=\'purchase_order\' AND m.source_line_model=\'purchase_order_line\' AND src.location_usage=\'vendor\' AND dst.location_usage IN (\'internal\',\'transit\') THEN CAST(GREATEST(0,COALESCE(l.quantity_done,0)-COALESCE((SELECT SUM(CASE WHEN rm.state=\'done\' THEN COALESCE(rl.quantity_done,0) ELSE rl.quantity END) FROM stock_move_lines rl JOIN stock_moves rm ON rm.id=rl.move_id WHERE rl.return_of_move_line_id=l.id AND rm.state IN (\'confirmed\',\'done\')),0)) AS DECIMAL(20,6)) ELSE 0 END AS returnable_quantity ' .
            'FROM stock_move_lines l JOIN stock_moves m ON m.id=l.move_id JOIN stock_locations src ON src.id=m.source_location_id JOIN stock_locations dst ON dst.id=m.destination_location_id ' .
            'LEFT JOIN stock_lots lot ON lot.id = l.lot_id WHERE l.move_id = :move_id ORDER BY l.id'
        );
        $lines->execute(['move_id' => $id]);
        $move['lines'] = $lines->fetchAll();
        $move['return_of_move_reference'] = null;
        if (!empty($move['return_of_move_id'])) {
            $parent=$this->pdo->prepare('SELECT reference FROM stock_moves WHERE id=:id AND company_id=:company');$parent->execute(['id'=>$move['return_of_move_id'],'company'=>$move['company_id']]);$move['return_of_move_reference']=$parent->fetchColumn()?:null;
        }
        return $move;
    }

    public function saveDraft(?int $id, array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), self::MOVE_FIELDS);
        if ($unknown) {
            throw new InvalidArgumentException('Unsupported stock move fields: ' . implode(', ', $unknown));
        }
        $existing = $id === null ? null : $this->find($id, $user, $authorization);
        if ($id !== null && !$existing) {
            throw new InvalidArgumentException('Stock move was not found or is outside the allowed companies.');
        }
        if ($existing && $existing['state'] !== 'draft') {
            throw new InvalidArgumentException('Only draft stock moves can be edited.');
        }
        $companyId = $existing
            ? (int)$existing['company_id']
            : $this->positiveId($input['company_id'] ?? $user->defaultCompanyId, 'Company');
        if (!$authorization->canAccessCompany($companyId)) {
            throw new InvalidArgumentException('The selected company is not available to your account.');
        }
        if (isset($input['company_id']) && $this->positiveId($input['company_id'], 'Company') !== $companyId) {
            throw new InvalidArgumentException('A stock move cannot be moved to another company.');
        }

        $productId = array_key_exists('product_id', $input) ? $this->positiveId($input['product_id'], 'Product') : (int)($existing['product_id'] ?? 0);
        $product = $this->product($productId, $companyId);
        if ($product['product_type'] !== 'goods') {
            throw new InvalidArgumentException('Only Goods products can be moved through Inventory.');
        }
        $uomId = array_key_exists('uom_id', $input) && $input['uom_id'] !== ''
            ? $this->positiveId($input['uom_id'], 'Unit of Measure')
            : (int)($existing['uom_id'] ?? $product['uom_id']);
        $uom = $this->uom($uomId, $companyId);
        $baseUom = $this->uom((int)$product['uom_id'], $companyId);
        if ((int)$uom['category_id'] !== (int)$baseUom['category_id']) {
            throw new InvalidArgumentException('Move unit and product unit must belong to the same unit category.');
        }

        $sourceId = array_key_exists('source_location_id', $input)
            ? $this->positiveId($input['source_location_id'], 'Source Location')
            : (int)($existing['source_location_id'] ?? 0);
        $destinationId = array_key_exists('destination_location_id', $input)
            ? $this->positiveId($input['destination_location_id'], 'Destination Location')
            : (int)($existing['destination_location_id'] ?? 0);
        $source = $this->location($sourceId, $companyId);
        $destination = $this->location($destinationId, $companyId);
        if ($sourceId === $destinationId) {
            throw new InvalidArgumentException('Source and destination locations must be different.');
        }
        if (!in_array($source['location_usage'], self::LOCATION_USAGES, true) || !in_array($destination['location_usage'], self::LOCATION_USAGES, true)) {
            throw new InvalidArgumentException('Source or destination location type is not supported.');
        }
        if (!$this->isStockLocation($source['location_usage']) && !$this->isStockLocation($destination['location_usage'])) {
            throw new InvalidArgumentException('A stock move must enter or leave a stock location.');
        }

        $demand = array_key_exists('demand_quantity', $input)
            ? $this->quantity($input['demand_quantity'], 'Demand Quantity')
            : (string)($existing['demand_quantity'] ?? '');
        if ($demand === '' || !$this->isPositive($demand)) {
            throw new InvalidArgumentException('Demand quantity must be greater than zero.');
        }
        $origin = $this->originFields($input, $existing);
        $scheduledAt = array_key_exists('scheduled_at', $input) ? $this->dateTime($input['scheduled_at']) : ($existing['scheduled_at'] ?? null);
        $rawLines = array_key_exists('lines', $input) ? $input['lines'] : ($existing['lines'] ?? []);
        $normalizedLines = $this->normalizeLines($rawLines, $companyId, $productId, (string)$product['tracking']);
        if ($product['tracking'] === 'none' && !$normalizedLines) {
            $normalizedLines = [['lot_id' => null, 'lot_code' => null, 'expiration_date' => null, 'quantity' => $demand]];
        }

        $before = $existing ? $this->auditValues($existing) : null;
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            $lockedLocations = $this->lockActiveLocations($sourceId, $destinationId, $companyId);
            $source = $lockedLocations[$sourceId];
            $destination = $lockedLocations[$destinationId];
            if ($sourceId === $destinationId) throw new InvalidArgumentException('Source and destination locations must be different.');
            if (!in_array($source['location_usage'], self::LOCATION_USAGES, true) || !in_array($destination['location_usage'], self::LOCATION_USAGES, true)) {
                throw new InvalidArgumentException('Source or destination location type is not supported.');
            }
            if (!$this->isStockLocation($source['location_usage']) && !$this->isStockLocation($destination['location_usage'])) {
                throw new InvalidArgumentException('A stock move must enter or leave a stock location.');
            }
            $values = [
                'company_id' => $companyId,
                'product_id' => $productId,
                'uom_id' => $uomId,
                'source_location_id' => $sourceId,
                'destination_location_id' => $destinationId,
                'demand_quantity' => $demand,
                'origin_model' => $origin['origin_model'],
                'origin_record_id' => $origin['origin_record_id'],
                'origin_reference' => $origin['origin_reference'],
                'scheduled_at' => $scheduledAt,
            ];
            if ($id === null) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO stock_moves (company_id, product_id, uom_id, source_location_id, destination_location_id, demand_quantity, ' .
                    'origin_model, origin_record_id, origin_reference, scheduled_at, created_by) VALUES ' .
                    '(:company_id, :product_id, :uom_id, :source_location_id, :destination_location_id, :demand_quantity, ' .
                    ':origin_model, :origin_record_id, :origin_reference, :scheduled_at, :created_by)'
                );
                $insert->execute($values + ['created_by' => $actorId]);
                $id = (int)$this->pdo->lastInsertId();
                $reference = 'STK/' . gmdate('Y') . '/' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
                $this->pdo->prepare('UPDATE stock_moves SET reference = :reference WHERE id = :id')->execute(['reference' => $reference, 'id' => $id]);
            } else {
                $lock = $this->pdo->prepare('SELECT state FROM stock_moves WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $id]);
                if ($lock->fetchColumn() !== 'draft') {
                    throw new InvalidArgumentException('This stock move changed state while you were editing it. Reload the draft before saving.');
                }
                $values['id'] = $id;
                $this->pdo->prepare(
                    'UPDATE stock_moves SET product_id=:product_id, uom_id=:uom_id, source_location_id=:source_location_id, ' .
                    'destination_location_id=:destination_location_id, demand_quantity=:demand_quantity, origin_model=:origin_model, ' .
                    'origin_record_id=:origin_record_id, origin_reference=:origin_reference, scheduled_at=:scheduled_at WHERE id=:id AND company_id=:company_id AND state=\'draft\''
                )->execute($values);
                $this->pdo->prepare('DELETE FROM stock_move_lines WHERE move_id = :move_id')->execute(['move_id' => $id]);
            }
            $this->insertMoveLines((int)$id, $normalizedLines);
            $saved = $this->find((int)$id, $user, $authorization);
            $this->audit($companyId, $actorId, (int)$id, $existing ? 'inventory.stock_move.updated' : 'inventory.stock_move.created', $before, $this->auditValues($saved ?? []), $remoteAddress);
            if ($ownsTransaction) $this->pdo->commit();
            return $saved ?? throw new \RuntimeException('Saved stock move could not be reloaded.');
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function createCustomerReturn(int $deliveryMoveId,array $input,UserContext $user,Authorization $authorization,int $actorId,string $remoteAddress):array
    {
        $unknown=array_diff(array_keys($input),['return_location_id','return_reason','lines']);if($unknown)throw new InvalidArgumentException('Unsupported customer return fields: '.implode(', ',$unknown));
        $reason=trim((string)($input['return_reason']??''));if(mb_strlen($reason)>190)throw new InvalidArgumentException('Return Reason must be 190 characters or fewer.');
        $rawLines=$input['lines']??null;if(!is_array($rawLines)||!array_is_list($rawLines)||!$rawLines)throw new InvalidArgumentException('Select at least one delivered lot/serial line and enter a return quantity.');
        $requested=[];foreach($rawLines as $index=>$row){if(!is_array($row))throw new InvalidArgumentException('Return line '.($index+1).' is invalid.');$fields=array_diff(array_keys($row),['source_line_id','quantity']);if($fields)throw new InvalidArgumentException('Unsupported customer return line fields: '.implode(', ',$fields));$lineId=$this->positiveId($row['source_line_id']??null,'Delivered Stock Move Line');if(isset($requested[$lineId]))throw new InvalidArgumentException('A delivered lot/serial line can only appear once on a return.');$quantity=$this->quantity($row['quantity']??null,'Return Quantity');if(!$this->isPositive($quantity))throw new InvalidArgumentException('Return Quantity must be greater than zero.');$requested[$lineId]=$quantity;}
        $this->pdo->beginTransaction();
        try{
            $lock=$this->pdo->prepare('SELECT m.*,p.tracking,p.product_type,dst.location_usage AS destination_usage FROM stock_moves m JOIN products p ON p.id=m.product_id JOIN stock_locations dst ON dst.id=m.destination_location_id WHERE m.id=:id FOR UPDATE');$lock->execute(['id'=>$deliveryMoveId]);$delivery=$lock->fetch();if(!$delivery||(int)$delivery['company_id']<1||!$authorization->canAccessCompany((int)$delivery['company_id']))throw new InvalidArgumentException('Customer delivery was not found or is outside the allowed companies.');$companyId=(int)$delivery['company_id'];if(!$authorization->allowsForCompany('inventory.write',$companyId))throw new \Erp\Core\Security\AccessDeniedException('You need Inventory Write access for this company.');
            if($delivery['state']!=='done'||$delivery['origin_model']!=='sale_order'||$delivery['source_line_model']!=='sales_order_line'||$delivery['destination_usage']!=='customer'||$delivery['product_type']!=='goods')throw new InvalidArgumentException('A customer return can only be created from a validated Sales delivery of Goods.');
            $salesLink=$this->pdo->prepare('SELECT so.id FROM sales_orders so JOIN sales_order_lines sl ON sl.order_id=so.id WHERE so.id=:order_id AND so.company_id=:company AND sl.id=:line_id AND sl.product_id=:product_id LIMIT 1');$salesLink->execute(['order_id'=>$delivery['origin_record_id'],'company'=>$companyId,'line_id'=>$delivery['source_line_id'],'product_id'=>$delivery['product_id']]);if($salesLink->fetchColumn()===false)throw new InvalidArgumentException('The delivery no longer points to a valid Sales Order line in this company.');
            $deliverySourceQuery=$this->pdo->prepare('SELECT location_usage FROM stock_locations WHERE id=:id AND company_id=:company');$deliverySourceQuery->execute(['id'=>$delivery['source_location_id'],'company'=>$companyId]);$deliverySourceUsage=$deliverySourceQuery->fetchColumn();if($deliverySourceUsage===false||!$this->isStockLocation((string)$deliverySourceUsage))throw new InvalidArgumentException('The original delivery must have started from an internal or transit location.');
            $returnLocationId=isset($input['return_location_id'])&&$input['return_location_id']!==''?$this->positiveId($input['return_location_id'],'Return Location'):(int)$delivery['source_location_id'];$returnLocation=$this->location($returnLocationId,$companyId);if(!$this->isStockLocation($returnLocation['location_usage']))throw new InvalidArgumentException('Return Location must be an active internal or transit location in this company.');
            $sourceLinesQuery=$this->pdo->prepare('SELECT l.id,l.lot_id,l.lot_code_snapshot,l.expiration_date_snapshot,l.quantity_done FROM stock_move_lines l WHERE l.move_id=:move AND l.quantity_done>0 ORDER BY l.id FOR UPDATE');$sourceLinesQuery->execute(['move'=>$deliveryMoveId]);$sourceLines=[];foreach($sourceLinesQuery->fetchAll() as $line)$sourceLines[(int)$line['id']]=$line;
            $demand='0.000000';$preparedLines=[];$used=[];$reservedQuery=$this->pdo->prepare("SELECT COALESCE(SUM(CASE WHEN rm.state='done' THEN COALESCE(rl.quantity_done,0) ELSE rl.quantity END),0) FROM stock_move_lines rl JOIN stock_moves rm ON rm.id=rl.move_id WHERE rl.return_of_move_line_id=:source_line AND rm.state IN ('confirmed','done')");
            foreach($requested as $sourceLineId=>$quantity){$sourceLine=$sourceLines[$sourceLineId]??null;if(!$sourceLine)throw new InvalidArgumentException('A selected return line is not part of the validated delivery.');$reservedQuery->execute(['source_line'=>$sourceLineId]);$reserved=(string)$reservedQuery->fetchColumn();$available=$this->maxZeroDecimal($this->subtractDecimal((string)$sourceLine['quantity_done'],$reserved));if(!$this->isPositive($available)||$this->compareDecimal($quantity,$available)>0)throw new InvalidArgumentException('Return Quantity cannot exceed the delivered quantity that has not already been returned or reserved.');if($delivery['tracking']==='serial'&&!$this->decimalEqualsOne($this->quantityInProductUom($quantity,(int)$delivery['uom_id'],(int)$this->product((int)$delivery['product_id'],$companyId)['uom_id'],$companyId)))throw new InvalidArgumentException('A serial-tracked return line must return exactly one product unit.');$used[$sourceLineId]=true;$preparedLines[]=['source_line_id'=>$sourceLineId,'source'=>$sourceLine,'quantity'=>$quantity];$demand=$this->addDecimal($demand,$quantity);}
            if(!$this->isPositive($demand))throw new InvalidArgumentException('Return Quantity must be greater than zero.');
            $insert=$this->pdo->prepare("INSERT INTO stock_moves (company_id,product_id,uom_id,source_location_id,destination_location_id,demand_quantity,state,origin_model,origin_record_id,source_line_model,source_line_id,origin_reference,return_of_move_id,return_reason,created_by) VALUES (:company,:product,:uom,:source,:destination,:demand,'confirmed','sale_order',:order_id,'sales_order_line',:sales_line,:origin_reference,:delivery,:reason,:actor)");$insert->execute(['company'=>$companyId,'product'=>$delivery['product_id'],'uom'=>$delivery['uom_id'],'source'=>$delivery['destination_location_id'],'destination'=>$returnLocationId,'demand'=>$demand,'order_id'=>$delivery['origin_record_id'],'sales_line'=>$delivery['source_line_id'],'origin_reference'=>$delivery['origin_reference'],'delivery'=>$deliveryMoveId,'reason'=>$reason!==''?$reason:null,'actor'=>$actorId]);$returnMoveId=(int)$this->pdo->lastInsertId();$reference='RET/'.$deliveryMoveId.'/'.$returnMoveId;$this->pdo->prepare('UPDATE stock_moves SET reference=:reference WHERE id=:id')->execute(['reference'=>$reference,'id'=>$returnMoveId]);
            $insertLine=$this->pdo->prepare('INSERT INTO stock_move_lines (move_id,lot_id,lot_code_snapshot,expiration_date_snapshot,quantity,return_of_move_line_id) VALUES (:move,:lot,:lot_code,:expiration,:quantity,:source_line)');foreach($preparedLines as $line)$insertLine->execute(['move'=>$returnMoveId,'lot'=>$line['source']['lot_id'],'lot_code'=>$line['source']['lot_code_snapshot'],'expiration'=>$line['source']['expiration_date_snapshot'],'quantity'=>$line['quantity'],'source_line'=>$line['source_line_id']]);
            $return=$this->find($returnMoveId,$user,$authorization)??throw new \RuntimeException('Customer return could not be reloaded.');$this->audit($companyId,$actorId,$returnMoveId,'inventory.customer_return.created',null,['reference'=>$reference,'delivery_move_id'=>$deliveryMoveId,'origin_record_id'=>(int)$delivery['origin_record_id'],'return_location_id'=>$returnLocationId,'return_reason'=>$reason,'demand_quantity'=>$demand,'source_move_line_ids'=>array_keys($used)],$remoteAddress);$this->audit($companyId,$actorId,$deliveryMoveId,'inventory.customer_delivery.return_created',['return_move_count'=>null],['return_move_id'=>$returnMoveId,'return_reference'=>$reference,'demand_quantity'=>$demand],$remoteAddress);$this->pdo->commit();return $return;
        }catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
    }

    public function createSupplierReturn(int $receiptMoveId,array $input,UserContext $user,Authorization $authorization,int $actorId,string $remoteAddress):array
    {
        $unknown=array_diff(array_keys($input),['return_reason','lines']);if($unknown)throw new InvalidArgumentException('Unsupported supplier return fields: '.implode(', ',$unknown));
        $reason=trim((string)($input['return_reason']??''));if(mb_strlen($reason)>190)throw new InvalidArgumentException('Return Reason must be 190 characters or fewer.');
        $rawLines=$input['lines']??null;if(!is_array($rawLines)||!array_is_list($rawLines)||!$rawLines)throw new InvalidArgumentException('Select at least one received lot/serial line and enter a return quantity.');
        $requested=[];foreach($rawLines as $index=>$row){if(!is_array($row))throw new InvalidArgumentException('Return line '.($index+1).' is invalid.');$fields=array_diff(array_keys($row),['source_line_id','quantity']);if($fields)throw new InvalidArgumentException('Unsupported supplier return line fields: '.implode(', ',$fields));$lineId=$this->positiveId($row['source_line_id']??null,'Received Stock Move Line');if(isset($requested[$lineId]))throw new InvalidArgumentException('A received lot/serial line can only appear once on a return.');$quantity=$this->quantity($row['quantity']??null,'Return Quantity');if(!$this->isPositive($quantity))throw new InvalidArgumentException('Return Quantity must be greater than zero.');$requested[$lineId]=$quantity;}
        $this->pdo->beginTransaction();
        try{
            $lock=$this->pdo->prepare('SELECT m.*,p.tracking,p.product_type,src.location_usage AS source_usage,dst.location_usage AS destination_usage FROM stock_moves m JOIN products p ON p.id=m.product_id JOIN stock_locations src ON src.id=m.source_location_id JOIN stock_locations dst ON dst.id=m.destination_location_id WHERE m.id=:id FOR UPDATE');$lock->execute(['id'=>$receiptMoveId]);$receipt=$lock->fetch();if(!$receipt||(int)$receipt['company_id']<1||!$authorization->canAccessCompany((int)$receipt['company_id']))throw new InvalidArgumentException('Purchase receipt was not found or is outside the allowed companies.');$companyId=(int)$receipt['company_id'];if(!$authorization->allowsForCompany('inventory.write',$companyId))throw new \Erp\Core\Security\AccessDeniedException('You need Inventory Write access for this company.');
            if($receipt['state']!=='done'||$receipt['origin_model']!=='purchase_order'||$receipt['source_line_model']!=='purchase_order_line'||$receipt['source_usage']!=='vendor'||!in_array($receipt['destination_usage'],['internal','transit'],true)||$receipt['product_type']!=='goods')throw new InvalidArgumentException('A supplier return can only be created from a validated Purchase receipt of Goods.');
            $purchaseLink=$this->pdo->prepare("SELECT po.id,po.partner_contact_id,po.name,po.vendor_reference,pol.id AS line_id FROM purchase_orders po JOIN purchase_order_lines pol ON pol.order_id=po.id WHERE po.id=:order_id AND po.company_id=:company AND po.state='purchase' AND pol.id=:line_id AND pol.product_id=:product_id");$purchaseLink->execute(['order_id'=>$receipt['origin_record_id'],'company'=>$companyId,'line_id'=>$receipt['source_line_id'],'product_id'=>$receipt['product_id']]);$purchase=$purchaseLink->fetch();if(!$purchase)throw new InvalidArgumentException('The receipt no longer points to a valid confirmed Purchase Order line in this company.');
            $sourceLinesQuery=$this->pdo->prepare('SELECT l.id,l.lot_id,l.lot_code_snapshot,l.expiration_date_snapshot,l.quantity_done FROM stock_move_lines l WHERE l.move_id=:move AND l.quantity_done>0 ORDER BY l.id FOR UPDATE');$sourceLinesQuery->execute(['move'=>$receiptMoveId]);$sourceLines=[];foreach($sourceLinesQuery->fetchAll() as $line)$sourceLines[(int)$line['id']]=$line;
            $demand='0.000000';$preparedLines=[];$used=[];$reservedQuery=$this->pdo->prepare("SELECT COALESCE(SUM(CASE WHEN rm.state='done' THEN COALESCE(rl.quantity_done,0) ELSE rl.quantity END),0) FROM stock_move_lines rl JOIN stock_moves rm ON rm.id=rl.move_id WHERE rl.return_of_move_line_id=:source_line AND rm.return_of_move_id=:receipt AND rm.company_id=:company AND rm.state IN ('confirmed','done')");
            foreach($requested as $sourceLineId=>$quantity){$sourceLine=$sourceLines[$sourceLineId]??null;if(!$sourceLine)throw new InvalidArgumentException('A selected return line is not part of the validated Purchase receipt.');$reservedQuery->execute(['source_line'=>$sourceLineId,'receipt'=>$receiptMoveId,'company'=>$companyId]);$reserved=(string)$reservedQuery->fetchColumn();$available=$this->maxZeroDecimal($this->subtractDecimal((string)$sourceLine['quantity_done'],$reserved));if(!$this->isPositive($available)||$this->compareDecimal($quantity,$available)>0)throw new InvalidArgumentException('Return Quantity cannot exceed the received quantity that has not already been returned or reserved.');if($receipt['tracking']==='serial'&&!$this->decimalEqualsOne($this->quantityInProductUom($quantity,(int)$receipt['uom_id'],(int)$this->product((int)$receipt['product_id'],$companyId)['uom_id'],$companyId)))throw new InvalidArgumentException('A serial-tracked return line must return exactly one product unit.');$used[$sourceLineId]=true;$preparedLines[]=['source_line_id'=>$sourceLineId,'source'=>$sourceLine,'quantity'=>$quantity];$demand=$this->addDecimal($demand,$quantity);}
            if(!$this->isPositive($demand))throw new InvalidArgumentException('Return Quantity must be greater than zero.');
            $insert=$this->pdo->prepare("INSERT INTO stock_moves (company_id,product_id,uom_id,source_location_id,destination_location_id,demand_quantity,state,origin_model,origin_record_id,source_line_model,source_line_id,origin_reference,return_of_move_id,return_reason,created_by) VALUES (:company,:product,:uom,:source,:destination,:demand,'confirmed','purchase_order',:order_id,'purchase_order_line',:purchase_line,:origin_reference,:receipt,:reason,:actor)");$insert->execute(['company'=>$companyId,'product'=>$receipt['product_id'],'uom'=>$receipt['uom_id'],'source'=>$receipt['destination_location_id'],'destination'=>$receipt['source_location_id'],'demand'=>$demand,'order_id'=>$purchase['id'],'purchase_line'=>$purchase['line_id'],'origin_reference'=>$purchase['vendor_reference']?:$purchase['name'],'receipt'=>$receiptMoveId,'reason'=>$reason!==''?$reason:null,'actor'=>$actorId]);$returnMoveId=(int)$this->pdo->lastInsertId();$reference='VRET/'.$receiptMoveId.'/'.$returnMoveId;$this->pdo->prepare('UPDATE stock_moves SET reference=:reference WHERE id=:id')->execute(['reference'=>$reference,'id'=>$returnMoveId]);
            $insertLine=$this->pdo->prepare('INSERT INTO stock_move_lines (move_id,lot_id,lot_code_snapshot,expiration_date_snapshot,quantity,return_of_move_line_id) VALUES (:move,:lot,:lot_code,:expiration,:quantity,:source_line)');foreach($preparedLines as $line)$insertLine->execute(['move'=>$returnMoveId,'lot'=>$line['source']['lot_id'],'lot_code'=>$line['source']['lot_code_snapshot'],'expiration'=>$line['source']['expiration_date_snapshot'],'quantity'=>$line['quantity'],'source_line'=>$line['source_line_id']]);
            $return=$this->find($returnMoveId,$user,$authorization)??throw new \RuntimeException('Supplier return could not be reloaded.');$this->audit($companyId,$actorId,$returnMoveId,'inventory.supplier_return.created',null,['reference'=>$reference,'receipt_move_id'=>$receiptMoveId,'purchase_order_id'=>(int)$purchase['id'],'supplier_contact_id'=>(int)$purchase['partner_contact_id'],'return_reason'=>$reason,'demand_quantity'=>$demand,'source_move_line_ids'=>array_keys($used)],$remoteAddress);$this->audit($companyId,$actorId,$receiptMoveId,'inventory.purchase_receipt.supplier_return_created',['return_move_count'=>null],['return_move_id'=>$returnMoveId,'return_reference'=>$reference,'demand_quantity'=>$demand],$remoteAddress);$this->pdo->commit();return $return;
        }catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
    }

    public function confirm(int $id, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            $move = $this->lockedMove($id, $user, $authorization);
            if ($move['state'] !== 'draft') throw new InvalidArgumentException('Only draft stock moves can be confirmed.');
            $product = $this->product((int)$move['product_id'], (int)$move['company_id']);
            $lines = $this->moveLines($id);
            $this->assertLinesMatchDemand($lines, (string)$move['demand_quantity']);
            if ($product['tracking'] === 'none') {
                if (count($lines) !== 1 || $lines[0]['lot_id'] !== null || $lines[0]['lot_code_snapshot'] !== null) {
                    throw new InvalidArgumentException('Untracked products must have one quantity line and no lot or serial.');
                }
            } else {
                if (!$lines) throw new InvalidArgumentException('Add lot or serial lines before confirming this tracked product move.');
                if ($product['tracking'] === 'serial') {
                    foreach ($lines as $line) {
                        if ($line['lot_code_snapshot'] === null || !$this->decimalEqualsOne($this->quantityInProductUom((string)$line['quantity'], (int)$move['uom_id'], (int)$product['uom_id'], (int)$move['company_id']))) {
                            throw new InvalidArgumentException('Each serial-tracked move line must identify one serial and contain exactly one product unit.');
                        }
                    }
                }
            }
            $source = $this->location((int)$move['source_location_id'], (int)$move['company_id']);
            foreach ($lines as $line) {
                $lotId = $this->resolveExistingLot($line, $product, (int)$move['company_id'], $this->isStockLocation($source['location_usage']));
                if ($lotId !== null && (int)$line['lot_id'] !== $lotId) {
                    $this->pdo->prepare('UPDATE stock_move_lines SET lot_id = :lot_id WHERE id = :id')->execute(['lot_id' => $lotId, 'id' => $line['id']]);
                }
                if ($this->isStockLocation($source['location_usage'])) {
                    $baseQuantity = $this->quantityInProductUom((string)$line['quantity'], (int)$move['uom_id'], (int)$product['uom_id'], (int)$move['company_id']);
                    $this->reserve((int)$move['company_id'], (int)$move['product_id'], (int)$move['source_location_id'], $lotId, $baseQuantity);
                }
            }
            $before = $this->auditValues($move);
            $this->pdo->prepare("UPDATE stock_moves SET state='confirmed' WHERE id=:id")->execute(['id' => $id]);
            $after = $this->find($id, $user, $authorization);
            $this->audit((int)$move['company_id'], $actorId, $id, 'inventory.stock_move.confirmed', $before, $this->auditValues($after ?? []), $remoteAddress);
            if ($ownsTransaction) $this->pdo->commit();
            return $after ?? throw new \RuntimeException('Confirmed stock move could not be reloaded.');
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function validate(int $id, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress, array $input = [], ?string $unitCostOverride = null): array
    {
        $unknown = array_diff(array_keys($input), ['done_quantities']);
        if ($unknown) throw new InvalidArgumentException('Unsupported stock validation fields: ' . implode(', ', $unknown));
        $companyQuery = $this->pdo->prepare('SELECT company_id FROM stock_moves WHERE id=:id LIMIT 1');
        $companyQuery->execute(['id' => $id]);
        $companyId = (int)$companyQuery->fetchColumn();
        if ($companyId < 1 || !$authorization->canAccessCompany($companyId)) throw new InvalidArgumentException('Stock move was not found or is outside your allowed companies.');
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            \Erp\Modules\Accounting\AccountingDateGuard::lockAndAssertOpen($this->pdo, $companyId, $this->companyToday($companyId));
            $move = $this->lockedMove($id, $user, $authorization);
            if ($move['state'] !== 'confirmed') throw new InvalidArgumentException('Only confirmed stock moves can be validated.');
            $this->pdo->prepare('SELECT id FROM products WHERE id=:product AND company_id=:company FOR UPDATE')
                ->execute(['product' => $move['product_id'], 'company' => $move['company_id']]);
            $product = $this->product((int)$move['product_id'], (int)$move['company_id']);
            $lines = $this->moveLines($id);
            $this->assertLinesMatchDemand($lines, (string)$move['demand_quantity']);
            $doneQuantities = $this->normalizeDoneQuantities($input['done_quantities'] ?? null, $lines, $product);
            $source = $this->location((int)$move['source_location_id'], (int)$move['company_id']);
            $destination = $this->location((int)$move['destination_location_id'], (int)$move['company_id']);
            if ($unitCostOverride !== null) {
                $overrideQuery = $this->pdo->prepare('SELECT CAST(:cost AS DECIMAL(20,6)) >= 0');
                $overrideQuery->execute(['cost' => $unitCostOverride]);
                if ($move['origin_model'] !== 'manufacturing' || $source['location_usage'] !== 'production' || !$this->isStockLocation($destination['location_usage']) || !(bool)$overrideQuery->fetchColumn()) {
                    throw new InvalidArgumentException('A unit cost override is only valid for a manufacturing receipt from Production into stock.');
                }
            }
            $done = '0.000000';
            $valuationValue = '0.000000';
            $backorderLines = [];
            $valuation = new StockValuationService($this->pdo);
            foreach ($lines as $line) {
                $lineDone = $doneQuantities[(int)$line['id']];
                $remaining = $this->subtractDecimal((string)$line['quantity'], $lineDone);
                $this->pdo->prepare('UPDATE stock_move_lines SET quantity_done=:done WHERE id=:id')->execute(['done' => $lineDone, 'id' => $line['id']]);
                if ($this->isPositive($remaining)) $backorderLines[] = $line + ['remaining_quantity' => $remaining];
                $lotId = $line['lot_id'] === null ? null : (int)$line['lot_id'];
                if ($this->isStockLocation($source['location_usage']) && $this->isPositive($remaining)) {
                    $remainingBase = $this->quantityInProductUom($remaining, (int)$move['uom_id'], (int)$product['uom_id'], (int)$move['company_id']);
                    $this->releaseReservation((int)$move['company_id'], (int)$move['product_id'], (int)$move['source_location_id'], $lotId, $remainingBase);
                }
                if (!$this->isPositive($lineDone)) continue;
        $incomingSerial = $product['tracking'] === 'serial' && in_array($source['location_usage'], ['vendor', 'production'], true);
                if ($product['tracking'] !== 'none' && ($lotId === null || $incomingSerial)) {
                    $lotId = $this->findOrCreateIncomingLot($line, $product, (int)$move['company_id'], $source, $actorId);
                    if ((int)($line['lot_id'] ?? 0) !== $lotId) {
                        $this->pdo->prepare('UPDATE stock_move_lines SET lot_id = :lot_id WHERE id = :id')->execute(['lot_id' => $lotId, 'id' => $line['id']]);
                    }
                }
                $baseQuantity = $this->quantityInProductUom($lineDone, (int)$move['uom_id'], (int)$product['uom_id'], (int)$move['company_id']);
                if ($this->isStockLocation($source['location_usage'])) {
                    $this->consumeReservation((int)$move['company_id'], (int)$move['product_id'], (int)$move['source_location_id'], $lotId, $baseQuantity);
                }
                if ($this->isStockLocation($destination['location_usage'])) {
                    $this->increaseQuant((int)$move['company_id'], (int)$move['product_id'], (int)$move['destination_location_id'], $lotId, $baseQuantity);
                }
                $valuationLine = $valuation->applyMoveLine(
                    $move,
                    $line,
                    (string)$source['location_usage'],
                    (string)$destination['location_usage'],
                    $baseQuantity,
                    $actorId,
                    $unitCostOverride
                );
                if ($valuationLine !== null) $valuationValue = $this->addDecimal($valuationValue, $valuationLine['value_delta']);
                $done = $this->addDecimal($done, $lineDone);
            }
            if (!$this->isPositive($done)) throw new InvalidArgumentException('Enter a positive done quantity before validating this move.');
            $before = $this->auditValues($move);
            $this->pdo->prepare("UPDATE stock_moves SET state='done', done_quantity=:done_quantity, valuation_value=:valuation_value, done_at=UTC_TIMESTAMP() WHERE id=:id")
                ->execute(['done_quantity' => $done, 'valuation_value' => $valuationValue, 'id' => $id]);
            $backorder = $backorderLines ? $this->createBackorder($move, $backorderLines, $user, $authorization, $actorId, $remoteAddress) : null;
            $after = $this->find($id, $user, $authorization);
            $this->audit((int)$move['company_id'], $actorId, $id, 'inventory.stock_move.done', $before, $this->auditValues($after ?? []), $remoteAddress);
            if ($ownsTransaction) $this->pdo->commit();
            if ($backorder !== null) {
                if ($after === null) $after = [];
                $after['backorder'] = $backorder;
            }
            return $after ?? throw new \RuntimeException('Validated stock move could not be reloaded.');
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function cancel(int $id, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            $move = $this->lockedMove($id, $user, $authorization);
            if (!in_array($move['state'], ['draft', 'confirmed'], true)) {
                throw new InvalidArgumentException('Only draft or confirmed stock moves can be cancelled; done moves require a reversing move.');
            }
            if ($move['state'] === 'confirmed') {
                $product = $this->product((int)$move['product_id'], (int)$move['company_id']);
                $source = $this->location((int)$move['source_location_id'], (int)$move['company_id']);
                if ($this->isStockLocation($source['location_usage'])) {
                    foreach ($this->moveLines($id) as $line) {
                        $baseQuantity = $this->quantityInProductUom((string)$line['quantity'], (int)$move['uom_id'], (int)$product['uom_id'], (int)$move['company_id']);
                        $this->releaseReservation((int)$move['company_id'], (int)$move['product_id'], (int)$move['source_location_id'], $line['lot_id'] === null ? null : (int)$line['lot_id'], $baseQuantity);
                    }
                }
            }
            $before = $this->auditValues($move);
            $this->pdo->prepare("UPDATE stock_moves SET state='cancelled' WHERE id=:id")->execute(['id' => $id]);
            $after = $this->find($id, $user, $authorization);
            $this->audit((int)$move['company_id'], $actorId, $id, 'inventory.stock_move.cancelled', $before, $this->auditValues($after ?? []), $remoteAddress);
            if ($ownsTransaction) $this->pdo->commit();
            return $after ?? throw new \RuntimeException('Cancelled stock move could not be reloaded.');
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function quants(UserContext $user, Authorization $authorization, int $companyId, int $productId): array
    {
        $companyId = $this->resolveCompany($user, $authorization, $companyId);
        $this->product($productId, $companyId);
        $query = $this->pdo->prepare(
            'SELECT q.id, q.product_id, q.location_id, l.complete_name AS location, l.location_usage, q.lot_id, lot.lot_code, ' .
            'q.quantity, q.reserved_quantity, CAST(q.quantity-q.reserved_quantity AS DECIMAL(20,6)) AS available_quantity ' .
            'FROM stock_quants q JOIN stock_locations l ON l.id=q.location_id LEFT JOIN stock_lots lot ON lot.id=q.lot_id ' .
            'WHERE q.company_id=:company_id AND q.product_id=:product_id AND (q.quantity<>0 OR q.reserved_quantity<>0) ' .
            'ORDER BY l.complete_name, lot.lot_code, q.id'
        );
        $query->execute(['company_id' => $companyId, 'product_id' => $productId]);
        return $query->fetchAll();
    }

    public function stockLevels(UserContext $user, Authorization $authorization, mixed $rawCompanyId): array
    {
        $companyId = $this->resolveCompany($user, $authorization, $rawCompanyId);
        $query = $this->pdo->prepare(
            'SELECT q.product_id, p.name AS product_name, p.internal_reference, u.abbreviation AS uom, ' .
            'q.location_id, l.complete_name AS location, l.location_usage, q.lot_id, lot.lot_code, ' .
            'q.quantity, q.reserved_quantity, CAST(q.quantity-q.reserved_quantity AS DECIMAL(20,6)) AS available_quantity ' .
            'FROM stock_quants q JOIN products p ON p.id=q.product_id JOIN units_of_measure u ON u.id=p.uom_id ' .
            'JOIN stock_locations l ON l.id=q.location_id LEFT JOIN stock_lots lot ON lot.id=q.lot_id ' .
            'WHERE q.company_id=:company_id AND (q.quantity<>0 OR q.reserved_quantity<>0) ' .
            'ORDER BY p.name, l.complete_name, lot.lot_code, q.id'
        );
        $query->execute(['company_id' => $companyId]);
        return ['records' => $query->fetchAll(), 'company_id' => $companyId];
    }

    private function lockedMove(int $id, UserContext $user, Authorization $authorization): array
    {
        $query = $this->pdo->prepare('SELECT * FROM stock_moves WHERE id=:id FOR UPDATE');
        $query->execute(['id' => $id]);
        $move = $query->fetch();
        if (!$move || !$authorization->canAccessCompany((int)$move['company_id'])) {
            throw new InvalidArgumentException('Stock move was not found or is outside the allowed companies.');
        }
        return $move;
    }

    private function product(int $id, int $companyId): array
    {
        $query = $this->pdo->prepare('SELECT id, company_id, name, product_type, tracking, uom_id FROM products WHERE id=:id AND company_id=:company_id AND active=1 LIMIT 1');
        $query->execute(['id' => $id, 'company_id' => $companyId]);
        $product = $query->fetch();
        if (!$product) throw new InvalidArgumentException('Select an active product available to this company.');
        return $product;
    }

    private function uom(int $id, int $companyId): array
    {
        $query = $this->pdo->prepare(
            'SELECT u.id, u.category_id, u.factor_to_reference FROM units_of_measure u ' .
            'JOIN uom_categories c ON c.id=u.category_id WHERE u.id=:id AND u.active=1 AND c.active=1 ' .
            'AND (u.company_id IS NULL OR u.company_id=:unit_company) AND (c.company_id IS NULL OR c.company_id=:category_company) LIMIT 1'
        );
        $query->execute(['id' => $id, 'unit_company' => $companyId, 'category_company' => $companyId]);
        $uom = $query->fetch();
        if (!$uom) throw new InvalidArgumentException('Select an active unit of measure.');
        return $uom;
    }

    private function location(int $id, int $companyId): array
    {
        $query = $this->pdo->prepare('SELECT id, company_id, location_usage, active FROM stock_locations WHERE id=:id AND company_id=:company_id LIMIT 1 FOR UPDATE');
        $query->execute(['id' => $id, 'company_id' => $companyId]);
        $location = $query->fetch();
        if (!$location || (int)$location['active'] !== 1) throw new InvalidArgumentException('Select an active stock location available to this company.');
        return $location;
    }

    private function lockActiveLocations(int $sourceId, int $destinationId, int $companyId): array
    {
        $query = $this->pdo->prepare('SELECT id,company_id,location_usage,active FROM stock_locations WHERE company_id=:company AND id IN (:source,:destination) ORDER BY id FOR UPDATE');
        $query->execute(['company' => $companyId, 'source' => $sourceId, 'destination' => $destinationId]);
        $locations = [];
        foreach ($query->fetchAll() as $location) {
            if ((int)$location['active'] !== 1) throw new InvalidArgumentException('Select an active stock location available to this company.');
            $locations[(int)$location['id']] = $location;
        }
        if (count($locations) !== 2) throw new InvalidArgumentException('Select active source and destination locations available to this company.');
        return $locations;
    }

    private function originFields(array $input, ?array $existing): array
    {
        $model = array_key_exists('origin_model', $input) ? trim((string)$input['origin_model']) : (string)($existing['origin_model'] ?? '');
        $recordId = array_key_exists('origin_record_id', $input) ? $input['origin_record_id'] : ($existing['origin_record_id'] ?? null);
        $reference = array_key_exists('origin_reference', $input) ? trim((string)$input['origin_reference']) : (string)($existing['origin_reference'] ?? '');
        $model = $model === '' ? null : $model;
        $reference = $reference === '' ? null : $reference;
        // The Inventory form calls this source "Sales"; Sales creates the
        // corresponding stock-move origin with the internal model key below.
        if ($model === 'sales') $model = 'sale_order';
        if ($model !== null && !in_array($model, ['sale_order', 'purchase', 'inventory', 'manufacturing'], true)) {
            throw new InvalidArgumentException('Origin model must be sales, purchase, inventory, or manufacturing.');
        }
        $recordId = $recordId === null || $recordId === '' ? null : $this->positiveId($recordId, 'Origin Record');
        if (($model === null) !== ($recordId === null)) {
            throw new InvalidArgumentException('Origin model and origin record ID must be supplied together.');
        }
        if ($reference !== null && mb_strlen($reference) > 120) throw new InvalidArgumentException('Origin reference must be 120 characters or fewer.');
        return ['origin_model' => $model, 'origin_record_id' => $recordId, 'origin_reference' => $reference];
    }

    private function normalizeLines(mixed $rawLines, int $companyId, int $productId, string $tracking): array
    {
        if (!is_array($rawLines)) throw new InvalidArgumentException('Stock move lines must be a list.');
        $lines = [];
        foreach ($rawLines as $index => $row) {
            if (!is_array($row)) throw new InvalidArgumentException('Stock move line ' . ($index + 1) . ' must be an object.');
            $unknown = array_diff(array_keys($row), ['lot_id', 'lot_code', 'expiration_date', 'quantity']);
            if ($unknown) throw new InvalidArgumentException('Unsupported stock move line fields: ' . implode(', ', $unknown));
            $quantity = $this->quantity($row['quantity'] ?? null, 'Line Quantity');
            $lotId = null;
            $lotCode = trim((string)($row['lot_code'] ?? ''));
            $lotCode = $lotCode === '' ? null : $lotCode;
            if ($lotCode !== null && mb_strlen($lotCode) > 120) throw new InvalidArgumentException('Lot or serial code must be 120 characters or fewer.');
            if (($row['lot_id'] ?? '') !== '') {
                $lotId = $this->positiveId($row['lot_id'], 'Lot');
                $query = $this->pdo->prepare('SELECT lot_code,expiration_date FROM stock_lots WHERE id=:id AND company_id=:company_id AND product_id=:product_id AND active=1 LIMIT 1');
                $query->execute(['id' => $lotId, 'company_id' => $companyId, 'product_id' => $productId]);
                $existingLot = $query->fetch();
                if (!$existingLot) throw new InvalidArgumentException('The selected lot or serial does not belong to this product and company.');
                if ($lotCode !== null && $lotCode !== $existingLot['lot_code']) throw new InvalidArgumentException('Lot ID and lot code do not match.');
                $lotCode = (string)$existingLot['lot_code'];
                if (($row['expiration_date'] ?? null) === null || ($row['expiration_date'] ?? '') === '') $row['expiration_date'] = $existingLot['expiration_date'];
            } elseif ($lotCode !== null) {
                $query = $this->pdo->prepare('SELECT id,expiration_date FROM stock_lots WHERE company_id=:company_id AND product_id=:product_id AND lot_code=:code AND active=1 LIMIT 1');
                $query->execute(['company_id' => $companyId, 'product_id' => $productId, 'code' => $lotCode]);
                $existingLot = $query->fetch();
                if ($existingLot) {
                    $lotId = (int)$existingLot['id'];
                    if (($row['expiration_date'] ?? null) === null || ($row['expiration_date'] ?? '') === '') $row['expiration_date'] = $existingLot['expiration_date'];
                }
            }
            if ($tracking === 'none' && ($lotId !== null || $lotCode !== null)) throw new InvalidArgumentException('This product is not lot or serial tracked.');
            if ($tracking !== 'none' && $lotCode === null) throw new InvalidArgumentException('Each tracked product line needs a lot or serial code.');
            $expiration = $this->date($row['expiration_date'] ?? null);
            $lines[] = ['lot_id' => $lotId, 'lot_code' => $lotCode, 'expiration_date' => $expiration, 'quantity' => $quantity];
        }
        return $lines;
    }

    private function insertMoveLines(int $moveId, array $lines): void
    {
        if (!$lines) return;
        $insert = $this->pdo->prepare(
            'INSERT INTO stock_move_lines (move_id, lot_id, lot_code_snapshot, expiration_date_snapshot, quantity) ' .
            'VALUES (:move_id, :lot_id, :lot_code, :expiration, :quantity)'
        );
        foreach ($lines as $line) {
            $insert->execute(['move_id' => $moveId, 'lot_id' => $line['lot_id'], 'lot_code' => $line['lot_code'], 'expiration' => $line['expiration_date'], 'quantity' => $line['quantity']]);
        }
    }

    private function moveLines(int $moveId): array
    {
        $query = $this->pdo->prepare('SELECT id, lot_id, lot_code_snapshot, expiration_date_snapshot, quantity,return_of_move_line_id FROM stock_move_lines WHERE move_id=:move_id ORDER BY id');
        $query->execute(['move_id' => $moveId]);
        return $query->fetchAll();
    }

    private function assertLinesMatchDemand(array $lines, string $demand): void
    {
        if (!$lines) throw new InvalidArgumentException('Add move lines before confirming or validating this stock move.');
        $total = '0.000000';
        foreach ($lines as $line) $total = $this->addDecimal($total, (string)$line['quantity']);
        $comparison = $this->pdo->prepare('SELECT CAST(:total AS DECIMAL(20,6)) = CAST(:demand AS DECIMAL(20,6))');
        $comparison->execute(['total' => $total, 'demand' => $demand]);
        if (!(bool)$comparison->fetchColumn()) throw new InvalidArgumentException('Move line quantities must exactly match the demanded quantity.');
    }

    /** @return array<int,string> */
    private function normalizeDoneQuantities(mixed $raw, array $lines, array $product): array
    {
        $quantities = [];
        if ($raw === null) {
            foreach ($lines as $line) $quantities[(int)$line['id']] = (string)$line['quantity'];
            return $quantities;
        }
        if (!is_array($raw) || array_is_list($raw) === false) throw new InvalidArgumentException('Done quantities must be a list of move-line quantities.');
        $allowedIds = array_fill_keys(array_map(static fn(array $line): int => (int)$line['id'], $lines), true);
        foreach ($raw as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['line_id', 'quantity']) || !isset($row['line_id']) || !array_key_exists('quantity', $row)) {
                throw new InvalidArgumentException('Each done-quantity row needs only a line ID and quantity.');
            }
            $lineId = filter_var($row['line_id'], FILTER_VALIDATE_INT);
            if ($lineId === false || !isset($allowedIds[$lineId]) || isset($quantities[$lineId])) throw new InvalidArgumentException('Done quantities must refer to each move line exactly once.');
            $quantity = $this->nonNegativeQuantity($row['quantity'], 'Done Quantity');
            $line = null;
            foreach ($lines as $candidate) if ((int)$candidate['id'] === $lineId) { $line = $candidate; break; }
            if ($line === null || $this->compareDecimal($quantity, (string)$line['quantity']) > 0) throw new InvalidArgumentException('A done quantity cannot exceed the quantity reserved on its move line.');
            if ($product['tracking'] === 'serial' && $this->isPositive($quantity) && $this->compareDecimal($quantity, (string)$line['quantity']) !== 0) {
                throw new InvalidArgumentException('A serial-tracked move line must be completed as one whole serial unit or left entirely for the backorder.');
            }
            $quantities[$lineId] = $quantity;
        }
        if (count($quantities) !== count($lines)) throw new InvalidArgumentException('Provide a done quantity for every detailed operation line.');
        $total = '0.000000';
        foreach ($quantities as $quantity) $total = $this->addDecimal($total, $quantity);
        if ($this->compareDecimal($total, '0') <= 0) throw new InvalidArgumentException('Done quantity must be greater than zero.');
        return $quantities;
    }

    private function createBackorder(array $move, array $lines, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $demand = '0.000000';
        foreach ($lines as $line) $demand = $this->addDecimal($demand, (string)$line['remaining_quantity']);
        if (!$this->isPositive($demand)) throw new \LogicException('A stock backorder must have a positive quantity.');
        $this->pdo->prepare('INSERT INTO stock_moves (company_id,product_id,uom_id,source_location_id,destination_location_id,demand_quantity,state,origin_model,origin_record_id,source_line_model,source_line_id,origin_reference,backorder_of_move_id,return_of_move_id,return_reason,created_by) VALUES (:company,:product,:uom,:source,:destination,:demand,\'confirmed\',:origin_model,:origin_id,:source_model,:source_id,:origin_reference,:parent,:return_parent,:return_reason,:actor)')
            ->execute(['company'=>$move['company_id'],'product'=>$move['product_id'],'uom'=>$move['uom_id'],'source'=>$move['source_location_id'],'destination'=>$move['destination_location_id'],'demand'=>$demand,'origin_model'=>$move['origin_model'],'origin_id'=>$move['origin_record_id'],'source_model'=>$move['source_line_model']??null,'source_id'=>$move['source_line_id']??null,'origin_reference'=>$move['origin_reference'],'parent'=>$move['id'],'return_parent'=>$move['return_of_move_id']??null,'return_reason'=>$move['return_reason']??null,'actor'=>$actorId]);
        $backorderId = (int)$this->pdo->lastInsertId();
        $reference = 'BO/' . $move['id'];
        $this->pdo->prepare('UPDATE stock_moves SET reference=:reference WHERE id=:id')->execute(['reference'=>mb_substr($reference,0,40),'id'=>$backorderId]);
        $insert = $this->pdo->prepare('INSERT INTO stock_move_lines (move_id,lot_id,lot_code_snapshot,expiration_date_snapshot,quantity,return_of_move_line_id) VALUES (:move,:lot,:code,:expiration,:quantity,:return_line)');
        $product = $this->product((int)$move['product_id'], (int)$move['company_id']);
        $source = $this->location((int)$move['source_location_id'], (int)$move['company_id']);
        foreach ($lines as $line) {
            $quantity = (string)$line['remaining_quantity'];
            $insert->execute(['move'=>$backorderId,'lot'=>$line['lot_id'],'code'=>$line['lot_code_snapshot']??null,'expiration'=>$line['expiration_date_snapshot']??null,'quantity'=>$quantity,'return_line'=>$line['return_of_move_line_id']??null]);
            if ($this->isStockLocation($source['location_usage'])) {
                $base = $this->quantityInProductUom($quantity, (int)$move['uom_id'], (int)$product['uom_id'], (int)$move['company_id']);
                $this->reserve((int)$move['company_id'], (int)$move['product_id'], (int)$move['source_location_id'], $line['lot_id']===null?null:(int)$line['lot_id'], $base);
            }
        }
        $backorder = $this->find($backorderId, $user, $authorization) ?? throw new \RuntimeException('Backorder could not be reloaded.');
        $this->audit((int)$move['company_id'],$actorId,$backorderId,'inventory.stock_move.backorder_created',null,['reference'=>$reference,'backorder_of_move_id'=>(int)$move['id'],'demand_quantity'=>$demand],$remoteAddress);
        return $backorder;
    }

    private function resolveExistingLot(array $line, array $product, int $companyId, bool $required): ?int
    {
        if ($product['tracking'] === 'none') return null;
        if ($line['lot_id'] !== null) return (int)$line['lot_id'];
        $code = trim((string)($line['lot_code_snapshot'] ?? ''));
        if ($code === '') throw new InvalidArgumentException('A lot or serial code is required for this product.');
        $query = $this->pdo->prepare('SELECT id FROM stock_lots WHERE company_id=:company_id AND product_id=:product_id AND lot_code=:code AND active=1 LIMIT 1');
        $query->execute(['company_id' => $companyId, 'product_id' => $product['id'], 'code' => $code]);
        $id = $query->fetchColumn();
        if ($id === false && $required) throw new InvalidArgumentException('Lot or serial ' . $code . ' is not registered for this product.');
        return $id === false ? null : (int)$id;
    }

    private function findOrCreateIncomingLot(array $line, array $product, int $companyId, array $source, int $actorId): int
    {
        $code = trim((string)($line['lot_code_snapshot'] ?? ''));
        if ($code === '') throw new InvalidArgumentException('A lot or serial code is required before receipt validation.');
        $query = $this->pdo->prepare('SELECT id FROM stock_lots WHERE company_id=:company_id AND product_id=:product_id AND lot_code=:code AND active=1 LIMIT 1 FOR UPDATE');
        $query->execute(['company_id' => $companyId, 'product_id' => $product['id'], 'code' => $code]);
        $existingId = $query->fetchColumn();
        if ($existingId !== false) {
            if ($product['tracking'] === 'serial' && in_array($source['location_usage'], ['vendor', 'production'], true)) {
                $stock = $this->pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM stock_quants WHERE company_id=:company_id AND product_id=:product_id AND lot_id=:lot_id');
                $stock->execute(['company_id' => $companyId, 'product_id' => $product['id'], 'lot_id' => $existingId]);
                $inStock = $stock->fetchColumn();
                $positive = $this->pdo->prepare('SELECT CAST(:quantity AS DECIMAL(20,6)) > 0');
                $positive->execute(['quantity' => (string)$inStock]);
                if ((bool)$positive->fetchColumn()) throw new InvalidArgumentException('This serial number is already in stock; a receipt cannot duplicate it.');
            }
            return (int)$existingId;
        }
        if (!in_array($source['location_usage'], ['vendor', 'inventory', 'production'], true)) {
            throw new InvalidArgumentException('A new lot or serial can only be created from a Vendor, Inventory Adjustment, or Production location.');
        }
        $insert = $this->pdo->prepare('INSERT INTO stock_lots (company_id, product_id, lot_code, expiration_date, created_by) VALUES (:company_id, :product_id, :code, :expiration, :created_by)');
        $insert->execute([
            'company_id' => $companyId, 'product_id' => $product['id'], 'code' => $code,
            'expiration' => $line['expiration_date_snapshot'], 'created_by' => $actorId,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function companyToday(int $companyId): string
    {
        $query = $this->pdo->prepare('SELECT timezone FROM companies WHERE id=:company AND active=1');
        $query->execute(['company' => $companyId]);
        $timezone = (string)$query->fetchColumn();
        if ($timezone === '') $timezone = 'UTC';
        try {
            return (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d');
        } catch (\Throwable) {
            return gmdate('Y-m-d');
        }
    }

    private function reserve(int $companyId, int $productId, int $locationId, ?int $lotId, string $quantity): void
    {
        $quantId = $this->lockedQuant($companyId, $productId, $locationId, $lotId);
        $update = $this->pdo->prepare(
            'UPDATE stock_quants SET reserved_quantity=reserved_quantity+:quantity ' .
            'WHERE id=:id AND (quantity-reserved_quantity)>=CAST(:check_quantity AS DECIMAL(20,6))'
        );
        $update->execute(['quantity' => $quantity, 'id' => $quantId, 'check_quantity' => $quantity]);
        if ($update->rowCount() !== 1) throw new InvalidArgumentException('Not enough unreserved stock is available at the source location for this move.');
    }

    private function consumeReservation(int $companyId, int $productId, int $locationId, ?int $lotId, string $quantity): void
    {
        $quantId = $this->lockedQuant($companyId, $productId, $locationId, $lotId);
        $update = $this->pdo->prepare(
            'UPDATE stock_quants SET quantity=quantity-:quantity, reserved_quantity=reserved_quantity-:reserved_quantity ' .
            'WHERE id=:id AND quantity>=CAST(:check_quantity AS DECIMAL(20,6)) ' .
            'AND reserved_quantity>=CAST(:check_reserved AS DECIMAL(20,6))'
        );
        $update->execute(['quantity' => $quantity, 'reserved_quantity' => $quantity, 'id' => $quantId, 'check_quantity' => $quantity, 'check_reserved' => $quantity]);
        if ($update->rowCount() !== 1) throw new InvalidArgumentException('Reserved stock changed before validation. Cancel and reserve the move again.');
    }

    private function releaseReservation(int $companyId, int $productId, int $locationId, ?int $lotId, string $quantity): void
    {
        $quantId = $this->lockedQuant($companyId, $productId, $locationId, $lotId);
        $update = $this->pdo->prepare('UPDATE stock_quants SET reserved_quantity=reserved_quantity-:quantity WHERE id=:id AND reserved_quantity>=CAST(:check_quantity AS DECIMAL(20,6))');
        $update->execute(['quantity' => $quantity, 'id' => $quantId, 'check_quantity' => $quantity]);
        if ($update->rowCount() !== 1) throw new \RuntimeException('Stock reservation could not be safely released.');
    }

    private function increaseQuant(int $companyId, int $productId, int $locationId, ?int $lotId, string $quantity): void
    {
        $lotKey = $lotId === null ? 'NONE' : 'LOT' . $lotId;
        $insert = $this->pdo->prepare(
            'INSERT INTO stock_quants (company_id, product_id, location_id, lot_id, lot_key, quantity, reserved_quantity) ' .
            'VALUES (:company_id, :product_id, :location_id, :lot_id, :lot_key, :quantity, 0) ' .
            'ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity)'
        );
        $insert->execute(['company_id' => $companyId, 'product_id' => $productId, 'location_id' => $locationId, 'lot_id' => $lotId, 'lot_key' => $lotKey, 'quantity' => $quantity]);
    }

    private function lockedQuant(int $companyId, int $productId, int $locationId, ?int $lotId): int
    {
        $lotKey = $lotId === null ? 'NONE' : 'LOT' . $lotId;
        $insert = $this->pdo->prepare(
            'INSERT INTO stock_quants (company_id, product_id, location_id, lot_id, lot_key, quantity, reserved_quantity) ' .
            'VALUES (:company_id, :product_id, :location_id, :lot_id, :lot_key, 0, 0) ' .
            'ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
        );
        $insert->execute(['company_id' => $companyId, 'product_id' => $productId, 'location_id' => $locationId, 'lot_id' => $lotId, 'lot_key' => $lotKey]);
        $query = $this->pdo->prepare('SELECT id FROM stock_quants WHERE company_id=:company_id AND product_id=:product_id AND location_id=:location_id AND lot_key=:lot_key FOR UPDATE');
        $query->execute(['company_id' => $companyId, 'product_id' => $productId, 'location_id' => $locationId, 'lot_key' => $lotKey]);
        $id = $query->fetchColumn();
        if ($id === false) throw new \RuntimeException('Stock quantity row could not be locked.');
        return (int)$id;
    }

    private function quantityInProductUom(string $quantity, int $moveUomId, int $productUomId, int $companyId): string
    {
        $moveUom = $this->uom($moveUomId, $companyId);
        $productUom = $this->uom($productUomId, $companyId);
        if ((int)$moveUom['category_id'] !== (int)$productUom['category_id']) throw new InvalidArgumentException('Unit conversion category mismatch.');
        $convert = $this->pdo->prepare('SELECT CAST((CAST(:quantity AS DECIMAL(20,6))*CAST(:move_factor AS DECIMAL(20,10))/CAST(:product_factor AS DECIMAL(20,10))) AS DECIMAL(20,6))');
        $convert->execute(['quantity' => $quantity, 'move_factor' => $moveUom['factor_to_reference'], 'product_factor' => $productUom['factor_to_reference']]);
        return (string)$convert->fetchColumn();
    }

    private function decimalEqualsOne(string $quantity): bool
    {
        $query = $this->pdo->prepare('SELECT CAST(:quantity AS DECIMAL(20,6)) = CAST(1 AS DECIMAL(20,6))');
        $query->execute(['quantity' => $quantity]);
        return (bool)$query->fetchColumn();
    }

    private function addDecimal(string $left, string $right): string
    {
        $query = $this->pdo->prepare('SELECT CAST(CAST(:left_value AS DECIMAL(20,6))+CAST(:right_value AS DECIMAL(20,6)) AS DECIMAL(20,6))');
        $query->execute(['left_value' => $left, 'right_value' => $right]);
        return (string)$query->fetchColumn();
    }

    private function subtractDecimal(string $left, string $right): string
    {
        $query = $this->pdo->prepare('SELECT CAST(CAST(:left_value AS DECIMAL(20,6))-CAST(:right_value AS DECIMAL(20,6)) AS DECIMAL(20,6))');
        $query->execute(['left_value' => $left, 'right_value' => $right]);
        return (string)$query->fetchColumn();
    }

    private function compareDecimal(string $left, string $right): int
    {
        $query = $this->pdo->prepare('SELECT CASE WHEN CAST(:left_less AS DECIMAL(20,6))<CAST(:right_less AS DECIMAL(20,6)) THEN -1 WHEN CAST(:left_more AS DECIMAL(20,6))>CAST(:right_more AS DECIMAL(20,6)) THEN 1 ELSE 0 END');
        $query->execute(['left_less' => $left, 'right_less' => $right, 'left_more' => $left, 'right_more' => $right]);
        return (int)$query->fetchColumn();
    }

    private function isPositive(string $quantity): bool
    {
        $query = $this->pdo->prepare('SELECT CAST(:quantity AS DECIMAL(20,6))>0');
        $query->execute(['quantity' => $quantity]);
        return (bool)$query->fetchColumn();
    }

    private function dateTime(mixed $value): ?string
    {
        $text = trim((string)$value);
        if ($text === '') return null;
        try {
            return (new DateTimeImmutable($text))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            throw new InvalidArgumentException('Scheduled date and time is invalid.');
        }
    }

    private function date(mixed $value): ?string
    {
        $text = trim((string)$value);
        if ($text === '') return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if (!$date || $date->format('Y-m-d') !== $text) throw new InvalidArgumentException('Expiration date must use YYYY-MM-DD.');
        return $text;
    }

    private function quantity(mixed $value, string $label): string
    {
        $text = trim((string)$value);
        if (preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/D', $text) !== 1 || !$this->isPositive($text)) {
            throw new InvalidArgumentException($label . ' must be a positive decimal with up to 6 fractional digits.');
        }
        return $text;
    }

    private function nonNegativeQuantity(mixed $value, string $label): string
    {
        $text = trim((string)$value);
        if (preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/D', $text) !== 1) {
            throw new InvalidArgumentException($label . ' must be a nonnegative decimal with up to 6 fractional digits.');
        }
        return $text;
    }

    private function positiveId(mixed $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new InvalidArgumentException($label . ' must be a positive integer.');
        return $id;
    }

    private function isStockLocation(string $usage): bool { return in_array($usage, self::STOCK_USAGES, true); }

    private function resolveCompany(UserContext $user, Authorization $authorization, mixed $raw): int
    {
        $companyId = $raw === null || $raw === '' ? null : $this->positiveId($raw, 'Company');
        $companyId ??= $user->defaultCompanyId ?? (count($user->companyIds) === 1 ? $user->companyIds[0] : null);
        if ($companyId === null) throw new InvalidArgumentException('Choose an allowed company.');
        if (!$authorization->canAccessCompany($companyId)) throw new InvalidArgumentException('The selected company is not available to your account.');
        return $companyId;
    }

    private function auditValues(?array $move): array
    {
        if (!$move) return [];
        return array_intersect_key($move, array_flip([
            'id', 'company_id', 'reference', 'product_id', 'uom_id', 'source_location_id', 'destination_location_id',
            'demand_quantity', 'done_quantity', 'valuation_value', 'state', 'origin_model', 'origin_record_id', 'origin_reference', 'scheduled_at',
        ]));
    }

    private function audit(int $companyId, int $actorId, int $recordId, string $event, ?array $before, ?array $after, string $remoteAddress): void
    {
        $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        $insert = $this->pdo->prepare(
            'INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) ' .
            'VALUES (:company_id,:user_id,:event_key,\'inventory.stock_move\',:record_id,:old_values,:new_values,:ip_address)'
        );
        $insert->bindValue(':company_id', $companyId, PDO::PARAM_INT);
        $insert->bindValue(':user_id', $actorId, PDO::PARAM_INT);
        $insert->bindValue(':event_key', $event);
        $insert->bindValue(':record_id', $recordId, PDO::PARAM_INT);
        $insert->bindValue(':old_values', $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), $before === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $insert->bindValue(':new_values', $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR), $after === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $insert->bindValue(':ip_address', $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $insert->execute();
    }
}
