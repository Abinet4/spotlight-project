# Seller ERP: architecture notes and plan

Status: Phase 1 done (2026-10-17), draft PR from `feat/seller-erp`. Phases 2 to 5 follow. This file is the source of truth for how the ERP fits the
existing marketplace; update it as phases land.

## What already exists (checked in the code, not assumed)

| Area | Where | What it does today |
| --- | --- | --- |
| Stack | `backend/` Laravel 13, Sanctum bearer tokens; `frontend/` React 19, Vite, Tailwind 4, lucide icons | API under `/api`, pages on Vercel, API on Railway (MySQL) |
| Seller access | `routes/api.php` group `seller` with `auth:sanctum` + `role:seller` (`EnsureRole`, also blocks suspended users) | Every seller query goes through `$request->user()->sellerProfile`, so another store's id answers 404 |
| Listings = products | `products` table, `Seller\ProductController` | One row per listing: price, `stock_type` (`in_stock` / `made_to_order`), `stock_quantity`, material, color, size |
| Stock | `OrderService::place()` | Locks product rows, checks stock, **decrements `stock_quantity` at order placement** and stores `order_items.reserved_quantity` |
| Stock release | `OrderService::releaseStock()` | Adds the reserved quantity back when an order is cancelled (by anyone, or unpaid online orders timing out) |
| Orders | `orders`, `order_items`, `order_status_history` | One order per store per checkout; statuses awaiting_payment, pending_confirmation, confirmed, processing, ready, dispatched, delivered, cancelled |
| Money | `orders.commission_santim` frozen per order; `seller_ledger_entries` (commission, sale, payment, payout, adjustment) | Cash orders: store collects cash, owes commission. Online: marketplace holds money, owes the store the sale minus commission |
| Refunds | `refunds` (one per order), admin completes them | Only online orders cancelled after payment. There is no return flow after delivery yet |
| Audit | `audit_logs` via `AuditLogger` | Who did what, with ids |
| Alerts | `Alerts` service, in-app bell + email/SMS | Already used for orders, reviews, payouts |
| Seller UI | `components/WorkArea.jsx` (shared side menu), `pages/seller/*`, `components/ui.jsx` (`Stats`, `TodoList`, `Pagination`, `.rtable` phone tables) | Forest green brand, warm stone background, cards |

Not in the code today: purchase cost, SKU, suppliers, purchase orders, warehouses, stock history,
expenses, offline sales, invoices. These are what the ERP adds.

## Design rules

1. **One product record.** The ERP's inventory item *is* the existing listing. No second product table.
   ERP-only fields (SKU, cost price, minimum stock, reorder quantity) are new columns on `products`.
2. **`products.stock_quantity` stays the available quantity** that checkout already trusts. For a listing
   with options it is the sum of the options' stock. Everything that changes stock goes through one
   `InventoryService` (it locks the product row, so option and location counts change under the same lock),
   which also writes a `stock_movements` row with the quantity after the change. Reserved = units held by open orders (`order_items.reserved_quantity`),
   sold = units in delivered orders and offline sales.
3. **Locations without breaking checkout.** Each store gets a default location ("Main store"). Extra
   locations keep their own counts; the default location holds whatever is not elsewhere
   (total minus the others), so existing code that sets the total keeps working. Orders take stock from the
   default location first, and a cancellation puts it back where it came from.
4. **No double counting.** Marketplace orders are recorded once, by the existing order system. Invoices
   for them are printed from the order itself. Offline (walk-in) sales are a separate, clearly labelled
   record. Revenue is never the same thing as profit, and a store's ledger balance is not treated as cash.
5. **Seller isolation on the server.** Every ERP table carries `seller_profile_id`; every lookup is scoped
   to the signed-in store and answers 404 for anything else. Writes are blocked for suspended or rejected stores,
   as listings already are.
6. **Additive migrations only.** New tables and nullable/defaulted columns; existing rows are kept and backfilled.

## Database changes (by phase)

| Phase | Change |
| --- | --- |
| 1 | `products`: `sku` (unique per store), `cost_price_santim`, `min_stock_level`, `reorder_quantity` |
| 1 | `product_variants` (options of a listing: size, color, material, own SKU, price, cost, stock, levels; soft deleted) |
| 1 | `cart_items.variant_id` (+ generated `variant_key`; unique per customer, listing and option) |
| 1 | `order_items.variant_id`, `variant_label` (snapshot), `unit_cost_santim` (cost at the time of sale) |
| 1 | `seller_erp_settings` (store's low-stock level, alerts on/off, SKU prefix) |
| 1 | `warehouses` (store's locations, one default), `warehouse_stock` (count per non-default location) |
| 1 | `stock_movements` (every change: type, quantity, before/after, location, linked order or document, who) |
| 2 | `suppliers`, `purchase_orders` (+ items), `purchase_receipts` (+ items), `supplier_invoices`, `supplier_payments`, `stock_transfers` (+ items) |
| 2 | `warehouses.contact_name`, `seller_erp_settings.cost_from_purchases` (default on), `products.supplier_id` (usual supplier, nullable) |
| 3 | `offline_sales`, `offline_sale_items`, `expense_categories`, `expenses` (with private attachment) |
| 4 | No new tables: reports are computed from the records above |

## Phases

1. **ERP menu, overview, inventory.** Expandable "ERP Management" section in the seller menu. Inventory list
   (search, filter, sort, pages), item page (stock by location, movement history), stock adjustments
   (count correction, damaged, returned, found), low-stock alerts, ERP settings. Checkout and cancellation
   record movements. Overview KPIs from real data only.
2. **Warehouses, suppliers, purchases.** (Done.) Locations and transfers; supplier records; purchase orders
   (draft, ordered, partly received, received, cancelled) with receipts that add stock once, supplier
   invoices and payments, balances owed, a printable purchase order.
3. **Sales, invoices, expenses.** Sales list built from marketplace orders plus offline sales, printable
   invoices and receipts, returns into stock; expenses by category with attachments.
4. **Finance and reports.** Gross sales, returns and refunds, commission, cost of goods sold, expenses,
   gross and estimated net profit, owed to suppliers, payouts; CSV exports with date ranges (Addis Ababa days).
5. **Hardening.** Cross-store access tests on every endpoint, stock reconciliation, phone layouts, deploy checks.

## Defaults picked where the request leaves room

- **Options (size, color, material) live inside a listing** (user's choice on 2026-10-17). A listing
  with options has one stock item per option; a listing without options is one stock item. Shoppers pick
  an option on the product page; the cart, checkout and the order line carry it. Adding the first option
  moves the listing's stock to it. An option with stock, or held by an open order, cannot be removed.
  Up to 30 options per listing. An order placed before options existed releases its stock to the first option.
- **Stock edits are checked for staleness.** The listing form and the stock-change form send the number the
  store saw; if an order changed it meanwhile, the save is refused and the page reloads the current number.
- **Low-stock alerts** fire once when an item drops to its level, and again at zero. Level: the option's,
  else the listing's, else the store setting (default 2). Stores can turn alerts off.
- **Profit is "estimated".** Phase 1 uses the cost price the store enters, frozen on each order line when the
  order is placed; lines without a cost are left out of gross profit and shown as a warning. Purchase
  receipts (phase 2) will keep the cost price up to date. This is operational reporting, not statutory
  accounting (no double-entry ledger or bank reconciliation).
- **Privacy:** stock history shows "Order" and the order number, never the customer's name.

### Phase 2 rules

- **Stock changes only when goods are received**, never when an order is created or sent. Each receipt
  goes through `InventoryService` (type `purchase_received`), is capped at what is still expected per line,
  locks the order row, and carries a `request_id` so a double tap or a retry saves it once.
- **Cost per unit with charges (landed cost)** = the line total plus its share of the order's charges
  minus its share of the order discount, shared by line value, divided by the quantity. With "Update cost
  prices when goods arrive" on (default), the cost price becomes the weighted average of the units already
  available and the units received; with no earlier cost or no units, it becomes the landed cost.
- **What a store owes a supplier.** An order's amount to pay is the sum of its invoices once any is recorded;
  until then it is the value of what arrived (at landed cost). Owed = all of that, plus invoices not tied to an
  order, minus payments. It can go below zero ("paid ahead") after a deposit.
- **Payment limits.** On an order still waiting for goods: up to the order total (a deposit) minus what was paid.
  On a received or closed order: only what is still owed. Without an order: only what is owed overall.
- **Nothing financial is deleted.** Invoices and payments are voided with a reason and stay in the history.
  An order with a recorded invoice or payment cannot be cancelled until those are voided. A supplier can be
  deleted only while it has no orders, invoices or payments; otherwise it is marked inactive.
- **Invoice numbers** are unique per supplier among invoices that are not voided, ignoring case.
- **Order numbers** (PO-00001, GRN-00001 for receipts, TR-00001 for transfers) run per store under a
  per-store lock, so two people saving at once never get the same number.
- **Drafts change freely; sent orders don't.** After "Mark as ordered" only the expected date, supplier's
  reference and notes can change. "Stop waiting for the rest" closes a partly received order.
- **Transfers.** Units leave the source when sent and cannot be sold while on the way. Receiving can be
  partial; units that did not arrive stay out of stock (record them as found if they turn up). Cancelling
  puts every unit back at the source. "It has already arrived" records both steps at once.
- **Locations.** Up to 20 per store. The main location cannot be closed; any other
  location closes only when empty, with no transfer on the way and no unfinished purchase order delivering there.
- **Listings with stock on the way.** A listing or option cannot be deleted, removed or switched to made to
  order while units are on an open purchase order or in a transfer. An order line written before the listing
  had options is received into its first option.
- **Upgrading.** The migration only adds tables and nullable or defaulted columns. Checked on a filled
  SQLite database (every row kept, including on rollback and re-run) and on MariaDB.
