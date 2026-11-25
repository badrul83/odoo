<?php
declare(strict_types=1);

namespace Erp\Modules\Sales;

use Erp\Core\Security\Authorization;
use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDO;
use Throwable;

final class SalesTemplateRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function list(int $companyId, UserContext $user, Authorization $auth): array
    {
        $this->assertCompany($companyId, $auth);
        $query = $this->pdo->prepare('SELECT t.id,t.company_id,t.name,t.validity_days,t.pricelist_id,t.terms,t.active,p.name AS pricelist_name FROM sales_order_templates t LEFT JOIN price_lists p ON p.id=t.pricelist_id WHERE t.company_id=:company_id ORDER BY t.active DESC,t.name,t.id');
        $query->execute(['company_id'=>$companyId]);
        $templates = $query->fetchAll();
        foreach ($templates as &$template) $template['lines'] = $this->lines((int)$template['id']);
        unset($template);
        return $templates;
    }

    public function find(int $id, int $companyId, Authorization $auth): ?array
    {
        $this->assertCompany($companyId, $auth);
        $query=$this->pdo->prepare('SELECT t.id,t.company_id,t.name,t.validity_days,t.pricelist_id,t.terms,t.active,p.name AS pricelist_name FROM sales_order_templates t LEFT JOIN price_lists p ON p.id=t.pricelist_id WHERE t.id=:id AND t.company_id=:company_id LIMIT 1');
        $query->execute(['id'=>$id,'company_id'=>$companyId]); $row=$query->fetch();
        if (!$row) return null;
        $row['lines']=$this->lines($id);
        return $row;
    }

    public function save(?int $id,array $input,UserContext $user,Authorization $auth,string $remoteAddress): array
    {
        $unknown=array_diff(array_keys($input),['company_id','name','validity_days','pricelist_id','terms','active','lines']);
        if ($unknown) throw new InvalidArgumentException('Unsupported quotation template fields: '.implode(', ',$unknown));
        $companyId=$this->positiveId($input['company_id']??null,'Company'); $this->assertCompany($companyId,$auth);
        $name=trim((string)($input['name']??''));
        if ($name==='' || mb_strlen($name)>120) throw new InvalidArgumentException('Name is required and must be 120 characters or fewer.');
        $validity=filter_var($input['validity_days']??0,FILTER_VALIDATE_INT);
        if ($validity===false || $validity<0 || $validity>3650) throw new InvalidArgumentException('Quotation validity must be between 0 and 3650 days.');
        $terms=trim((string)($input['terms']??'')); if (mb_strlen($terms)>10000) throw new InvalidArgumentException('Terms and Conditions cannot exceed 10000 characters.');
        $pricelist=$this->nullableId($input['pricelist_id']??null,'Pricelist');
        if ($pricelist!==null) { $q=$this->pdo->prepare('SELECT 1 FROM price_lists WHERE id=:id AND active=1 AND (company_id IS NULL OR company_id=:company)'); $q->execute(['id'=>$pricelist,'company'=>$companyId]); if (!$q->fetchColumn()) throw new InvalidArgumentException('Select an active pricelist available to this company.'); }
        $active=filter_var($input['active']??true,FILTER_VALIDATE_BOOL,FILTER_NULL_ON_FAILURE); if ($active===null) throw new InvalidArgumentException('Active must be true or false.');
        $rawLines=$input['lines']??[]; if (!is_array($rawLines)||!$rawLines||count($rawLines)>200) throw new InvalidArgumentException('Add between 1 and 200 product lines to the quotation template.');
        $lines=[];
        foreach (array_values($rawLines) as $index=>$row) $lines[]=$this->prepareLine($row,$companyId,$index);

        $this->pdo->beginTransaction();
        try {
            $old=null;
            if ($id!==null) {
                $lock=$this->pdo->prepare('SELECT * FROM sales_order_templates WHERE id=:id LIMIT 1 FOR UPDATE'); $lock->execute(['id'=>$id]); $old=$lock->fetch();
                if (!$old || (int)$old['company_id']!==$companyId) throw new InvalidArgumentException('Quotation template was not found in this company.');
                $this->pdo->prepare('UPDATE sales_order_templates SET name=:name,validity_days=:days,pricelist_id=:pricelist,terms=:terms,active=:active WHERE id=:id')
                    ->execute(['name'=>$name,'days'=>$validity,'pricelist'=>$pricelist,'terms'=>$terms!==''?$terms:null,'active'=>(int)$active,'id'=>$id]);
                $this->pdo->prepare('DELETE FROM sales_order_template_lines WHERE template_id=:id')->execute(['id'=>$id]);
            } else {
                $this->pdo->prepare('INSERT INTO sales_order_templates (company_id,name,validity_days,pricelist_id,terms,active) VALUES (:company,:name,:days,:pricelist,:terms,:active)')
                    ->execute(['company'=>$companyId,'name'=>$name,'days'=>$validity,'pricelist'=>$pricelist,'terms'=>$terms!==''?$terms:null,'active'=>(int)$active]);
                $id=(int)$this->pdo->lastInsertId();
            }
            $insert=$this->pdo->prepare('INSERT INTO sales_order_template_lines (template_id,sequence,product_id,uom_id,description,quantity,unit_price,discount_percent,tax_id) VALUES (:template,:sequence,:product,:uom,:description,:quantity,:price,:discount,:tax)');
            foreach ($lines as $index=>$line) $insert->execute(['template'=>$id,'sequence'=>($index+1)*10]+$line);
            $this->audit($companyId,$user->id,$id,$old?'sales_quotation_template_updated':'sales_quotation_template_created',$old?['name'=>$old['name'],'active'=>(bool)$old['active']]:null,['name'=>$name,'validity_days'=>$validity,'pricelist_id'=>$pricelist,'active'=>$active,'lines'=>$lines],$remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
        return $this->find((int)$id,$companyId,$auth) ?? throw new InvalidArgumentException('Saved quotation template could not be reloaded.');
    }

    public function archive(int $id,int $companyId,UserContext $user,Authorization $auth,string $remoteAddress): void
    {
        $this->assertCompany($companyId,$auth); $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare('SELECT name,active FROM sales_order_templates WHERE id=:id AND company_id=:company_id LIMIT 1 FOR UPDATE'); $query->execute(['id'=>$id,'company_id'=>$companyId]); $old=$query->fetch();
            if (!$old) throw new InvalidArgumentException('Quotation template was not found in this company.');
            $this->pdo->prepare('UPDATE sales_order_templates SET active=0 WHERE id=:id')->execute(['id'=>$id]);
            $this->audit($companyId,$user->id,$id,'sales_quotation_template_archived',['name'=>$old['name'],'active'=>(bool)$old['active']],['active'=>false],$remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
    }

    private function lines(int $id): array
    {
        $query=$this->pdo->prepare('SELECT l.id,l.sequence,l.product_id,p.name AS product_name,l.uom_id,u.name AS uom_name,l.description,l.quantity,l.unit_price,l.discount_percent,l.tax_id,t.name AS tax_name FROM sales_order_template_lines l JOIN products p ON p.id=l.product_id JOIN units_of_measure u ON u.id=l.uom_id LEFT JOIN sales_taxes t ON t.id=l.tax_id WHERE l.template_id=:id ORDER BY l.sequence,l.id');
        $query->execute(['id'=>$id]); return $query->fetchAll();
    }

    private function prepareLine(mixed $row,int $companyId,int $index): array
    {
        if (!is_array($row)) throw new InvalidArgumentException('Template line '.($index+1).' must be an object.');
        $unknown=array_diff(array_keys($row),['product_id','uom_id','description','quantity','unit_price','discount_percent','tax_id']);
        if ($unknown) throw new InvalidArgumentException('Unsupported fields on template line '.($index+1).'.');
        $product=$this->positiveId($row['product_id']??null,'Product');
        $q=$this->pdo->prepare('SELECT p.name,p.uom_id,u.category_id FROM products p JOIN units_of_measure u ON u.id=p.uom_id WHERE p.id=:id AND p.company_id=:company AND p.active=1 AND p.sale_ok=1'); $q->execute(['id'=>$product,'company'=>$companyId]); $p=$q->fetch();
        if (!$p) throw new InvalidArgumentException('Each template line needs an active saleable product in this company.');
        $uom=$this->positiveId($row['uom_id']??$p['uom_id'],'Unit of Measure');
        $q=$this->pdo->prepare('SELECT category_id,company_id,active FROM units_of_measure WHERE id=:id'); $q->execute(['id'=>$uom]); $u=$q->fetch();
        if (!$u || !(int)$u['active'] || (int)$u['category_id']!==(int)$p['category_id'] || ($u['company_id']!==null && (int)$u['company_id']!==$companyId)) throw new InvalidArgumentException('Template line unit must be active and in the product unit category.');
        $quantity=$this->decimal($row['quantity']??1,'Quantity',0.000001,999999999999.999999);
        $price=$this->decimal($row['unit_price']??0,'Unit Price',0,999999999999.999999);
        $discount=$this->decimal($row['discount_percent']??0,'Discount',0,100);
        $tax=$this->nullableId($row['tax_id']??null,'Tax');
        if ($tax!==null) { $q=$this->pdo->prepare('SELECT 1 FROM sales_taxes WHERE id=:id AND active=1 AND (company_id IS NULL OR company_id=:company)'); $q->execute(['id'=>$tax,'company'=>$companyId]); if (!$q->fetchColumn()) throw new InvalidArgumentException('Template tax is inactive or unavailable to this company.'); }
        $description=trim((string)($row['description']??$p['name'])); if ($description===''||mb_strlen($description)>500) throw new InvalidArgumentException('Product description is required and must be 500 characters or fewer.');
        return ['product'=>$product,'uom'=>$uom,'description'=>$description,'quantity'=>$quantity,'price'=>$price,'discount'=>$discount,'tax'=>$tax];
    }

    private function assertCompany(int $companyId,Authorization $auth): void
    {
        if (!$auth->canAccessCompany($companyId)) throw new InvalidArgumentException('Selected company is not available to this account.');
        if (!$auth->allowsForCompany('sales.write',$companyId)) throw new AccessDeniedException('You do not have Sales write access to this company.');
    }
    private function positiveId(mixed $value,string $label): int { $id=filter_var($value,FILTER_VALIDATE_INT); if ($id===false||$id<1) throw new InvalidArgumentException($label.' must be selected.'); return $id; }
    private function nullableId(mixed $value,string $label): ?int { if ($value===null||$value==='') return null; return $this->positiveId($value,$label); }
    private function decimal(mixed $value,string $label,float $min,float $max): string { if (!is_numeric($value)||!is_finite((float)$value)||(float)$value<$min||(float)$value>$max) throw new InvalidArgumentException($label.' is outside the supported range.'); return number_format((float)$value,6,'.',''); }
    private function audit(int $companyId,int $userId,int $recordId,string $event,?array $old,?array $new,string $ip): void
    {
        $query=$this->pdo->prepare('INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) VALUES (:company,:user,:event,\'sales_order_template\',:record,:old,:new,:ip)');
        $binary=@inet_pton($ip); $query->execute(['company'=>$companyId,'user'=>$userId,'event'=>$event,'record'=>$recordId,'old'=>$old===null?null:json_encode($old,JSON_THROW_ON_ERROR),'new'=>$new===null?null:json_encode($new,JSON_THROW_ON_ERROR),'ip'=>$binary===false?null:$binary]);
    }
}
