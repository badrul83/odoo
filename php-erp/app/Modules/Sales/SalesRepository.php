<?php
declare(strict_types=1);

namespace Erp\Modules\Sales;

use Erp\Core\Security\Authorization;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDO;
use Throwable;

final class SalesRepository
{
    private const ORDER_FIELDS = [
        'company_id', 'partner_contact_id', 'contract_id', 'invoice_contact_id', 'delivery_contact_id', 'crm_lead_id', 'sale_order_template_id',
        'salesperson_user_id', 'pricelist_id', 'payment_terms_id', 'warehouse_id', 'currency_code',
        'order_date', 'validity_date', 'client_order_ref', 'note', 'lines',
    ];

    public function __construct(private readonly PDO $pdo) {}

    public function options(UserContext $user, Authorization $authorization, mixed $rawCompanyId): array
    {
        $companyId = $this->resolveCompany($user, $authorization, $rawCompanyId);
        $contacts = $this->pdo->prepare(
            'SELECT id, name, contact_type, email, phone, customer_pricelist_id, customer_payment_terms_id ' .
            'FROM contacts WHERE active=1 AND (company_id IS NULL OR company_id=:company_id) ORDER BY name,id LIMIT 1000'
        );
        $contacts->execute(['company_id' => $companyId]);
        $products = $this->pdo->prepare(
            'SELECT p.id,p.name,p.internal_reference,p.product_type,p.invoice_policy,p.category_id,p.uom_id,p.list_price,u.name AS uom_name,u.abbreviation ' .
            'FROM products p JOIN units_of_measure u ON u.id=p.uom_id ' .
            'WHERE p.company_id=:company_id AND p.active=1 AND p.sale_ok=1 ORDER BY p.name,p.id LIMIT 2000'
        );
        $products->execute(['company_id' => $companyId]);
        $units = $this->pdo->prepare(
            'SELECT u.id,u.name,u.abbreviation,u.category_id,u.factor_to_reference,u.rounding FROM units_of_measure u ' .
            'JOIN uom_categories c ON c.id=u.category_id WHERE u.active=1 AND c.active=1 ' .
            'AND (u.company_id IS NULL OR u.company_id=:unit_company_id) AND (c.company_id IS NULL OR c.company_id=:category_company_id) ORDER BY c.name,u.name,u.id'
        );
        $units->execute(['unit_company_id'=>$companyId,'category_company_id'=>$companyId]);
        $companies = $this->companyOptions($user);
        $params = ['company_id' => $companyId];
        if ($user->superuser) {
            $people = $this->pdo->query("SELECT u.id,c.name FROM users u JOIN contacts c ON c.id=u.contact_id WHERE u.status IN ('confirmed','active') ORDER BY c.name,u.id")->fetchAll();
        } else {
            $peopleQuery = $this->pdo->prepare("SELECT u.id,c.name FROM users u JOIN contacts c ON c.id=u.contact_id JOIN user_company_access a ON a.user_id=u.id AND a.company_id=:company_id WHERE u.status IN ('confirmed','active') ORDER BY c.name,u.id");
            $peopleQuery->execute($params);
            $people = $peopleQuery->fetchAll();
        }
        $crm = $this->pdo->prepare(
            "SELECT id,name,partner_contact_id,expected_revenue,currency_code FROM crm_leads " .
            "WHERE company_id=:company_id AND record_type='opportunity' AND state IN ('open','won') ORDER BY updated_at DESC,id DESC LIMIT 1000"
        );
        $crm->execute($params);
        $company = $this->pdo->prepare('SELECT currency_code FROM companies WHERE id=:company_id');
        $company->execute($params);
        $currencies = $this->pdo->query('SELECT currency_code,name,symbol,decimal_places FROM currencies WHERE active=1 ORDER BY name')->fetchAll();
        $pricelists = $this->pdo->prepare('SELECT id,name,currency_code FROM price_lists WHERE active=1 AND (company_id IS NULL OR company_id=:company_id) ORDER BY name,id');
        $pricelists->execute($params);
        $terms = $this->pdo->prepare('SELECT id,name FROM payment_terms WHERE active=1 AND (company_id IS NULL OR company_id=:company_id) ORDER BY name,id');
        $terms->execute($params);
        $templates = $this->pdo->prepare('SELECT id,name,validity_days,pricelist_id,terms FROM sales_order_templates WHERE active=1 AND company_id=:company_id ORDER BY name,id');
        $templates->execute($params);
        $warehouses = $this->pdo->prepare('SELECT id,name,code FROM warehouses WHERE active=1 AND company_id=:company_id ORDER BY name,id');
        $warehouses->execute($params);
        $taxes = $this->pdo->prepare('SELECT id,name,amount,price_included,account_id FROM sales_taxes WHERE active=1 AND (company_id IS NULL OR company_id=:company_id) ORDER BY name,id');
        $taxes->execute($params);
        $accounts = $this->pdo->prepare('SELECT id,code,name,account_type FROM accounting_accounts WHERE company_id=:company_id AND active=1 ORDER BY code,id');
        $accounts->execute($params);
        return [
            'company_id' => $companyId, 'companies' => $companies, 'contacts' => $contacts->fetchAll(),
            'products' => $products->fetchAll(), 'units' => $units->fetchAll(), 'salespeople' => $people, 'opportunities' => $crm->fetchAll(),
            'pricelists' => $pricelists->fetchAll(), 'payment_terms' => $terms->fetchAll(), 'quotation_templates' => $templates->fetchAll(),
            'warehouses' => $warehouses->fetchAll(), 'taxes' => $taxes->fetchAll(), 'accounts' => $accounts->fetchAll(), 'currencies' => $currencies,
            'contracts' => $authorization->allowsForCompany('contracts.read',$companyId) ? (new \Erp\Modules\Contracts\ContractRepository($this->pdo))->orderOptions($companyId,'customer') : [],
            'currency_code' => (string)$company->fetchColumn(),
        ];
    }

    public function search(UserContext $user, Authorization $authorization, array $filters): array
    {
        $companyId = $this->resolveCompany($user, $authorization, $filters['company_id'] ?? null);
        $where = ['o.company_id=:company_id'];
        $params = ['company_id' => $companyId];
        $state = (string)($filters['state'] ?? 'all');
        if ($state !== 'all') {
            if (!in_array($state, ['draft','sent','sale','cancelled'], true)) throw new InvalidArgumentException('Select a valid sales document state.');
            $where[] = 'o.state=:state'; $params['state'] = $state;
        }
        foreach (['partner_contact_id' => 'o.partner_contact_id', 'crm_lead_id' => 'o.crm_lead_id'] as $key => $column) {
            if (($filters[$key] ?? '') !== '') { $where[] = $column . '=:' . $key; $params[$key] = $this->positiveId($filters[$key], ucfirst(str_replace('_',' ',$key))); }
        }
        $queryText = trim((string)($filters['q'] ?? ''));
        if ($queryText !== '') {
            $where[] = '(o.name LIKE :q OR c.name LIKE :customer_q OR o.client_order_ref LIKE :reference_q OR crm.name LIKE :crm_q)';
            foreach (['q','customer_q','reference_q','crm_q'] as $key) $params[$key] = '%' . $queryText . '%';
        }
        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM sales_orders o JOIN contacts c ON c.id=o.partner_contact_id LEFT JOIN crm_leads crm ON crm.id=o.crm_lead_id WHERE ' . $whereSql);
        $count->execute($params); $total = (int)$count->fetchColumn();
        $limit = max(1,min(100,(int)($filters['limit'] ?? 50))); $offset = max(0,min(1000000,(int)($filters['offset'] ?? 0)));
        $query = $this->pdo->prepare(
            'SELECT o.id,o.company_id,o.name,o.partner_contact_id,c.name AS customer_name,o.crm_lead_id,crm.name AS opportunity_name,' .
            'o.salesperson_user_id,person.name AS salesperson_name,o.contract_id,contract.name AS contract_name,o.order_date,o.validity_date,o.currency_code,o.state,' .
            'o.untaxed_amount,o.tax_amount,o.total_amount,o.created_at,' .
            '(SELECT COUNT(*) FROM stock_moves m WHERE m.origin_model=\'sale_order\' AND m.origin_record_id=o.id) AS delivery_move_count,' .
            '(SELECT COUNT(*) FROM stock_moves m WHERE m.origin_model=\'sale_order\' AND m.origin_record_id=o.id AND m.state=\'done\') AS delivered_move_count ' .
            'FROM sales_orders o JOIN contacts c ON c.id=o.partner_contact_id LEFT JOIN crm_leads crm ON crm.id=o.crm_lead_id ' .
            'LEFT JOIN users su ON su.id=o.salesperson_user_id LEFT JOIN contacts person ON person.id=su.contact_id LEFT JOIN contracts contract ON contract.id=o.contract_id AND contract.company_id=o.company_id ' .
            'WHERE ' . $whereSql . ' ORDER BY o.order_date DESC,o.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) $query->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $query->bindValue(':limit',$limit,PDO::PARAM_INT); $query->bindValue(':offset',$offset,PDO::PARAM_INT); $query->execute();
        return ['records'=>$query->fetchAll(),'total'=>$total,'limit'=>$limit,'offset'=>$offset];
    }

    public function find(int $id, UserContext $user, Authorization $authorization): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT o.*,c.name AS customer_name,c.email AS customer_email,ic.name AS invoice_contact_name,dc.name AS delivery_contact_name,' .
            'crm.name AS opportunity_name,person.name AS salesperson_name,p.name AS pricelist_name,pt.name AS payment_terms_name,w.name AS warehouse_name,contract.name AS contract_name ' .
            'FROM sales_orders o JOIN contacts c ON c.id=o.partner_contact_id LEFT JOIN contacts ic ON ic.id=o.invoice_contact_id ' .
            'LEFT JOIN contacts dc ON dc.id=o.delivery_contact_id LEFT JOIN crm_leads crm ON crm.id=o.crm_lead_id ' .
            'LEFT JOIN users su ON su.id=o.salesperson_user_id LEFT JOIN contacts person ON person.id=su.contact_id ' .
            'LEFT JOIN price_lists p ON p.id=o.pricelist_id LEFT JOIN payment_terms pt ON pt.id=o.payment_terms_id ' .
            'LEFT JOIN warehouses w ON w.id=o.warehouse_id LEFT JOIN contracts contract ON contract.id=o.contract_id AND contract.company_id=o.company_id WHERE o.id=:id LIMIT 1'
        );
        $query->execute(['id'=>$id]); $order=$query->fetch();
        if (!$order || !$authorization->canAccessCompany((int)$order['company_id'])) return null;
        $lines=$this->pdo->prepare('SELECT l.*,p.name AS product_name,p.internal_reference,p.product_type,u.name AS uom_name,u.abbreviation FROM sales_order_lines l JOIN products p ON p.id=l.product_id JOIN units_of_measure u ON u.id=l.uom_id WHERE l.order_id=:id ORDER BY l.sequence,l.id');
        $lines->execute(['id'=>$id]); $order['lines']=$lines->fetchAll();
        $moves=$this->pdo->prepare('SELECT m.id,m.reference,m.origin_reference,m.product_id,p.name AS product_name,m.demand_quantity,m.done_quantity,m.state,m.scheduled_at,m.return_of_move_id,m.return_reason,parent.reference AS return_of_reference FROM stock_moves m JOIN products p ON p.id=m.product_id LEFT JOIN stock_moves parent ON parent.id=m.return_of_move_id AND parent.company_id=m.company_id WHERE m.origin_model=\'sale_order\' AND m.origin_record_id=:id ORDER BY m.id');
        $moves->execute(['id'=>$id]); $order['deliveries']=$moves->fetchAll();
        $order['approval']=(new \Erp\Modules\Settings\ApprovalRepository($this->pdo))->approvalForDocument('sales_order',$id,$user,$authorization);
        return $order;
    }

    public function saveDraft(?int $id, array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown=array_diff(array_keys($input),self::ORDER_FIELDS);
        if ($unknown) throw new InvalidArgumentException('Unsupported sales order fields: ' . implode(', ',$unknown));
        if (!isset($input['lines']) || !is_array($input['lines']) || !$input['lines']) throw new InvalidArgumentException('Add at least one product line to the quotation.');
        $existing=$id===null?null:$this->find($id,$user,$authorization);
        if ($id!==null && !$existing) throw new InvalidArgumentException('Sales order was not found or is outside the allowed companies.');
        if ($existing && $existing['state']!=='draft') throw new InvalidArgumentException('Only draft quotations can be edited.');
        if ($existing && ($existing['approval']['status']??null)==='pending') throw new InvalidArgumentException('This quotation is locked while its approval layers are pending.');
        $companyId=$existing?(int)$existing['company_id']:$this->positiveId($input['company_id']??$user->defaultCompanyId,'Company');
        if (!$authorization->canAccessCompany($companyId)) throw new InvalidArgumentException('The selected company is not available to this account.');
        if ($existing && isset($input['company_id']) && (int)$input['company_id']!==$companyId) throw new InvalidArgumentException('A sales order cannot be moved to another company.');
        $customerId=$this->positiveId($this->inputOrExisting($input,'partner_contact_id',$existing),'Customer');
        $customer=$this->contact($customerId,$companyId);
        $contractId=$this->nullableId($this->inputOrExisting($input,'contract_id',$existing),'Customer Contract');
        if($contractId!==null){if(!$authorization->allowsForCompany('contracts.read',$companyId))throw new \Erp\Core\Security\AccessDeniedException('Customer Contract access is required to link a Contract.');$contracts=new \Erp\Modules\Contracts\ContractRepository($this->pdo);$active=$contracts->compatibleForOrder($companyId,$customerId,'customer');$kept=$existing&&(int)($existing['contract_id']??0)===$contractId&&$contracts->matchesOrder($contractId,$companyId,$customerId,'customer');if((!$active||(int)$active['id']!==$contractId)&&!$kept)throw new InvalidArgumentException('Select an active Customer Contract for this company and customer.');}
        $invoiceId=$this->nullableId($this->inputOrExisting($input,'invoice_contact_id',$existing),'Invoice Contact');
        $deliveryId=$this->nullableId($this->inputOrExisting($input,'delivery_contact_id',$existing),'Delivery Contact');
        if ($invoiceId!==null) $this->contact($invoiceId,$companyId);
        if ($deliveryId!==null) $this->contact($deliveryId,$companyId);
        $crmId=$this->nullableId($this->inputOrExisting($input,'crm_lead_id',$existing),'Opportunity');
        if ($crmId!==null) {
            $crm=$this->pdo->prepare("SELECT partner_contact_id FROM crm_leads WHERE id=:id AND company_id=:company_id AND record_type='opportunity' AND state IN ('open','won') LIMIT 1");
            $crm->execute(['id'=>$crmId,'company_id'=>$companyId]); $crmContact=$crm->fetchColumn();
            if ($crmContact===false) throw new InvalidArgumentException('Select an open or won Opportunity from this company.');
            if ($crmContact!==null && (int)$crmContact!==$customerId) throw new InvalidArgumentException('The selected customer must match the Opportunity contact.');
        }
        $templateId=$this->nullableId($this->inputOrExisting($input,'sale_order_template_id',$existing),'Quotation Template');
        if ($templateId!==null) {
            $templateQuery=$this->pdo->prepare('SELECT id FROM sales_order_templates WHERE id=:id AND company_id=:company_id AND (active=1 OR id=:kept_id) LIMIT 1');
            $templateQuery->execute(['id'=>$templateId,'company_id'=>$companyId,'kept_id'=>$existing && (int)$existing['sale_order_template_id']===$templateId?$templateId:0]);
            if (!$templateQuery->fetchColumn()) throw new InvalidArgumentException('Select an active quotation template available to this company.');
        }
        $salespersonId=$this->nullableId($this->inputOrExisting($input,'salesperson_user_id',$existing),'Salesperson');
        if ($salespersonId!==null) $this->assertUserCompany($salespersonId,$companyId);
        $pricelistId=$this->nullableId(array_key_exists('pricelist_id',$input)?$input['pricelist_id']:($existing['pricelist_id']??$customer['customer_pricelist_id']??null),'Pricelist');
        $termsId=$this->nullableId(array_key_exists('payment_terms_id',$input)?$input['payment_terms_id']:($existing['payment_terms_id']??$customer['customer_payment_terms_id']??null),'Payment Terms');
        $warehouseId=$this->nullableId($this->inputOrExisting($input,'warehouse_id',$existing),'Warehouse');
        $currencyCode=strtoupper($this->text($this->inputOrExisting($input,'currency_code',$existing,$customer['company_currency']),'Currency',3)??'');
        $this->assertCurrency($currencyCode);
        if ($pricelistId!==null) $this->assertPricelist($pricelistId,$companyId,$currencyCode);
        if ($termsId!==null) $this->assertCompanyReference('payment_terms',$termsId,$companyId,'Payment Terms');
        if ($warehouseId!==null) $this->assertCompanyReference('warehouses',$warehouseId,$companyId,'Warehouse');
        $orderDate=$this->date($this->inputOrExisting($input,'order_date',$existing,gmdate('Y-m-d')),'Order Date',false);
        $validity=$this->date($this->inputOrExisting($input,'validity_date',$existing),'Validity Date',true);
        if ($validity!==null && $validity<$orderDate) throw new InvalidArgumentException('Validity Date cannot be earlier than Order Date.');
        $clientRef=$this->text($this->inputOrExisting($input,'client_order_ref',$existing),'Customer Reference',190);
        $note=$this->text($this->inputOrExisting($input,'note',$existing),'Terms and Conditions',10000);
        $lines=$this->prepareLines($input['lines'],$companyId);
        $totals=['untaxed'=>0.0,'tax'=>0.0,'total'=>0.0];
        foreach ($lines as $line) { $totals['untaxed']+=$line['subtotal']; $totals['tax']+=$line['tax_amount']; $totals['total']+=$line['total']; }
        $totals=array_map(static fn(float $v):string=>number_format($v,6,'.',''),$totals);
        $this->pdo->beginTransaction();
        try {
            if ($existing) {
                $locked = $this->pdo->prepare('SELECT state,company_id FROM sales_orders WHERE id=:id FOR UPDATE');
                $locked->execute(['id'=>$id]);
                $current = $locked->fetch();
                if (!$current || (int)$current['company_id'] !== $companyId || $current['state'] !== 'draft') {
                    throw new InvalidArgumentException('This quotation changed while it was being edited. Reload and try again.');
                }
                $lockedOrder=$this->find((int)$id,$user,$authorization);
                if(!$lockedOrder||$lockedOrder['state']!=='draft')throw new InvalidArgumentException('This quotation changed while it was being edited. Reload and try again.');
                if(\Erp\Modules\Settings\ApprovalRepository::fingerprint($lockedOrder)!==\Erp\Modules\Settings\ApprovalRepository::fingerprint($existing))throw new InvalidArgumentException('This quotation was edited by another user. Reload it before saving your changes.');
                if(($lockedOrder['approval']['status']??null)==='pending')throw new InvalidArgumentException('This quotation is locked while its approval layers are pending.');
                $existing=$lockedOrder;
                $this->pdo->prepare('UPDATE sales_orders SET partner_contact_id=:customer,contract_id=:contract,invoice_contact_id=:invoice_contact,delivery_contact_id=:delivery_contact,crm_lead_id=:crm,sale_order_template_id=:template,salesperson_user_id=:salesperson,pricelist_id=:pricelist,payment_terms_id=:terms,warehouse_id=:warehouse,currency_code=:currency,order_date=:order_date,validity_date=:validity,client_order_ref=:client_ref,note=:note,untaxed_amount=:untaxed,tax_amount=:tax,total_amount=:total,updated_by=:actor WHERE id=:id')
                    ->execute(['customer'=>$customerId,'contract'=>$contractId,'invoice_contact'=>$invoiceId,'delivery_contact'=>$deliveryId,'crm'=>$crmId,'template'=>$templateId,'salesperson'=>$salespersonId,'pricelist'=>$pricelistId,'terms'=>$termsId,'warehouse'=>$warehouseId,'currency'=>$currencyCode,'order_date'=>$orderDate,'validity'=>$validity,'client_ref'=>$clientRef,'note'=>$note,'untaxed'=>$totals['untaxed'],'tax'=>$totals['tax'],'total'=>$totals['total'],'actor'=>$actorId,'id'=>$id]);
                $this->pdo->prepare('DELETE FROM sales_order_lines WHERE order_id=:id')->execute(['id'=>$id]); $orderId=$id;
                $event='sales_quotation_updated'; $old=['state'=>'draft','total_amount'=>$existing['total_amount']];
            } else {
                $name=$this->nextNumber($companyId,(int)substr($orderDate,0,4));
                $this->pdo->prepare('INSERT INTO sales_orders (company_id,name,partner_contact_id,contract_id,invoice_contact_id,delivery_contact_id,crm_lead_id,sale_order_template_id,salesperson_user_id,pricelist_id,payment_terms_id,warehouse_id,currency_code,order_date,validity_date,state,client_order_ref,note,untaxed_amount,tax_amount,total_amount,created_by,updated_by) VALUES (:company_id,:name,:customer,:contract,:invoice_contact,:delivery_contact,:crm,:template,:salesperson,:pricelist,:terms,:warehouse,:currency,:order_date,:validity,\'draft\',:client_ref,:note,:untaxed,:tax,:total,:creator,:actor)')
                    ->execute(['company_id'=>$companyId,'name'=>$name,'customer'=>$customerId,'contract'=>$contractId,'invoice_contact'=>$invoiceId,'delivery_contact'=>$deliveryId,'crm'=>$crmId,'template'=>$templateId,'salesperson'=>$salespersonId,'pricelist'=>$pricelistId,'terms'=>$termsId,'warehouse'=>$warehouseId,'currency'=>$currencyCode,'order_date'=>$orderDate,'validity'=>$validity,'client_ref'=>$clientRef,'note'=>$note,'untaxed'=>$totals['untaxed'],'tax'=>$totals['tax'],'total'=>$totals['total'],'creator'=>$actorId,'actor'=>$actorId]);
                $orderId=(int)$this->pdo->lastInsertId(); $event='sales_quotation_created'; $old=null;
            }
            $insert=$this->pdo->prepare('INSERT INTO sales_order_lines (order_id,sequence,product_id,uom_id,description,quantity,invoice_policy,unit_price,discount_percent,tax_id,tax_name_snapshot,tax_rate_snapshot,tax_included_snapshot,subtotal,tax_amount,total) VALUES (:order_id,:sequence,:product,:uom,:description,:quantity,:invoice_policy,:price,:discount,:tax_id,:tax_name,:tax_rate,:tax_included,:subtotal,:tax_amount,:total)');
            foreach ($lines as $index=>$line) $insert->execute(['order_id'=>$orderId,'sequence'=>($index+1)*10,'product'=>$line['product_id'],'uom'=>$line['uom_id'],'description'=>$line['description'],'quantity'=>$line['quantity'],'invoice_policy'=>$line['invoice_policy'],'price'=>$line['unit_price'],'discount'=>$line['discount_percent'],'tax_id'=>$line['tax_id'],'tax_name'=>$line['tax_name'],'tax_rate'=>$line['tax_rate'],'tax_included'=>$line['tax_included'],'subtotal'=>$line['subtotal'],'tax_amount'=>$line['tax_amount'],'total'=>$line['total']]);
            $this->audit($companyId,$actorId,$orderId,'sales_order',$event,$old,['name'=>$existing['name']??$name,'state'=>'draft','customer_id'=>$customerId,'crm_lead_id'=>$crmId,'total_amount'=>$totals['total']],$remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
        return $this->find($orderId,$user,$authorization) ?? throw new InvalidArgumentException('Saved quotation could not be reloaded.');
    }

    public function markSent(int $id, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $order=$this->find($id,$user,$authorization); if (!$order) throw new InvalidArgumentException('Sales order was not found.');
        if(($order['approval']['status']??null)==='pending')throw new InvalidArgumentException('This quotation cannot change status while approval layers are pending.');
        $this->pdo->beginTransaction();
        try {
            $locked=$this->pdo->prepare('SELECT state FROM sales_orders WHERE id=:id AND company_id=:company_id FOR UPDATE');
            $locked->execute(['id'=>$id,'company_id'=>$order['company_id']]); $state=$locked->fetchColumn();
            $order=$this->find($id,$user,$authorization);
            if (!$order || !in_array($state,['draft','sent'],true) || $order['state']!==$state) throw new InvalidArgumentException('Only quotations can be marked as sent.');
            if(($order['approval']['status']??null)==='pending')throw new InvalidArgumentException('This quotation cannot change status while approval layers are pending.');
            if ($state==='draft') {
                $this->pdo->prepare("UPDATE sales_orders SET state='sent',sent_at=UTC_TIMESTAMP(),updated_by=:actor WHERE id=:id AND state='draft'")->execute(['actor'=>$actorId,'id'=>$id]);
                $this->audit((int)$order['company_id'],$actorId,$id,'sales_order','sales_quotation_marked_sent',['state'=>'draft'],['state'=>'sent','email_sent'=>false],$remoteAddress);
            }
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
        return $this->find($id,$user,$authorization) ?? $order;
    }

    public function confirm(int $id, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $order=$this->find($id,$user,$authorization); if (!$order) throw new InvalidArgumentException('Sales order was not found.');
        if (!in_array($order['state'],['draft','sent'],true)) throw new InvalidArgumentException('Only draft or sent quotations can be confirmed.');
        if (!$order['lines']) throw new InvalidArgumentException('Add at least one product line before confirming.');
        $companyId=(int)$order['company_id'];
        foreach($order['lines'] as $line) if($line['product_type']==='combo') throw new InvalidArgumentException('Combo products need a configured kit or bill of materials before they can be confirmed in Inventory.');
        $goods=array_values(array_filter($order['lines'],static fn(array $line):bool=>$line['product_type']==='goods'));
        $this->pdo->beginTransaction();
        try {
            $locked=$this->pdo->prepare('SELECT state FROM sales_orders WHERE id=:id AND company_id=:company_id FOR UPDATE');
            $locked->execute(['id'=>$id,'company_id'=>$companyId]);
            if ($locked->fetchColumn()!==$order['state']) throw new InvalidArgumentException('This quotation changed while it was being confirmed. Reload and try again.');
            $order=$this->find($id,$user,$authorization);
            if(!$order||!in_array($order['state'],['draft','sent'],true))throw new InvalidArgumentException('Only draft or sent quotations can be confirmed.');
            if(!$order['lines'])throw new InvalidArgumentException('Add at least one product line before confirming.');
            foreach($order['lines'] as $line)if($line['product_type']==='combo')throw new InvalidArgumentException('Combo products need a configured kit or bill of materials before they can be confirmed in Inventory.');
            $goods=array_values(array_filter($order['lines'],static fn(array $line):bool=>$line['product_type']==='goods'));
            $companyId=(int)$order['company_id'];
            $approval=(new \Erp\Modules\Settings\ApprovalRepository($this->pdo))->requestIfRequired('sales_order',$id,$companyId,(string)$order['currency_code'],(string)$order['total_amount'],\Erp\Modules\Settings\ApprovalRepository::fingerprint($order),$actorId,$remoteAddress);
            if ($approval !== null) {
                $this->pdo->commit();
                return $this->find($id,$user,$authorization) ?? ($order + ['approval'=>$approval]);
            }
            if ($goods) $this->createDeliveryMoves($order,$goods,$actorId);
            $this->pdo->prepare("UPDATE sales_orders SET state='sale',confirmed_at=UTC_TIMESTAMP(),updated_by=:actor WHERE id=:id")->execute(['actor'=>$actorId,'id'=>$id]);
            if (!empty($order['crm_lead_id'])) $this->markOpportunityWon((int)$order['crm_lead_id'],$companyId,$actorId,$remoteAddress);
            $this->audit($companyId,$actorId,$id,'sales_order','sales_order_confirmed',['state'=>$order['state']],['state'=>'sale','delivery_moves_created'=>count($goods)],$remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
        return $this->find($id,$user,$authorization) ?? $order;
    }

    public function cancel(int $id, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $order=$this->find($id,$user,$authorization); if (!$order) throw new InvalidArgumentException('Sales order was not found.');
        $this->pdo->beginTransaction();
        try {
            $locked=$this->pdo->prepare('SELECT state FROM sales_orders WHERE id=:id AND company_id=:company_id FOR UPDATE');
            $locked->execute(['id'=>$id,'company_id'=>$order['company_id']]); $state=$locked->fetchColumn();
            if (!in_array($state,['draft','sent'],true)) throw new InvalidArgumentException('Confirmed sales orders cannot be cancelled here; resolve their delivery and invoice documents first.');
            (new \Erp\Modules\Settings\ApprovalRepository($this->pdo))->cancelPending('sales_order',$id,(int)$order['company_id'],$actorId,$remoteAddress);
            $this->pdo->prepare("UPDATE sales_orders SET state='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_by=:actor WHERE id=:id AND state IN ('draft','sent')")->execute(['actor'=>$actorId,'id'=>$id]);
            $this->audit((int)$order['company_id'],$actorId,$id,'sales_order','sales_quotation_cancelled',['state'=>$state],['state'=>'cancelled'],$remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
        return $this->find($id,$user,$authorization) ?? $order;
    }

    public function createTax(array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown=array_diff(array_keys($input),['company_id','name','amount','price_included','account_id']);
        if($unknown) throw new InvalidArgumentException('Unsupported tax fields: ' . implode(', ',$unknown));
        $companyId=$this->resolveCompany($user,$authorization,$input['company_id']??null);
        $name=$this->text($input['name']??null,'Tax Name',120);
        if($name===null) throw new InvalidArgumentException('Tax Name is required.');
        $amount=$this->decimal($input['amount']??null,'Tax Rate',5,4,true);
        if((float)$amount>100) throw new InvalidArgumentException('Tax Rate must be from 0 to 100 percent.');
        $accountId=$this->nullableId($input['account_id']??null,'Tax Payable Account');
        if((float)$amount>0&&$accountId===null)throw new InvalidArgumentException('Select a Tax Payable account for a non-zero tax.');
        if($accountId!==null){$account=$this->pdo->prepare("SELECT 1 FROM accounting_accounts WHERE id=:id AND company_id=:company_id AND active=1 AND account_type LIKE 'liability%' LIMIT 1");$account->execute(['id'=>$accountId,'company_id'=>$companyId]);if(!$account->fetchColumn())throw new InvalidArgumentException('Tax Payable account must be an active liability account in this company.');}
        $rawIncluded=$input['price_included']??false;
        if (!is_bool($rawIncluded) && !in_array($rawIncluded,[0,1,'0','1','true','false'],true)) throw new InvalidArgumentException('Price Included must be true or false.');
        $included=filter_var($rawIncluded,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
        if ($included===null) throw new InvalidArgumentException('Price Included must be true or false.');
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare('INSERT INTO sales_taxes (company_id,name,amount,price_included,account_id) VALUES (:company_id,:name,:amount,:included,:account_id)')
                ->execute(['company_id'=>$companyId,'name'=>$name,'amount'=>$amount,'included'=>$included?1:0,'account_id'=>$accountId]);
            $id=(int)$this->pdo->lastInsertId();
            $this->audit($companyId,$actorId,$id,'sales_tax','sales_tax_created',null,['name'=>$name,'amount'=>$amount,'price_included'=>$included],$remoteAddress);
            $this->pdo->commit();
        }catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
        $query=$this->pdo->prepare('SELECT id,name,amount,price_included,account_id FROM sales_taxes WHERE id=:id');$query->execute(['id'=>$id]);return $query->fetch()?:[];
    }

    private function prepareLines(array $input,int $companyId): array
    {
        if (count($input)>500) throw new InvalidArgumentException('A quotation can contain at most 500 product lines.');
        $result=[];
        foreach (array_values($input) as $index=>$row) {
            if (!is_array($row)) throw new InvalidArgumentException('Each quotation line must be an object.');
            $unknown=array_diff(array_keys($row),['product_id','uom_id','description','quantity','unit_price','discount_percent','tax_id']);
            if ($unknown) throw new InvalidArgumentException('Unsupported quotation line fields: ' . implode(', ',$unknown));
            $productId=$this->positiveId($row['product_id']??null,'Product');
            $productQuery=$this->pdo->prepare('SELECT p.name,p.product_type,p.invoice_policy,p.uom_id,p.sale_ok,p.active,u.category_id FROM products p JOIN units_of_measure u ON u.id=p.uom_id WHERE p.id=:id AND p.company_id=:company_id LIMIT 1');
            $productQuery->execute(['id'=>$productId,'company_id'=>$companyId]); $product=$productQuery->fetch();
            if (!$product || !(int)$product['active'] || !(int)$product['sale_ok']) throw new InvalidArgumentException('Each line must use an active, saleable product from this company.');
            $uomId=$this->positiveId($row['uom_id']??$product['uom_id'],'Unit of Measure');
            $uomQuery=$this->pdo->prepare('SELECT category_id,company_id,active FROM units_of_measure WHERE id=:id LIMIT 1'); $uomQuery->execute(['id'=>$uomId]); $uom=$uomQuery->fetch();
            if (!$uom || !(int)$uom['active'] || (int)$uom['category_id']!==(int)$product['category_id'] || ($uom['company_id']!==null && (int)$uom['company_id']!==$companyId)) throw new InvalidArgumentException('Each line must use an active unit in the product unit category.');
            $quantity=$this->decimal($row['quantity']??null,'Quantity',14,6,false);
            if ((float)$quantity<=0) throw new InvalidArgumentException('Quantity must be greater than zero.');
            $price=$this->decimal($row['unit_price']??0,'Unit Price',14,6,true);
            $discount=$this->decimal($row['discount_percent']??0,'Discount',3,4,true);
            if ((float)$discount>100) throw new InvalidArgumentException('Discount cannot exceed 100%.');
            $taxId=$this->nullableId($row['tax_id']??null,'Tax'); $tax=null;
            if ($taxId!==null) {
                $taxQuery=$this->pdo->prepare('SELECT name,amount,price_included FROM sales_taxes WHERE id=:id AND active=1 AND (company_id IS NULL OR company_id=:company_id) LIMIT 1');
                $taxQuery->execute(['id'=>$taxId,'company_id'=>$companyId]); $tax=$taxQuery->fetch();
                if (!$tax) throw new InvalidArgumentException('Select an active tax available to this company.');
            }
            $description=$this->text($row['description']??$product['name'],'Description',500)??$product['name'];
            $gross=(float)$quantity*(float)$price*(1-(float)$discount/100); $rate=(float)($tax['amount']??0); $included=(int)($tax['price_included']??0);
            $taxAmount=$included&&$rate>0?$gross-($gross/(1+$rate/100)):$gross*$rate/100;
            $subtotal=$included?$gross-$taxAmount:$gross; $total=$included?$gross:$gross+$taxAmount;
            $result[]=['product_id'=>$productId,'uom_id'=>$uomId,'description'=>$description,'quantity'=>$quantity,'invoice_policy'=>$product['invoice_policy'],'unit_price'=>$price,'discount_percent'=>$discount,'tax_id'=>$taxId,'tax_name'=>$tax['name']??null,'tax_rate'=>number_format($rate,4,'.',''),'tax_included'=>$included,'subtotal'=>number_format($subtotal,6,'.',''),'tax_amount'=>number_format($taxAmount,6,'.',''),'total'=>number_format($total,6,'.',''),'product_type'=>$product['product_type'],'sequence'=>($index+1)*10];
        }
        return $result;
    }

    private function createDeliveryMoves(array $order,array $goods,int $actorId): void
    {
        $warehouseId=$order['warehouse_id']!==null?(int)$order['warehouse_id']:null;
        if ($warehouseId===null) throw new InvalidArgumentException('Choose a warehouse before confirming an order that contains goods.');
        $source=$this->pdo->prepare("SELECT id FROM stock_locations WHERE company_id=:company_id AND warehouse_id=:warehouse_id AND active=1 AND location_usage='internal' ORDER BY id LIMIT 1 FOR UPDATE");
        $source->execute(['company_id'=>$order['company_id'],'warehouse_id'=>$warehouseId]); $sourceId=$source->fetchColumn();
        if ($sourceId===false) throw new InvalidArgumentException('The selected warehouse has no internal stock location. Configure its locations before confirming this order.');
        $dest=$this->pdo->prepare("SELECT id FROM stock_locations WHERE company_id=:company_id AND warehouse_id IS NULL AND active=1 AND location_usage='customer' ORDER BY id LIMIT 1 FOR UPDATE");
        $dest->execute(['company_id'=>$order['company_id']]); $destId=$dest->fetchColumn();
        if ($destId===false) {
            $this->pdo->prepare("INSERT INTO stock_locations (company_id,name,complete_name,location_usage) VALUES (:company_id,'Customers','Partners/Customers','customer')")->execute(['company_id'=>$order['company_id']]);
            $destId=(int)$this->pdo->lastInsertId();
        }
        $insert=$this->pdo->prepare("INSERT INTO stock_moves (company_id,reference,product_id,uom_id,source_location_id,destination_location_id,demand_quantity,state,origin_model,origin_record_id,source_line_model,source_line_id,origin_reference,created_by) VALUES (:company_id,:reference,:product,:uom,:source,:destination,:quantity,'draft','sale_order',:order_id,'sales_order_line',:line_id,:origin,:actor)");
        $insertLine=$this->pdo->prepare('INSERT INTO stock_move_lines (move_id,lot_id,lot_code_snapshot,expiration_date_snapshot,quantity) VALUES (:move_id,NULL,NULL,NULL,:quantity)');
        foreach ($goods as $index=>$line) {
            $reference='DEL/' . $order['id'] . '/' . ($index+1);
            $insert->execute(['company_id'=>$order['company_id'],'reference'=>$reference,'product'=>$line['product_id'],'uom'=>$line['uom_id'],'source'=>$sourceId,'destination'=>$destId,'quantity'=>$line['quantity'],'order_id'=>$order['id'],'line_id'=>$line['id'],'origin'=>$order['name'],'actor'=>$actorId]);
            $moveId=(int)$this->pdo->lastInsertId();
            $insertLine->execute(['move_id'=>$moveId,'quantity'=>$line['quantity']]);
        }
    }

    private function markOpportunityWon(int $leadId,int $companyId,int $actorId,string $remoteAddress): void
    {
        $query=$this->pdo->prepare("SELECT id,stage_id,state FROM crm_leads WHERE id=:id AND company_id=:company_id AND record_type='opportunity' LIMIT 1 FOR UPDATE");
        $query->execute(['id'=>$leadId,'company_id'=>$companyId]); $lead=$query->fetch(); if (!$lead || $lead['state']==='won') return;
        $stage=$this->pdo->prepare('SELECT id FROM crm_stages WHERE company_id=:company_id AND active=1 AND is_won=1 ORDER BY sequence,id LIMIT 1'); $stage->execute(['company_id'=>$companyId]); $stageId=$stage->fetchColumn();
        if ($stageId===false) return;
        $this->pdo->prepare("UPDATE crm_leads SET state='won',stage_id=:stage_id,probability=100,updated_by=:actor WHERE id=:id")->execute(['stage_id'=>$stageId,'actor'=>$actorId,'id'=>$leadId]);
        $this->audit($companyId,$actorId,$leadId,'crm_lead','crm_opportunity_won_from_sales',['state'=>$lead['state'],'stage_id'=>$lead['stage_id']],['state'=>'won','sales_order_confirmed'=>true],$remoteAddress);
    }

    private function nextNumber(int $companyId,int $year): string
    {
        $insert=$this->pdo->prepare('INSERT INTO sales_order_sequences (company_id,sequence_year,last_number) VALUES (:company_id,:year,0) ON DUPLICATE KEY UPDATE company_id=VALUES(company_id)');
        $insert->execute(['company_id'=>$companyId,'year'=>$year]);
        $lock=$this->pdo->prepare('SELECT last_number FROM sales_order_sequences WHERE company_id=:company_id AND sequence_year=:year FOR UPDATE'); $lock->execute(['company_id'=>$companyId,'year'=>$year]); $next=(int)$lock->fetchColumn()+1;
        $this->pdo->prepare('UPDATE sales_order_sequences SET last_number=:number WHERE company_id=:company_id AND sequence_year=:year')->execute(['number'=>$next,'company_id'=>$companyId,'year'=>$year]);
        return sprintf($this->documentPrefix($companyId, 'sales_order', 'SO') . '%04d-%05d',$year,$next);
    }

    private function documentPrefix(int $companyId, string $sequenceKey, string $default): string
    {
        $query = $this->pdo->prepare('SELECT prefix FROM company_document_prefixes WHERE company_id = :company AND sequence_key = :sequence_key');
        $query->execute(['company' => $companyId, 'sequence_key' => $sequenceKey]);
        $prefix = $query->fetchColumn();
        return $prefix === false ? $default : (string)$prefix;
    }

    private function contact(int $id,int $companyId): array
    {
        $query=$this->pdo->prepare('SELECT c.id,c.company_id,c.active,c.customer_pricelist_id,c.customer_payment_terms_id,co.currency_code AS company_currency FROM contacts c LEFT JOIN companies co ON co.id=:company_id WHERE c.id=:id LIMIT 1');
        $query->execute(['id'=>$id,'company_id'=>$companyId]); $row=$query->fetch();
        if (!$row || !(int)$row['active'] || ($row['company_id']!==null && (int)$row['company_id']!==$companyId)) throw new InvalidArgumentException('Select an active Contact shared with or belonging to this company.');
        return $row;
    }

    private function assertUserCompany(int $userId,int $companyId): void
    {
        $query=$this->pdo->prepare("SELECT 1 FROM users u JOIN user_company_access a ON a.user_id=u.id AND a.company_id=:company_id WHERE u.id=:user_id AND u.status IN ('confirmed','active') LIMIT 1"); $query->execute(['user_id'=>$userId,'company_id'=>$companyId]);
        if (!$query->fetchColumn()) throw new InvalidArgumentException('The selected salesperson is not an active user of this company.');
    }

    private function assertCompanyReference(string $table,int $id,int $companyId,string $label): void
    {
        if (!in_array($table,['payment_terms','warehouses'],true)) throw new InvalidArgumentException('Unsupported company reference.');
        $companyClause=$table==='payment_terms'?'(company_id IS NULL OR company_id=:company_id)':'company_id=:company_id';
        $query=$this->pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE id=:id AND active=1 AND ' . $companyClause . ' LIMIT 1'); $query->execute(['id'=>$id,'company_id'=>$companyId]);
        if (!$query->fetchColumn()) throw new InvalidArgumentException('Select an active ' . $label . ' from this company.');
    }

    private function assertPricelist(int $id,int $companyId,string $currency): void
    {
        $query=$this->pdo->prepare('SELECT currency_code FROM price_lists WHERE id=:id AND active=1 AND (company_id IS NULL OR company_id=:company_id) LIMIT 1'); $query->execute(['id'=>$id,'company_id'=>$companyId]); $value=$query->fetchColumn();
        if ($value===false || $value!==$currency) throw new InvalidArgumentException('The Pricelist must be active for this company and use the quotation currency.');
    }

    private function assertCurrency(string $currency): void
    {
        $query=$this->pdo->prepare('SELECT 1 FROM currencies WHERE currency_code=:currency AND active=1 LIMIT 1'); $query->execute(['currency'=>$currency]);
        if (!$query->fetchColumn()) throw new InvalidArgumentException('Select an active currency.');
    }

    private function companyOptions(UserContext $user): array
    {
        if ($user->superuser) return $this->pdo->query('SELECT id,name,currency_code FROM companies WHERE active=1 ORDER BY name,id')->fetchAll();
        if (!$user->companyIds) return [];
        $params=[]; $marks=[]; foreach($user->companyIds as $i=>$companyId){$marks[]=':c'.$i;$params['c'.$i]=$companyId;}
        $query=$this->pdo->prepare('SELECT id,name,currency_code FROM companies WHERE active=1 AND id IN (' . implode(',',$marks) . ') ORDER BY name,id'); $query->execute($params); return $query->fetchAll();
    }

    private function resolveCompany(UserContext $user,Authorization $authorization,mixed $raw): int
    {
        $companyId=$raw===null||$raw===''?null:$this->positiveId($raw,'Company');
        if ($companyId===null && $user->superuser) $companyId=(int)$this->pdo->query('SELECT id FROM companies WHERE active=1 ORDER BY id LIMIT 1')->fetchColumn()?:null;
        $companyId??=$user->defaultCompanyId??(count($user->companyIds)===1?$user->companyIds[0]:null);
        if ($companyId===null || !$authorization->canAccessCompany($companyId)) throw new InvalidArgumentException('Choose an allowed company.');
        $check=$this->pdo->prepare('SELECT 1 FROM companies WHERE id=:id AND active=1 LIMIT 1');$check->execute(['id'=>$companyId]);
        if (!$check->fetchColumn()) throw new InvalidArgumentException('The selected company is inactive or does not exist.'); return $companyId;
    }

    private function audit(int $companyId,int $actorId,int $recordId,string $model,string $event,?array $old,array $new,string $remoteAddress): void
    {
        $ip=filter_var($remoteAddress,FILTER_VALIDATE_IP)?inet_pton($remoteAddress):null;
        $query=$this->pdo->prepare('INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) VALUES (:company_id,:user_id,:event_key,:model_key,:record_id,:old_values,:new_values,:ip)');
        $query->bindValue(':company_id',$companyId,PDO::PARAM_INT);$query->bindValue(':user_id',$actorId,PDO::PARAM_INT);$query->bindValue(':event_key',$event);$query->bindValue(':model_key',$model);$query->bindValue(':record_id',$recordId,PDO::PARAM_INT);
        $query->bindValue(':old_values',$old===null?null:json_encode($old,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$old===null?PDO::PARAM_NULL:PDO::PARAM_STR);$query->bindValue(':new_values',json_encode($new,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));$query->bindValue(':ip',$ip,$ip===null?PDO::PARAM_NULL:PDO::PARAM_LOB);$query->execute();
    }

    private function date(mixed $value,string $label,bool $nullable): ?string
    {
        if (($value===null||$value==='')&&$nullable) return null;
        if (!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)||!checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4))) throw new InvalidArgumentException($label . ' must be a valid YYYY-MM-DD date.'); return $value;
    }
    private function text(mixed $value,string $label,int $max): ?string
    {
        if ($value===null) return null; if (!is_string($value)&&!is_numeric($value)) throw new InvalidArgumentException($label . ' must be text.'); $value=trim((string)$value); if ($value==='') return null; if (mb_strlen($value)>$max) throw new InvalidArgumentException($label . ' must be ' . $max . ' characters or fewer.'); return $value;
    }
    private function inputOrExisting(array $input,string $field,?array $existing,mixed $default=null): mixed
    {
        return array_key_exists($field,$input)?$input[$field]:($existing[$field]??$default);
    }
    private function positiveId(mixed $value,string $label): int { $id=filter_var($value,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException($label . ' must be a positive integer.');return $id; }
    private function nullableId(mixed $value,string $label): ?int { return $value===null||$value===''?null:$this->positiveId($value,$label); }
    private function decimal(mixed $value,string $label,int $integerDigits,int $fractionDigits,bool $zeroAllowed): string
    {
        if (!is_string($value)&&!is_numeric($value)) throw new InvalidArgumentException($label . ' must be a number.'); $text=(string)$value;
        $pattern='/^(?:0|[1-9][0-9]{0,' . ($integerDigits-1) . '})(?:\.[0-9]{1,' . $fractionDigits . '})?$/D';
        if (!preg_match($pattern,$text)||(!$zeroAllowed&&(float)$text<0)) throw new InvalidArgumentException($label . ' has an invalid decimal value.'); return $text;
    }
}
