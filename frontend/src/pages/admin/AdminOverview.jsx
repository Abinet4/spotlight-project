import { CheckCircle2, ChevronRight } from 'lucide-react'
import { Link, useOutletContext } from 'react-router-dom'
import { ErrorState, Spinner, Stats } from '../../components/ui'
import { ORDER_STATUS_LABELS, STATUS_LABELS, formatETB } from '../../lib/format'

const money = (s) => formatETB(s) || 'ETB 0'

/** One line of counts, leaving out the zeros: "Waiting for seller 10 · Delivered 25". */
function CountLine({ label, counts, labels, link }) {
  const shown = Object.entries(counts).filter(([, n]) => n > 0)
  return (
    <div className="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:gap-4">
      <p className="w-20 shrink-0 text-sm font-medium text-stone-900">{label}</p>
      <p className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-stone-600">
        {shown.length === 0 ? <span>None yet</span> : shown.map(([k, n]) => (
          <Link key={k} to={link(k)} className="hover:text-brand-800 hover:underline">{labels[k] ?? k} <span className="font-semibold text-stone-900">{n}</span></Link>
        ))}
      </p>
    </div>
  )
}

export default function AdminOverview() {
  // The layout already loads /admin/summary for its menu counts; reuse it.
  const { data, error, reload } = useOutletContext()
  if (error && !data) return <ErrorState error={error} onRetry={reload} />
  if (!data) return <Spinner />
  const s = data.data

  // Only what needs someone now; zero counts are left out.
  const todo = [
    [s.sellers.pending, 'store application', 'store applications', '/admin/sellers?status=pending'],
    [s.products.pending_review, 'listing to review', 'listings to review', '/admin/listings'],
    [s.refunds_due, 'refund to send', 'refunds to send', '/admin/payments'],
    [s.reviews_low, 'low rating in the last 30 days', 'low ratings in the last 30 days', '/admin/reviews?filter=low'],
    [s.orders.pending_confirmation, 'order waiting for its store to confirm', 'orders waiting for their stores to confirm', '/admin/orders?status=pending_confirmation'],
  ].filter(([n]) => n > 0)

  return (
    <>
      <h1 className="mb-4 text-2xl font-bold tracking-tight text-stone-900">Overview</h1>

      <section aria-labelledby="todo" className="mb-6">
        <h2 id="todo" className="mb-2 text-sm font-semibold text-stone-500">To do</h2>
        {todo.length === 0 ? (
          <p className="card flex items-center gap-2 px-4 py-3 text-sm text-stone-700"><CheckCircle2 className="size-4 text-emerald-600" aria-hidden="true" />Nothing needs you right now.</p>
        ) : (
          <ul className="card divide-y divide-stone-100">
            {todo.map(([n, one, many, to]) => (
              <li key={to}>
                <Link to={to} className="flex items-center gap-3 px-4 py-2.5 text-sm hover:bg-stone-50">
                  <span className="min-w-8 rounded-full bg-amber-100 px-2 py-0.5 text-center font-semibold text-amber-800">{n}</span>
                  <span className="flex-1 text-stone-800">{n === 1 ? one : many}</span>
                  <ChevronRight className="size-4 text-stone-400" aria-hidden="true" />
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section aria-labelledby="money" className="mb-6">
        <h2 id="money" className="mb-2 text-sm font-semibold text-stone-500">Money <span className="font-normal">(delivered orders; commission is revenue, not profit)</span></h2>
        <Stats items={[
          { label: 'Gross sales', value: money(s.money.delivered_gross_santim) },
          { label: 'Commission earned', value: money(s.money.commission_earned_santim), hint: `at ${s.money.commission_rate_percent}% now`, to: '/admin/settings' },
          { label: 'Stores owe commission', value: money(s.money.commission_outstanding_santim), to: '/admin/settlements?owing=1', tone: s.money.commission_outstanding_santim > 0 ? 'warn' : undefined },
          { label: 'Open orders', value: money(s.money.open_orders_value_santim) },
        ]} />
      </section>

      <section aria-labelledby="glance">
        <h2 id="glance" className="mb-2 text-sm font-semibold text-stone-500">At a glance</h2>
        <div className="card divide-y divide-stone-100">
          <CountLine label="Orders" counts={s.orders} labels={ORDER_STATUS_LABELS} link={(k) => `/admin/orders?status=${k}`} />
          <CountLine label="Listings" counts={s.products} labels={STATUS_LABELS} link={(k) => `/admin/listings?status=${k}`} />
          <CountLine label="Stores" counts={s.sellers} labels={STATUS_LABELS} link={(k) => `/admin/sellers?status=${k}`} />
          <div className="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:gap-4">
            <p className="w-20 shrink-0 text-sm font-medium text-stone-900">Customers</p>
            <Link to="/admin/users?role=customer" className="text-sm text-stone-600 hover:text-brand-800 hover:underline"><span className="font-semibold text-stone-900">{s.customers}</span> accounts</Link>
          </div>
        </div>
      </section>
    </>
  )
}
