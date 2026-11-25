-- Standalone customer invoices may reference saleable products, but do not create
-- stock moves. Sales Orders remain the path for physical delivery and stock-cost links.
ALTER TABLE customer_invoices
    MODIFY COLUMN sales_order_id BIGINT UNSIGNED NULL;

ALTER TABLE customer_invoice_lines
    MODIFY COLUMN sales_order_line_id BIGINT UNSIGNED NULL;
