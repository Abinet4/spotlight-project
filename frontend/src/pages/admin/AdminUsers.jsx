import { useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { Alert, EmptyState, ErrorState, PageHeader, Pagination, Spinner, StatusBadge } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { formatDateTime, formatETB, toQuery } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'

const ROLE_LABELS = { customer: 'Customer', seller: 'Seller', admin: 'Admin' }

export function AdminUsers() {
  const [params, setParams] = useSearchParams()
  const role = params.get('role') || ''
  const status = params.get('status') || ''
  const q = params.get('q') || ''
  const [term, setTerm] = useState(q)
  const list = useFetch(`/admin/users?${toQuery({ role, status, q, page: params.get('page') || '' })}`)

  return (
    <>
      <PageHeader title="Users" />
      <form className="mb-4 flex flex-wrap gap-2" onSubmit={(e) => { e.preventDefault(); setParams(toQuery({ role, status, q: term.trim() })) }}>
        <label htmlFor="user-role" className="sr-only">Role</label>
        <select id="user-role" className="input w-auto" value={role} onChange={(e) => setParams(toQuery({ role: e.target.value, status, q }))}>
          <option value="">All roles</option>
          {Object.entries(ROLE_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
        <label htmlFor="user-status" className="sr-only">Status</label>
        <select id="user-status" className="input w-auto" value={status} onChange={(e) => setParams(toQuery({ role, status: e.target.value, q }))}>
          <option value="">Any status</option>
          <option value="active">Active</option>
          <option value="suspended">Suspended</option>
        </select>
        <label htmlFor="user-q" className="sr-only">Search</label>
        <input id="user-q" className="input min-w-[12rem] flex-1 sm:max-w-xs" placeholder="Name, email or phone" value={term} onChange={(e) => setTerm(e.target.value)} />
        <button type="submit" className="btn-secondary">Search</button>
      </form>
      {list.loading && !list.data ? <Spinner /> : list.error ? <ErrorState error={list.error} /> : list.data.data.length === 0 ? <EmptyState title="No accounts found" /> : (
        <>
          <ul className="card divide-y divide-stone-100">
            {list.data.data.map((u) => (
              <li key={u.id}>
                <Link to={`/admin/users/${u.id}`} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 hover:bg-stone-50">
                  <span className="min-w-0">
                    <span className="block truncate font-medium text-stone-900">{u.name}</span>
                    <span className="block truncate text-xs text-stone-500">{u.email}{u.store && ` · ${u.store.store_name}`}</span>
                  </span>
                  <span className="flex items-center gap-2 text-xs">
                    <span className="rounded bg-stone-100 px-1.5 py-0.5 font-medium">{ROLE_LABELS[u.role]}</span>
                    {u.status === 'suspended' && <StatusBadge status="suspended" />}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
          <Pagination meta={list.data.meta} onPage={(p) => setParams(toQuery({ role, status, q, page: String(p) }))} />
        </>
      )}
    </>
  )
}

export function AdminUserDetail() {
  const { id } = useParams()
  const res = useFetch(`/admin/users/${id}`)
  const [notice, setNotice] = useState(null)

  if (res.loading && !res.data) return <Spinner />
  if (res.error) return <ErrorState error={res.error} />
  const u = res.data.data

  async function setStatus(action) {
    let reason = null
    if (action === 'suspend') {
      reason = window.prompt(`Why suspend ${u.name}? They are signed out right away. (kept in the audit log)`)
      if (!reason?.trim()) return
    }
    try {
      await api.post(`/admin/users/${u.id}/${action}`, { reason })
      setNotice({ tone: 'success', text: action === 'suspend' ? 'Account suspended and signed out.' : 'Account reactivated.' })
      res.reload()
    } catch (err) {
      const p = parseError(err)
      setNotice({ tone: 'error', text: Object.values(p.fields)[0] || p.message })
    }
  }

  return (
    <>
      <Link to="/admin/users" className="text-sm text-brand-700 hover:underline">Back to users</Link>
      <PageHeader title={u.name} subtitle={`${ROLE_LABELS[u.role]} since ${formatDateTime(u.created_at)}`} />
      {notice && <div className="mb-4"><Alert tone={notice.tone}>{notice.text}</Alert></div>}
      <div className="grid gap-4 md:grid-cols-2">
        <section className="card space-y-1 p-4 text-sm">
          <p><span className="text-stone-500">Email:</span> {u.email}</p>
          <p><span className="text-stone-500">Phone:</span> {u.phone || 'none'}</p>
          <p><span className="text-stone-500">Status:</span> <StatusBadge status={u.status} /></p>
          {u.store && <p><span className="text-stone-500">Store:</span> <Link to={`/admin/sellers?q=${encodeURIComponent(u.store.store_name)}`} className="text-brand-700 hover:underline">{u.store.store_name}</Link> <StatusBadge status={u.store.status} /></p>}
          {u.role !== 'admin' && (
            <div className="pt-3">
              {u.status === 'active'
                ? <button type="button" className="btn-danger" onClick={() => setStatus('suspend')}>Suspend account</button>
                : <button type="button" className="btn-primary" onClick={() => setStatus('reactivate')}>Reactivate account</button>}
              {u.role === 'seller' && u.status === 'active' && <p className="mt-2 text-xs text-stone-500">To pause only the store, suspend it from Sellers instead. Suspending the account also signs the owner out.</p>}
            </div>
          )}
        </section>
        {u.role === 'customer' && (
          <section className="card space-y-1 p-4 text-sm">
            <h2 className="font-semibold text-stone-900">As a customer</h2>
            <p>{u.orders.count} orders, {u.orders.delivered_count} delivered</p>
            <p>Spent on delivered orders: {formatETB(u.orders.delivered_spend_santim) || 'ETB 0'}</p>
            <p>Cancelled by them: {u.orders.cancelled_by_customer}</p>
            <p>Reviews written: {u.reviews_count}</p>
          </section>
        )}
      </div>
    </>
  )
}
