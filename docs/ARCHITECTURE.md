# Architecture

## Overview

```
React SPA (frontend/)  --HTTPS, Bearer token-->  Laravel API (backend/, /api/*)  -->  MySQL
                                                       |
                                                       +--> public disk (product photos)
```

- The API is the only place permissions are enforced. Frontend role checks only hide navigation.
- Authentication: Laravel Sanctum personal access tokens (30-day expiry), sent as `Authorization: Bearer`. One token per sign-in; sign-out revokes it.
- Money: every amount is stored as integer **santim** (1 ETB = 100 santim) in `*_santim` columns. The API accepts and returns birr strings (`"12500.50"`) and also returns the santim integer. No floats touch stored money.

## Roles

| Role | How it is assigned | Can |
|---|---|---|
| guest | not signed in | browse published listings, storefronts, categories |
| customer | `POST /api/auth/register` | wishlist (orders, reviews, inquiries in later milestones) |
| seller | `POST /api/auth/register-seller` creates the account and a **pending** store | drafts while pending; submit listings once approved; only ever sees own records |
| admin | `php artisan marketplace:create-admin` only | approve/reject/suspend sellers, moderate listings, manage categories, audit log |
| delivery | reserved for a later milestone | |

`role` and `status` are not mass-assignable, so no API payload can change them.

## Schema (milestone 1)

```mermaid
erDiagram
    users ||--o| seller_profiles : "owns (role=seller)"
    users ||--o{ favorites : saves
    users ||--o{ audit_logs : performs
    cities ||--o{ areas : has
    cities ||--o{ seller_profiles : "located in"
    areas ||--o{ seller_profiles : "located in"
    areas ||--o{ products : "item location"
    seller_profiles ||--o{ products : lists
    categories ||--o{ categories : "parent of (max 2 levels)"
    categories ||--o{ products : classifies
    products ||--o{ product_images : has
    products ||--o{ favorites : "saved in"
```

| Table | Key columns and constraints |
|---|---|
| users | email unique (stored lowercase), role (customer/seller/admin/delivery), status (active/suspended), bcrypt password |
| cities, areas | Addis Ababa + 11 sub-cities seeded; `areas (city_id, slug)` unique. Adding a city later is data, not code |
| seller_profiles | user_id unique FK; slug unique and stable after renames; status pending/approved/rejected/suspended; reviewed_by/at; TIN optional |
| categories | slug unique, parent_id (two levels), is_active, sort_order. Admin-managed |
| products | seller_profile_id FK, category_id FK, price_santim, condition, stock_type (in_stock/made_to_order), stock_quantity, production_days, dimensions cm, material, color, delivery fields, status, moderation_note, soft deletes. Indexes on (status, category_id), (status, price_santim), (status, published_at), (seller_profile_id, status) |
| product_images | product_id FK cascade, random file names under `products/{id}/` |
| favorites | (user_id, product_id) unique |
| audit_logs | append-only: user, action, subject, JSON meta, IP |

Foreign keys use `restrict` where deleting the parent would destroy history (sellers, categories, cities) and `cascade` only for pure child rows (images, favorites).

## Business rules implemented

**Public visibility.** A listing is public only when it is `published`, not soft-deleted, its category is active, its seller is `approved`, and the seller's user account is `active`. Suspending a seller or deactivating a category hides their listings immediately without changing listing data, so reinstating restores them.

**Seller lifecycle.** pending -> approved | rejected; approved -> suspended -> approved (reinstate); rejected -> approved. Reject and suspend require a reason the seller sees. Transitions run under a row lock and are audited.

**Listing lifecycle.**
- Create as `draft` (any pending or approved seller) or submit straight to `pending_review` (approved sellers, at least one photo).
- Admin approves `pending_review` -> `published` (seller must be approved, listing must have a photo) or rejects with a note. Published listings can also be taken down with a note.
- Editing name, description, category, material or condition of a published listing sends it back to `pending_review` (`MARKETPLACE_REAPPROVE_ON_EDIT`). Price, stock and delivery edits go live immediately.
- `archived` hides a listing; resubmitting sends it to review. Delete is a soft delete so future order history keeps its product rows.
- Made-to-order items carry production days and no stock count; in-stock items carry a count and no production days. Every listing offers delivery, pickup, or both.

**Ownership.** All seller endpoints look records up through the signed-in seller's own profile, so another seller's id returns 404 (no existence leak). Tests cover show, update, delete, submit, archive, image upload and image delete across sellers.

## Security measures in place

- bcrypt password hashing; minimum 8 characters with letters and numbers (12 + symbols for admins).
- Login: constant-time path for unknown emails, 5 attempts per minute per email+IP and 20 per IP. Registration 10 per hour per IP. Uploads 30 per minute per user. Catalog 120 per minute.
- Validation on every write; LIKE search input is escaped; all queries use bindings.
- Mass assignment restricted with explicit fillable lists; strict model mode in non-production to catch silently dropped attributes and lazy loading.
- Uploads: content-checked images only (jpg, png, webp; no SVG), 5 MB, 200 to 8000 px, max 8 per listing enforced under a row lock, stored under random names.
- API 404s never reveal model names; 500s never return server messages to the UI.
- CORS limited to `CORS_ALLOWED_ORIGINS`. Bearer tokens mean CSRF does not apply to the API; there are no cookie-authenticated API routes.
- Audit log for seller applications and reviews, listing create/update/submit/archive/delete/moderation, category changes and CLI admin creation.

## Decisions and defaults (change if the business decides otherwise)

| Decision | Default chosen | Why |
|---|---|---|
| Token storage | `localStorage` | Simple and works across domains. Trade-off: an XSS bug could read the token. Moving to Sanctum cookie auth on a shared domain is the hardening step before launch |
| One role per account | seller accounts are separate from customer accounts | Simplest correct permission model; sellers can still browse and save favorites |
| Moderation on edit | content fields only | Avoids blocking sellers from updating price and stock while still catching bait-and-switch content |
| Brand name | "Betoch Furniture" via `MARKETPLACE_NAME` | Placeholder; one env change |
| Commission | 10% placeholder in config, unused yet | Real rate becomes an admin setting with the payments milestone |
| Contact seller | public store phone with call link | Works today; in-app inquiries come in milestone 2 |

## Planned design for the next milestones

**Multi-seller checkout (Stage 5).** One `checkouts` row per customer payment, containing one `orders` row per seller (each with its own status, delivery record and commission). `order_items` snapshot product name, unit price and delivery fee at purchase time. Amounts are recomputed server-side from current prices inside a DB transaction with `SELECT ... FOR UPDATE` on product rows.

**Stock.** In-stock quantity is reserved (decremented) when the order is placed, released on cancellation or payment failure/expiry, and never decremented for made-to-order items. Orders start `pending_confirmation`; the seller must confirm availability and final delivery terms before `confirmed`.

**Order states.** pending_confirmation -> confirmed -> processing -> ready_for_delivery -> dispatched -> delivered; cancelled from pre-dispatch states; returned/disputed after delivery. Every transition is written to `order_status_history`.

**Payments.** `payment_transactions` with provider, provider reference (unique), amount_santim, currency, status (pending/succeeded/failed/refunded) and raw callback. Callbacks are verified server-side with the provider, amounts and currency are checked against the checkout, and the unique provider reference plus an idempotency check prevents double processing. A clearly labeled development driver exists only outside production. Cash on delivery is a config flag.

**Commission and settlements.** `commission_records` per seller order store the rate and amount used at the time. `seller_settlements` group delivered, non-disputed orders into payouts. Reports separate GMV, platform revenue (commission + fees), seller payouts, operating costs and profit; total sales are never labeled profit.

**Also planned:** inquiries/conversations (Method B, quotes for custom work), customer addresses, delivery zones and fees per area, reviews for delivered orders, disputes, admin user management and reports.

## Roadmap

| Stage | Scope | Status |
|---|---|---|
| 1 | Repository inspection | Done: repository was empty |
| 2 | Foundation: schema, auth, roles, seeders, API client, shared UI | Done |
| 3 | Catalog: home, categories, search, filters, sorting, pagination, product page, storefronts, wishlist | Done. Inquiries move to milestone 2 |
| 4 | Seller: application, approval, dashboard, listings, photos, stock | Done. Orders and finance views come with stage 5 |
| 6 (part) | Admin: seller approval, listing moderation, categories, audit log, counts | Done |
| 2 next | Inquiries and custom quotes, customer addresses, delivery zones | Next |
| 5 | Cart, checkout, multi-seller orders, stock reservation, COD and payment provider integration | Planned |
| 6 rest | Orders, disputes, commission settings, settlements, reports, user management | Planned |
| 7 | CI, end-to-end tests, deployment scripts, backups automation | Planned |
