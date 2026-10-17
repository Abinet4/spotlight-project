import { Link } from 'react-router-dom'
import { ErrorState, PageHeader, Spinner, Stats } from '../../../components/ui'
import { formatDateTime } from '../../../lib/format'
import { useFetch } from '../../../lib/useFetch'
import { Change, DayBars, MOVEMENT_LABELS, PeriodPicker, StockBadge, money, usePeriod } from './shared'

const ACTIVITY = {
  'inventory.adjusted': 'Recorded a stock change',
  'inventory.item_updated': 'Changed SKU, cost or stock levels',
  'inventory.sku_generated': 'Created SKUs',
  'inventory.exported': 'Downloaded the inventory',
  'erp.settings_updated': 'Changed ERP settings',
  'product.created': 'Added a listing',
  'product.updated': 'Edited a listing',
  'product.submitted': 'Sent a listing for review',
  'product.archived': 'Archived a listing',
  'product.deleted': 'Deleted a listing',
  'product.variant_added': 'Added an option',
  'product.variant_updated': 'Changed an option',
  'product.variant_removed': 'Removed an option',
}

export default function ErpOverview() {
  const period = usePeriod('30d')
  const res = useFetch(`/seller/erp/overview?${period.query}`)
  const d = res.data?.data

  return (
    <>
      <PageHeader title="ERP overview" subtitle="Stock as of now; sales for the period you pick (Addis Ababa days)." actions={<PeriodPicker value={period} />} />
      {res.loading && !d ? <Spinner /> : res.error ? <ErrorState error={res.error} onRetry={res.reload} /> : (
        <>
          <h2 className="mb-2 text-sm font-semibold text-stone-500">Stock now</h2>
          <Stats items={[
            { label: 'Listings', value: d.stock.listings, hint: d.stock.items !== d.stock.listings ? `${d.stock.items} stock items with options` : undefined, to: '/seller/erp/inventory' },
            { label: 'Units available', value: d.stock.available, hint: d.stock.held ? `plus ${d.stock.held} held for open orders` : undefined },
            { label: 'Stock value at cost', value: money(d.stock.value_cost_santim), hint: d.stock.missing_cost ? `${d.stock.missing_cost} items have no cost price` : 'available plus held units', tone: d.stock.missing_cost ? 'warn' : undefined, to: d.stock.missing_cost ? '/seller/erp/inventory' : undefined },
            { label: 'Stock value at selling price', value: money(d.stock.value_price_santim) },
            { label: 'Low stock', value: d.stock.low, tone: d.stock.low ? 'warn' : undefined, to: d.stock.low ? '/seller/erp/inventory?stock=low' : undefined },
            { label: 'Out of stock', value: d.stock.out, tone: d.stock.out ? 'bad' : undefined, to: d.stock.out ? '/seller/erp/inventory?stock=out' : undefined },
          ]} />

          <h2 className="mt-6 mb-2 text-sm font-semibold text-stone-500">Marketplace sales, {d.range.from === d.range.to ? d.range.from : `${d.range.from} to ${d.range.to}`}</h2>
          <Stats items={[
            { label: 'Item sales (delivered)', value: money(d.sales.marketplace.items_santim), hint: `${d.sales.marketplace.orders} orders` },
            { label: 'Cost of goods sold', value: money(d.sales.marketplace.cost_of_goods_santim), hint: d.sales.marketplace.lines_without_cost ? `${d.sales.marketplace.lines_without_cost} lines without a cost price` : undefined, tone: d.sales.marketplace.lines_without_cost ? 'warn' : undefined },
            { label: 'Gross profit (estimate)', value: money(d.sales.marketplace.gross_profit_santim), hint: d.sales.marketplace.sales_without_cost_santim ? `leaves out ${money(d.sales.marketplace.sales_without_cost_santim)} of sales with no cost` : 'item sales minus their cost' },
            { label: 'Commission', value: money(d.sales.marketplace.commission_santim) },
            { label: 'Delivery fees charged', value: money(d.sales.marketplace.delivery_santim) },
            { label: 'Open orders', value: d.orders.open, hint: d.orders.pending_confirmation ? `${d.orders.pending_confirmation} waiting for you` : undefined, to: '/seller/orders' },
          ]} />
          <p className="mt-2 text-xs text-stone-500">Each order counts once, on the day it was delivered. Profit uses the cost prices you entered; it is an estimate, not accounting.</p>

          <div className="mt-5 grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-2">
            <section className="card p-4">
              <h2 className="mb-3 font-semibold text-stone-900">Item sales by day</h2>
              <DayBars days={d.sales.days.map((x) => ({ date: x.date, value: x.items_santim }))} format={money} label="Item sales by day" />
            </section>

            <section className="card p-4">
              <h2 className="mb-3 font-semibold text-stone-900">Best sellers</h2>
              {d.sales.top_products.length === 0 ? <p className="text-sm text-stone-500">No deliveries in this period.</p> : (
                <ol className="divide-y divide-stone-100 text-sm">
                  {d.sales.top_products.map((t) => (
                    <li key={`${t.product_id}-${t.variant_id}`} className="flex items-center justify-between gap-3 py-2">
                      <Link to={`/seller/erp/inventory/${t.product_id}`} className="min-w-0 flex-1 truncate font-medium text-stone-900 hover:underline">
                        {t.name}{t.label && <span className="text-stone-500"> ({t.label})</span>}
                      </Link>
                      <span className="text-stone-600 tabular-nums">{t.units} sold</span>
                      <span className="w-28 text-right font-semibold tabular-nums">{money(t.revenue_santim)}</span>
                    </li>
                  ))}
                </ol>
              )}
            </section>

            <section className="card p-4">
              <div className="mb-3 flex items-center justify-between"><h2 className="font-semibold text-stone-900">Needs restocking</h2><Link to="/seller/erp/inventory?stock=low" className="text-sm font-semibold text-brand-700 hover:underline">All</Link></div>
              {d.stock.alerts.length === 0 ? <p className="text-sm text-stone-500">Everything is above its low-stock level.</p> : (
                <ul className="divide-y divide-stone-100 text-sm">
                  {d.stock.alerts.map((a) => (
                    <li key={`${a.product_id}-${a.variant_id}`} className="flex items-center justify-between gap-3 py-2">
                      <Link to={`/seller/erp/inventory/${a.product_id}`} className="min-w-0 flex-1 truncate font-medium text-stone-900 hover:underline">
                        {a.name}{a.label && <span className="text-stone-500"> ({a.label})</span>}
                      </Link>
                      <span className="text-stone-600 tabular-nums">{a.available} left{a.reorder_quantity ? `, reorder ${a.reorder_quantity}` : ''}</span>
                      <StockBadge state={a.state} />
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <section className="card p-4">
              <div className="mb-3 flex items-center justify-between"><h2 className="font-semibold text-stone-900">Latest stock changes</h2><Link to="/seller/erp/movements" className="text-sm font-semibold text-brand-700 hover:underline">History</Link></div>
              {d.recent_movements.length === 0 ? <p className="text-sm text-stone-500">No stock changes yet.</p> : (
                <ul className="divide-y divide-stone-100 text-sm">
                  {d.recent_movements.map((m) => (
                    <li key={m.id} className="flex items-center justify-between gap-3 py-2">
                      <span className="min-w-0 flex-1">
                        <span className="block truncate font-medium text-stone-900">{m.product?.name}{m.variant && <span className="text-stone-500"> ({m.variant})</span>}</span>
                        <span className="text-xs text-stone-500">{m.note || MOVEMENT_LABELS[m.type] || m.type}{m.order && ` · ${m.order.number}`} · {formatDateTime(m.created_at)}</span>
                      </span>
                      <Change n={m.quantity} />
                    </li>
                  ))}
                </ul>
              )}
            </section>
          </div>

          {d.activity.length > 0 && (
            <section className="card mt-5 p-4">
              <h2 className="mb-3 font-semibold text-stone-900">Your recent activity</h2>
              <ul className="divide-y divide-stone-100 text-sm">
                {d.activity.map((a) => (
                  <li key={a.id} className="flex justify-between gap-3 py-2"><span>{ACTIVITY[a.action] || a.action}</span><span className="text-stone-500">{formatDateTime(a.created_at)}</span></li>
                ))}
              </ul>
            </section>
          )}
        </>
      )}
    </>
  )
}
