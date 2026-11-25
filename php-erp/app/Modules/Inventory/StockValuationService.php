<?php
declare(strict_types=1);

namespace Erp\Modules\Inventory;

use InvalidArgumentException;
use PDO;

/** Maintains the physical inventory valuation ledger during stock validation. */
final class StockValuationService
{
    private const STOCK_USAGES = ['internal', 'transit'];

    public function __construct(private readonly PDO $pdo) {}

    /** @return array{value_delta:string,unit_cost:string,costing_method:string}|null */
    public function applyMoveLine(array $move, array $line, string $sourceUsage, string $destinationUsage, string $baseQuantity, int $actorId, ?string $unitCostOverride = null): ?array
    {
        $sourceIsStock = in_array($sourceUsage, self::STOCK_USAGES, true);
        $destinationIsStock = in_array($destinationUsage, self::STOCK_USAGES, true);
        if (!empty($line['return_of_move_line_id'])) {
            if (!$sourceIsStock && $destinationIsStock) return $this->applyCustomerReturn($move,$line,$baseQuantity,$actorId);
            if ($sourceIsStock && $destinationUsage === 'vendor') $this->assertSupplierReturn($move,$line,$baseQuantity);
            else throw new InvalidArgumentException('A linked stock return must move between the original external location and internal/transit stock.');
        }
        if ($sourceIsStock === $destinationIsStock) return null;

        $companyId = (int)$move['company_id'];
        $productId = (int)$move['product_id'];
        $productQuery = $this->pdo->prepare(
            'SELECT p.cost_price,COALESCE(o.costing_method,s.costing_method,c.costing_method) AS costing_method ' .
            'FROM products p JOIN product_categories c ON c.id=p.category_id ' .
            'LEFT JOIN inventory_accounting_settings s ON s.company_id=p.company_id ' .
            'LEFT JOIN product_category_accounting_overrides o ON o.company_id=p.company_id AND o.category_id=p.category_id ' .
            'WHERE p.id=:product AND p.company_id=:company FOR UPDATE'
        );
        $productQuery->execute(['product' => $productId, 'company' => $companyId]);
        $product = $productQuery->fetch();
        if (!$product) throw new InvalidArgumentException('Product category costing settings are unavailable for this stock move.');
        $method = (string)$product['costing_method'];
        if (!in_array($method, ['standard', 'fifo', 'average'], true)) throw new InvalidArgumentException('Product category must use Standard, FIFO, or Average cost before valuing stock.');

        $balance = $this->lockBalance($companyId, $productId);
        if ($destinationIsStock) {
            $unitCost = $unitCostOverride ?? ($method === 'standard' ? (string)$product['cost_price'] : $this->purchaseUnitCost($move, $productId) ?? (string)$product['cost_price']);
            $value = $this->multiply($baseQuantity, $unitCost);
            $newQuantity = $this->add((string)$balance['quantity'], $baseQuantity);
            $newValue = $this->add((string)$balance['value'], $value);
            $this->writeBalance($companyId, $productId, $newQuantity, $newValue);
            $this->insertLayer($move, $line, $method, $baseQuantity, $unitCost, $value, $method === 'fifo' ? $baseQuantity : '0.000000', $method === 'fifo' ? $value : '0.000000', $actorId);
            if (in_array($method, ['fifo', 'average'], true)) $this->updateDisplayCost($productId, $newQuantity, $newValue);
            return ['value_delta' => $value, 'unit_cost' => $unitCost, 'costing_method' => $method];
        }

        $currentQuantity = (string)$balance['quantity'];
        $currentValue = (string)$balance['value'];
        if ($this->compare($baseQuantity, $currentQuantity) > 0) throw new InvalidArgumentException('Stock has no matching valuation balance for this outgoing quantity. Review the opening stock valuation before validating this move.');
        if ($method === 'fifo') {
            $value = $this->consumeFifo($companyId, $productId, $baseQuantity);
            $unitCost = $this->divide($value, $baseQuantity);
        } else {
            $unitCost = $method === 'standard' ? (string)$product['cost_price'] : $this->divide($currentValue, $currentQuantity);
            $value = $this->multiply($baseQuantity, $unitCost);
        }
        if ($this->compare($value, $currentValue) > 0) throw new InvalidArgumentException('The outgoing move value exceeds the available inventory value. Revalue stock before validating this move.');
        $newQuantity = $this->subtract($currentQuantity, $baseQuantity);
        $newValue = $this->subtract($currentValue, $value);
        $this->writeBalance($companyId, $productId, $newQuantity, $newValue);
        $negativeQuantity = $this->negate($baseQuantity);
        $negativeValue = $this->negate($value);
        $this->insertLayer($move, $line, $method, $negativeQuantity, $unitCost, $negativeValue, '0.000000', '0.000000', $actorId);
        if (in_array($method, ['fifo', 'average'], true)) $this->updateDisplayCost($productId, $newQuantity, $newValue);
        return ['value_delta' => $negativeValue, 'unit_cost' => $unitCost, 'costing_method' => $method];
    }

    private function applyCustomerReturn(array $move,array $line,string $baseQuantity,int $actorId):array
    {
        $companyId=(int)$move['company_id'];$productId=(int)$move['product_id'];$sourceLineId=(int)$line['return_of_move_line_id'];
        $source=$this->pdo->prepare('SELECT quantity_delta,unit_cost,value_delta,costing_method FROM inventory_valuation_layers WHERE company_id=:company AND product_id=:product AND stock_move_line_id=:line AND quantity_delta<0 FOR UPDATE');$source->execute(['company'=>$companyId,'product'=>$productId,'line'=>$sourceLineId]);$original=$source->fetch();if(!$original)throw new InvalidArgumentException('The original delivery has no valuation layer for this customer return line.');
        $returned=$this->pdo->prepare("SELECT CAST(COALESCE(SUM(v.quantity_delta),0) AS DECIMAL(20,6)) AS quantity,CAST(COALESCE(SUM(v.value_delta),0) AS DECIMAL(20,6)) AS value FROM inventory_valuation_layers v JOIN stock_move_lines rl ON rl.id=v.stock_move_line_id JOIN stock_moves rm ON rm.id=rl.move_id WHERE rl.return_of_move_line_id=:line AND rm.state='done'");$returned->execute(['line'=>$sourceLineId]);$prior=$returned->fetch()?:['quantity'=>'0.000000','value'=>'0.000000'];
        $originalQuantity=$this->negate((string)$original['quantity_delta']);$originalValue=$this->negate((string)$original['value_delta']);$availableQuantity=$this->subtract($originalQuantity,(string)$prior['quantity']);$availableValue=$this->subtract($originalValue,(string)$prior['value']);if($this->compare($baseQuantity,'0')<=0||$this->compare($baseQuantity,$availableQuantity)>0)throw new InvalidArgumentException('Returned quantity exceeds the quantity from the original delivery that has not already been returned.');
        $value=$this->compare($baseQuantity,$availableQuantity)===0?$availableValue:$this->multiply($baseQuantity,(string)$original['unit_cost']);if($this->compare($value,$availableValue)>0)throw new InvalidArgumentException('Returned valuation exceeds the original delivery valuation.');$unitCost=$this->divide($value,$baseQuantity);$balance=$this->lockBalance($companyId,$productId);$newQuantity=$this->add((string)$balance['quantity'],$baseQuantity);$newValue=$this->add((string)$balance['value'],$value);$this->writeBalance($companyId,$productId,$newQuantity,$newValue);$method=(string)$original['costing_method'];$this->insertLayer($move,$line,$method,$baseQuantity,$unitCost,$value,$method==='fifo'?$baseQuantity:'0.000000',$method==='fifo'?$value:'0.000000',$actorId);if(in_array($method,['fifo','average'],true))$this->updateDisplayCost($productId,$newQuantity,$newValue);
        return ['value_delta'=>$value,'unit_cost'=>$unitCost,'costing_method'=>$method];
    }

    private function assertSupplierReturn(array $move,array $line,string $baseQuantity):void
    {
        $receiptId=(int)($move['return_of_move_id']??0);$sourceLineId=(int)($line['return_of_move_line_id']??0);
        $query=$this->pdo->prepare("SELECT source_line.quantity_done,source_move.uom_id,source_move.source_location_id,source_move.destination_location_id,receipt_uom.factor_to_reference AS receipt_factor,product_uom.factor_to_reference AS product_factor FROM stock_moves source_move JOIN stock_move_lines source_line ON source_line.move_id=source_move.id JOIN products product ON product.id=source_move.product_id JOIN units_of_measure receipt_uom ON receipt_uom.id=source_move.uom_id JOIN units_of_measure product_uom ON product_uom.id=product.uom_id JOIN stock_locations source ON source.id=source_move.source_location_id JOIN stock_locations destination ON destination.id=source_move.destination_location_id WHERE source_move.id=:receipt AND source_move.company_id=:company AND source_move.origin_model='purchase_order' AND source_move.source_line_model='purchase_order_line' AND source_move.state='done' AND source_line.id=:source_line AND source_move.product_id=:product AND source.location_usage='vendor' AND destination.location_usage IN ('internal','transit')");
        $query->execute(['receipt'=>$receiptId,'company'=>$move['company_id'],'source_line'=>$sourceLineId,'product'=>$move['product_id']]);$receipt=$query->fetch();
        if(!$receipt||(int)$move['source_location_id']!==(int)$receipt['destination_location_id']||(int)$move['destination_location_id']!==(int)$receipt['source_location_id'])throw new InvalidArgumentException('A supplier return must link to its validated Purchase receipt and return goods to the same supplier location.');
        $returned=$this->pdo->prepare("SELECT CAST(COALESCE(SUM(CASE WHEN rm.state='done' THEN COALESCE(rl.quantity_done,0) ELSE rl.quantity END),0) AS DECIMAL(20,6)) FROM stock_move_lines rl JOIN stock_moves rm ON rm.id=rl.move_id WHERE rl.return_of_move_line_id=:source_line AND rm.return_of_move_id=:receipt AND rm.company_id=:company AND rm.state IN ('confirmed','done') AND rm.id<>:current");$returned->execute(['source_line'=>$sourceLineId,'receipt'=>$receiptId,'company'=>$move['company_id'],'current'=>$move['id']]);
        $available=$this->subtract((string)$receipt['quantity_done'],(string)$returned->fetchColumn());if($this->compare($available,'0')<0)$available='0.000000';
        $availableProductUom=$this->calculate('CAST(ROUND(CAST(:quantity AS DECIMAL(20,6))*CAST(:receipt_factor AS DECIMAL(20,10))/CAST(:product_factor AS DECIMAL(20,10)),6) AS DECIMAL(20,6))',['quantity'=>$available,'receipt_factor'=>$receipt['receipt_factor'],'product_factor'=>$receipt['product_factor']]);
        if($this->compare($baseQuantity,$availableProductUom)>0)throw new InvalidArgumentException('Supplier return quantity exceeds the receipt-line quantity not already returned or reserved.');
    }

    private function lockBalance(int $companyId, int $productId): array
    {
        $this->pdo->prepare('INSERT INTO inventory_valuation_balances (company_id,product_id,quantity,value) VALUES (:company,:product,0,0) ON DUPLICATE KEY UPDATE product_id=VALUES(product_id)')->execute(['company' => $companyId, 'product' => $productId]);
        $query = $this->pdo->prepare('SELECT quantity,value FROM inventory_valuation_balances WHERE company_id=:company AND product_id=:product FOR UPDATE');
        $query->execute(['company' => $companyId, 'product' => $productId]);
        return $query->fetch() ?: throw new \RuntimeException('Inventory valuation balance could not be locked.');
    }

    private function writeBalance(int $companyId, int $productId, string $quantity, string $value): void
    {
        $this->pdo->prepare('UPDATE inventory_valuation_balances SET quantity=:quantity,value=:value WHERE company_id=:company AND product_id=:product')->execute(['quantity' => $quantity, 'value' => $value, 'company' => $companyId, 'product' => $productId]);
    }

    private function purchaseUnitCost(array $move, int $productId): ?string
    {
        if (($move['source_line_model'] ?? null) !== 'purchase_order_line' || empty($move['source_line_id'])) return null;
        $query = $this->pdo->prepare('SELECT pol.subtotal,pol.quantity,pu.factor_to_reference AS purchase_factor,pu_product.factor_to_reference AS product_factor FROM purchase_order_lines pol JOIN purchase_orders po ON po.id=pol.order_id AND po.company_id=:company JOIN products p ON p.id=pol.product_id JOIN units_of_measure pu ON pu.id=pol.uom_id JOIN units_of_measure pu_product ON pu_product.id=p.uom_id WHERE pol.id=:line AND pol.product_id=:product AND pol.quantity>0');
        $query->execute(['company' => $move['company_id'], 'line' => $move['source_line_id'], 'product' => $productId]);
        $row = $query->fetch();
        if (!$row) throw new InvalidArgumentException('Purchase receipt valuation could not find its source Purchase Order line.');
        return $this->calculate('CAST(ROUND(CAST(:subtotal AS DECIMAL(20,6))/CAST(:quantity AS DECIMAL(20,6))*CAST(:product_factor AS DECIMAL(20,10))/CAST(:purchase_factor AS DECIMAL(20,10)),6) AS DECIMAL(20,6))', ['subtotal' => $row['subtotal'], 'quantity' => $row['quantity'], 'product_factor' => $row['product_factor'], 'purchase_factor' => $row['purchase_factor']]);
    }

    private function consumeFifo(int $companyId, int $productId, string $quantity): string
    {
        $query = $this->pdo->prepare('SELECT id,remaining_quantity,remaining_value FROM inventory_valuation_layers WHERE company_id=:company AND product_id=:product AND remaining_quantity>0 ORDER BY valuation_date,id FOR UPDATE');
        $query->execute(['company' => $companyId, 'product' => $productId]);
        $needed = $quantity;
        $value = '0.000000';
        foreach ($query->fetchAll() as $layer) {
            if ($this->compare($needed, '0') <= 0) break;
            $available = (string)$layer['remaining_quantity'];
            $take = $this->compare($available, $needed) <= 0 ? $available : $needed;
            $takeValue = $this->compare($take, $available) === 0
                ? (string)$layer['remaining_value']
                : $this->calculate('CAST(ROUND(CAST(:value AS DECIMAL(20,6))*CAST(:take AS DECIMAL(20,6))/CAST(:quantity AS DECIMAL(20,6)),6) AS DECIMAL(20,6))', ['value' => $layer['remaining_value'], 'take' => $take, 'quantity' => $available]);
            $this->pdo->prepare('UPDATE inventory_valuation_layers SET remaining_quantity=:quantity,remaining_value=:value WHERE id=:id')->execute(['quantity' => $this->subtract($available, $take), 'value' => $this->subtract((string)$layer['remaining_value'], $takeValue), 'id' => $layer['id']]);
            $needed = $this->subtract($needed, $take);
            $value = $this->add($value, $takeValue);
        }
        if ($this->compare($needed, '0') > 0) throw new InvalidArgumentException('FIFO valuation layers do not cover the available stock. Revalue opening stock before validating this move.');
        return $value;
    }

    private function insertLayer(array $move, array $line, string $method, string $quantity, string $unitCost, string $value, string $remainingQuantity, string $remainingValue, int $actorId): void
    {
        $reference = trim((string)($move['origin_reference'] ?? '')) ?: trim((string)($move['reference'] ?? '')) ?: 'Stock move '.$move['id'];
        $query = $this->pdo->prepare('INSERT INTO inventory_valuation_layers (company_id,product_id,stock_move_id,stock_move_line_id,source_model,source_record_id,reference,costing_method,quantity_delta,unit_cost,value_delta,remaining_quantity,remaining_value,created_by) VALUES (:company,:product,:move,:line,:source_model,:source_id,:reference,:method,:quantity,:unit_cost,:value,:remaining_quantity,:remaining_value,:actor)');
        $sourceModel = ($move['source_line_model'] ?? null) ?: ($move['origin_model'] ?? null);
        $sourceRecordId = ($move['source_line_id'] ?? null) ?: ($move['origin_record_id'] ?? null);
        $query->execute(['company' => $move['company_id'], 'product' => $move['product_id'], 'move' => $move['id'], 'line' => $line['id'], 'source_model' => $sourceModel, 'source_id' => $sourceRecordId, 'reference' => mb_substr($reference, 0, 120), 'method' => $method, 'quantity' => $quantity, 'unit_cost' => $unitCost, 'value' => $value, 'remaining_quantity' => $remainingQuantity, 'remaining_value' => $remainingValue, 'actor' => $actorId]);
    }

    private function updateDisplayCost(int $productId, string $quantity, string $value): void
    {
        if ($this->compare($quantity, '0') <= 0) return;
        $cost = $this->divide($value, $quantity);
        $this->pdo->prepare('UPDATE products SET cost_price=:cost WHERE id=:product')->execute(['cost' => $cost, 'product' => $productId]);
    }

    private function calculate(string $expression, array $parameters): string
    {
        $query = $this->pdo->prepare('SELECT '.$expression);
        $query->execute($parameters);
        return (string)$query->fetchColumn();
    }

    private function add(string $left, string $right): string { return $this->calculate('CAST(CAST(:left AS DECIMAL(20,6))+CAST(:right AS DECIMAL(20,6)) AS DECIMAL(20,6))', ['left' => $left, 'right' => $right]); }
    private function subtract(string $left, string $right): string { return $this->calculate('CAST(CAST(:left AS DECIMAL(20,6))-CAST(:right AS DECIMAL(20,6)) AS DECIMAL(20,6))', ['left' => $left, 'right' => $right]); }
    private function multiply(string $left, string $right): string { return $this->calculate('CAST(ROUND(CAST(:left AS DECIMAL(20,6))*CAST(:right AS DECIMAL(20,6)),6) AS DECIMAL(20,6))', ['left' => $left, 'right' => $right]); }
    private function divide(string $left, string $right): string { return $this->calculate('CASE WHEN CAST(:right_zero AS DECIMAL(20,6))=0 THEN 0 ELSE CAST(ROUND(CAST(:left AS DECIMAL(20,6))/CAST(:right_value AS DECIMAL(20,6)),6) AS DECIMAL(20,6)) END', ['left' => $left, 'right_zero' => $right, 'right_value' => $right]); }
    private function negate(string $value): string { return $this->calculate('CAST(0-CAST(:value AS DECIMAL(20,6)) AS DECIMAL(20,6))', ['value' => $value]); }
    private function compare(string $left, string $right): int { return (int)$this->calculate('CASE WHEN CAST(:left_less AS DECIMAL(20,6))<CAST(:right_less AS DECIMAL(20,6)) THEN -1 WHEN CAST(:left_more AS DECIMAL(20,6))>CAST(:right_more AS DECIMAL(20,6)) THEN 1 ELSE 0 END', ['left_less' => $left, 'right_less' => $right, 'left_more' => $left, 'right_more' => $right]); }
}
