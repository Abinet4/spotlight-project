import { Link } from 'react-router-dom'
import { ErrorState, PageHeader, Spinner } from '../../components/ui'
import { STATUS_LABELS } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'

function Stat({ label, value, to, highlight }) {
  const body = (
    <div className={`card h-full p-4 ${highlight ? 'border-amber-300 bg-amber-50' : ''}`}>
      <p className="text-sm text-stone-600">{label}</p>
      <p className="mt-1 text-2xl font-bold text-stone-900">{value}</p>
    </div>
  )
  return to ? <Link to={to} className="block hover:opacity-90">{body}</Link> : body
}

export default function AdminOverview() {
  const { data, error, loading, reload } = useFetch('/admin/summary')
  if (loading) return <Spinner />
  if (error) return <ErrorState error={error} onRetry={reload} />
  const s = data.data

  return (
    <>
      <PageHeader title="Marketplace overview" />
      <h2 className="mb-3 text-sm font-bold tracking-wide text-stone-500 uppercase">Needs attention</h2>
      <div className="mb-8 grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat label="Seller applications" value={s.sellers.pending} to="/admin/sellers?status=pending" highlight={s.sellers.pending > 0} />
        <Stat label="Listings to review" value={s.products.pending_review} to="/admin/listings" highlight={s.products.pending_review > 0} />
      </div>
      <h2 className="mb-3 text-sm font-bold tracking-wide text-stone-500 uppercase">Accounts</h2>
      <div className="mb-8 grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat label="Customers" value={s.customers} />
        {Object.entries(s.sellers).map(([k, v]) => <Stat key={k} label={`Sellers: ${STATUS_LABELS[k]}`} value={v} to={`/admin/sellers?status=${k}`} />)}
      </div>
      <h2 className="mb-3 text-sm font-bold tracking-wide text-stone-500 uppercase">Listings</h2>
      <div className="mb-8 grid grid-cols-2 gap-3 md:grid-cols-5">
        {Object.entries(s.products).map(([k, v]) => <Stat key={k} label={STATUS_LABELS[k]} value={v} to={`/admin/listings?status=${k}`} />)}
      </div>
      <p className="text-sm text-stone-500">
        Orders, gross merchandise value, platform revenue and seller payouts appear here once checkout is live. They are tracked separately so sales are never reported as profit.
      </p>
    </>
  )
}
