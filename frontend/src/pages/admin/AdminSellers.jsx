import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Alert, EmptyState, ErrorState, PageHeader, Pagination, Spinner, StatusBadge } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { BUSINESS_TYPES, toQuery } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'

const ACTIONS = {
  pending: [['approve', 'Approve', false], ['reject', 'Reject', true]],
  approved: [['suspend', 'Suspend', true]],
  suspended: [['reinstate', 'Reinstate', false]],
  rejected: [['approve', 'Approve', false]],
}

export default function AdminSellers() {
  const [params, setParams] = useSearchParams()
  const status = params.get('status') || ''
  const [search, setSearch] = useState(params.get('q') || '')
  const list = useFetch(`/admin/sellers?${toQuery({ status, q: params.get('q') || '', page: params.get('page') || '' })}`)
  const [notice, setNotice] = useState(null)

  async function review(seller, action, needsReason) {
    let reason = null
    if (needsReason) {
      reason = window.prompt(`Reason to ${action} ${seller.store_name} (shown to the seller):`)
      if (!reason?.trim()) return
    }
    try {
      await api.post(`/admin/sellers/${seller.id}/${action}`, { reason })
      setNotice({ tone: 'success', text: `${seller.store_name}: ${action} done.` })
      list.reload()
    } catch (err) {
      const e = parseError(err)
      setNotice({ tone: 'error', text: Object.values(e.fields)[0] || e.message })
    }
  }

  return (
    <>
      <PageHeader title="Sellers" />
      <div className="mb-4 flex flex-col gap-2 sm:flex-row">
        <select className="input sm:w-56" value={status} onChange={(e) => setParams(toQuery({ status: e.target.value, q: params.get('q') || '' }))} aria-label="Status">
          <option value="">All statuses</option>
          <option value="pending">Pending</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
          <option value="suspended">Suspended</option>
        </select>
        <form className="flex flex-1 gap-2" onSubmit={(e) => { e.preventDefault(); setParams(toQuery({ status, q: search })) }}>
          <input className="input" placeholder="Search store name or phone" value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Search sellers" />
          <button className="btn-secondary" type="submit">Search</button>
        </form>
      </div>
      {notice && <div className="mb-4"><Alert tone={notice.tone}>{notice.text}</Alert></div>}
      {list.loading && <Spinner />}
      {list.error && <ErrorState error={list.error} onRetry={list.reload} />}
      {list.data && (list.data.data.length ? (
        <>
          <ul className="space-y-3">
            {list.data.data.map((s) => (
              <li key={s.id} className="card flex flex-col gap-3 p-4 md:flex-row md:items-start">
                <div className="min-w-0 flex-1 text-sm">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="text-base font-semibold text-stone-900">{s.store_name}</p>
                    <StatusBadge status={s.status} />
                  </div>
                  <p className="text-stone-600">{BUSINESS_TYPES[s.business_type]} in {s.area || 'unknown area'} · {s.phone} · {s.products_count} listings</p>
                  <p className="text-stone-600">Owner: {s.owner?.name} ({s.owner?.email}){s.tin_number ? ` · TIN ${s.tin_number}` : ''}</p>
                  {s.address_line && <p className="text-stone-600">Address: {s.address_line}</p>}
                  {s.description && <p className="mt-1 text-stone-700">{s.description}</p>}
                  {s.status_reason && <p className="mt-1 text-red-700">Reason: {s.status_reason}</p>}
                  <p className="mt-1 text-xs text-stone-500">Applied {new Date(s.created_at).toLocaleDateString()}</p>
                </div>
                <div className="flex shrink-0 gap-2">
                  {(ACTIONS[s.status] || []).map(([action, label, needsReason]) => (
                    <button key={action} type="button" onClick={() => review(s, action, needsReason)}
                      className={['reject', 'suspend'].includes(action) ? 'btn-secondary text-red-700' : 'btn-primary'}>
                      {label}
                    </button>
                  ))}
                </div>
              </li>
            ))}
          </ul>
          <Pagination meta={list.data.meta} onPage={(p) => setParams(toQuery({ status, q: params.get('q') || '', page: p }))} />
        </>
      ) : <EmptyState title="No sellers match" />)}
    </>
  )
}
