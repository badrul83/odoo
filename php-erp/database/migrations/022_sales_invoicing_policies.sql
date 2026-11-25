ALTER TABLE products
    ADD COLUMN invoice_policy VARCHAR(16) NOT NULL DEFAULT 'ordered' AFTER sale_ok,
    ADD CONSTRAINT chk_product_invoice_policy CHECK (invoice_policy IN ('ordered','delivered'));

ALTER TABLE sales_order_lines
    ADD COLUMN invoice_policy VARCHAR(16) NOT NULL DEFAULT 'ordered' AFTER quantity;

UPDATE sales_order_lines l
JOIN products p ON p.id=l.product_id
SET l.invoice_policy=p.invoice_policy;

ALTER TABLE sales_order_lines
    ADD CONSTRAINT chk_sales_order_line_invoice_policy CHECK (invoice_policy IN ('ordered','delivered'));

ALTER TABLE customer_invoices
    DROP INDEX uq_customer_invoice_sales_order,
    ADD KEY idx_customer_invoice_order_state (sales_order_id,state,id);
