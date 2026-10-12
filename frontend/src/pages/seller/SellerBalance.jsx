import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Alert, ErrorState, PageHeader, Pagination, Spinner, Stats } from '../../components/ui'
import { ENTRY_LABELS, METHOD_LABELS, formatDateTime, formatETB } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'


/**
 * Statement rows shared by the seller and admin views. Positive amounts are owed to the platform.
 */
export function LedgerTable({ entries, admin = false }) {
  if (!entries.length) return <p className="text-sm text-stone-500">Nothing yet. Entries are added when an order is marked delivered.</p>
  // A list rather than a table so the amount never scrolls out of sight on a phone.
  return (
    <ul className="divide-y divide-stone-100 text-sm">
      {entries.map((e) => (
        <li key={e.id} className="flex items-start justify-between gap-3 py-2">
          <div className="min-w-0">
            <p className="text-stone-900">
              <span className="font-medium">{ENTRY_LABELS[e.type]}</span>
              {e.order && <> · <Link to={`${admin ? '/admin' : '/seller'}/orders/${e.order.id}`} className="text-brand-700 hover:underline">{e.order.number}</Link></>}
            </p>
            <p className="text-xs break-words text-stone-500">
              {formatDateTime(e.created_at)}
              {e.method && ` · ${METHOD_LABELS[e.method]}${e.reference ? ` · ${e.reference}` : ''}`}
              {admin && e.recorded_by && ` · by ${e.recorded_by}`}
            </p>
            {e.note && !e.order && <p className="text-xs break-words text-stone-600">{e.note}</p>}
          </div>
          <p className={`shrink-0 font-medium whitespace-nowrap ${e.amount_santim < 0 ? 'text-emerald-700' : 'text-stone-900'}`}>
            {e.amount_santim < 0 ? `- ${formatETB(-e.amount_santim)}` : formatETB(e.amount_santim)}
          </p>
        </li>
      ))}
    </ul>
  )
}

export function BalanceCards({ b, admin = false }) {
  const online = b.online_sales_total_santim > 0 || b.payouts_total_santim > 0
  return (
    <Stats items={[
      {
        label: b.balance_santim < 0 ? (admin ? 'Marketplace owes the store' : 'The marketplace owes you') : 'Owed to the marketplace',
        value: formatETB(Math.abs(b.balance_santim)) || 'ETB 0',
        hint: b.credit_limit_santim ? `Limit ${formatETB(b.credit_limit_santim)}` : undefined,
        tone: b.over_limit ? 'bad' : undefined,
      },
      { label: 'Commission charged', value: formatETB(b.commission_total_santim) || 'ETB 0' },
      { label: 'Paid so far', value: formatETB(b.paid_total_santim) || 'ETB 0' },
      { label: 'Adjustments', value: `${b.adjustments_total_santim < 0 ? '- ' : ''}${formatETB(Math.abs(b.adjustments_total_santim)) || 'ETB 0'}` },
      online && { label: 'Online sales held', value: formatETB(b.online_sales_total_santim) || 'ETB 0' },
      online && { label: admin ? 'Paid out to the store' : 'Paid out to you', value: formatETB(b.payouts_total_santim) || 'ETB 0' },
    ]} />
  )
}

export default function SellerBalance() {
  const balance = useFetch('/seller/balance')
  const [page, setPage] = useState(1)
  const entries = useFetch(`/seller/balance/entries?page=${page}`)

  if (balance.loading && !balance.data) return <Spinner />
  if (balance.error) return <div className="container-page py-10"><ErrorState error={balance.error} /></div>
  const b = balance.data.data

  return (
    <div className="container-page py-8">
      <PageHeader title="Balance" subtitle="Cash orders: you collect the money and owe the marketplace its commission. Online orders: the marketplace holds the payment and owes you the sale minus commission once delivered." actions={<Link to="/seller/orders" className="btn-secondary">Orders</Link>} />
      {b.over_limit && <div className="mb-4"><Alert tone="error">Your unpaid commission is over the limit, so you cannot confirm new cash orders. Orders paid online still go ahead. Pay your balance and the marketplace team will record it.</Alert></div>}
      {b.owed_to_seller_santim > 0 && <div className="mb-4"><Alert tone="success">The marketplace owes you {formatETB(b.owed_to_seller_santim)} from online sales. The team sends it to you and records each payout here.</Alert></div>}
      <BalanceCards b={b} />
      <div className="card mt-4 p-4 text-sm">
        <h2 className="font-semibold text-stone-900">How to pay commission</h2>
        <p className="mt-1 whitespace-pre-line text-stone-700">{b.instructions || 'Contact the marketplace team to arrange payment.'}</p>
        <p className="mt-2 text-xs text-stone-500">Payments show here once the team records them. Keep your transfer reference.</p>
      </div>
      <section className="card mt-6 p-4">
        <h2 className="mb-2 font-semibold text-stone-900">Statement</h2>
        {entries.loading && !entries.data ? <Spinner /> : entries.error ? <ErrorState error={entries.error} /> : (
          <>
            <LedgerTable entries={entries.data.data} />
            <Pagination meta={entries.data.meta} onPage={setPage} />
          </>
        )}
      </section>
    </div>
  )
}
