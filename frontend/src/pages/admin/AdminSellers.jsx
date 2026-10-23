import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Alert, EmptyState, ErrorState, Field, PageHeader, Pagination, Spinner, StatusBadge } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { BUSINESS_TYPES, toQuery } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'

const ACTIONS = {
  pending: [['approve', 'Approve', false], ['reject', 'Reject', true]],
  approved: [['suspend', 'Suspend', true]],
  suspended: [['reinstate', 'Reinstate', false]],
  rejected: [['approve', 'Approve', false]],
}

/**
 * The store's checked identity. Stores cannot change their name or TIN after approval, so the
 * marketplace team corrects them here; every change goes to the audit log.
 */
function DetailsForm({ seller, onSaved, onCancel }) {
  const [values, setValues] = useState({ store_name: seller.store_name, tin_number: seller.tin_number ?? '', business_type: seller.business_type, vat_registered: !!seller.vat_registered, vat_number: seller.vat_number ?? '' })
  const [errors, setErrors] = useState({})
  const [busy, setBusy] = useState(false)
  const bind = (name) => ({ id: `${name}-${seller.id}`, value: values[name], onChange: (e) => setValues((v) => ({ ...v, [name]: e.target.value })), 'aria-invalid': errors[name] ? true : undefined })

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    try {
      await api.put(`/admin/sellers/${seller.id}`, values)
      onSaved()
    } catch (err) {
      const p = parseError(err)
      setErrors(Object.keys(p.fields).length ? p.fields : { store_name: p.message })
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="mt-3 grid gap-3 rounded-xl bg-stone-50 p-3 sm:grid-cols-3" noValidate>
      <Field label="Store name" name={`store_name-${seller.id}`} error={errors.store_name}><input className="input" {...bind('store_name')} /></Field>
      <Field label="TIN number" name={`tin_number-${seller.id}`} error={errors.tin_number}><input className="input" {...bind('tin_number')} /></Field>
      <Field label="Kind of business" name={`business_type-${seller.id}`} error={errors.business_type}>
        <select className="input" {...bind('business_type')}>
          {Object.entries(BUSINESS_TYPES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
      </Field>
      <label className="flex items-center gap-2 text-sm sm:col-span-1 sm:self-end sm:pb-2">
        <input type="checkbox" className="size-4 accent-brand-700" checked={values.vat_registered} onChange={(e) => setValues((v) => ({ ...v, vat_registered: e.target.checked }))} />
        Registered for VAT
      </label>
      {values.vat_registered && (
        <Field label="VAT registration number" name={`vat_number-${seller.id}`} error={errors.vat_number}><input className="input" {...bind('vat_number')} /></Field>
      )}
      <p className="text-xs text-stone-500 sm:col-span-3">The store page keeps its web address after a rename. Check the VAT certificate before marking a store as registered: its prices then include VAT on receipts.</p>
      <div className="flex gap-2 sm:col-span-3">
        <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Saving...' : 'Save details'}</button>
        <button type="button" className="btn-ghost" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  )
}

export default function AdminSellers() {
  const [params, setParams] = useSearchParams()
  const status = params.get('status') || ''
  const [search, setSearch] = useState(params.get('q') || '')
  const list = useFetch(`/admin/sellers?${toQuery({ status, q: params.get('q') || '', page: params.get('page') || '' })}`)
  const [notice, setNotice] = useState(null)
  const [editing, setEditing] = useState(null)

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
          <input className="input min-w-0" placeholder="Search store name or phone" value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Search sellers" />
          <button className="btn-secondary" type="submit">Search</button>
        </form>
      </div>
      {notice && <div className="mb-4"><Alert tone={notice.tone}>{notice.text}</Alert></div>}
      {list.loading && <Spinner />}
      {list.error && <ErrorState error={list.error} onRetry={list.reload} />}
      {list.data && (list.data.data.length ? (
        <>
          <ul className="card divide-y divide-stone-100">
            {list.data.data.map((s) => (
              <li key={s.id} className="flex flex-col gap-3 px-4 py-3 md:flex-row md:items-start">
                <div className="min-w-0 flex-1 text-sm">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="font-semibold text-stone-900">{s.store_name}</p>
                    <StatusBadge status={s.status} />
                  </div>
                  <p className="text-stone-600">{BUSINESS_TYPES[s.business_type]} in {s.area || 'unknown area'} · {s.phone} · {s.products_count} listings</p>
                  <p className="text-stone-600">Owner: {s.owner?.name} ({s.owner?.email}){s.tin_number ? ` · TIN ${s.tin_number}` : ''}{s.vat_registered ? ` · VAT ${s.vat_number}` : ''} · applied {new Date(s.created_at).toLocaleDateString()}</p>
                  {/* Address and description help decide an application; approved stores show them on their page. */}
                  {s.status === 'pending' && s.address_line && <p className="text-stone-600">Address: {s.address_line}</p>}
                  {s.status === 'pending' && s.description && <p className="mt-1 text-stone-700">{s.description}</p>}
                  {s.status_reason && <p className="mt-1 text-red-700">Reason: {s.status_reason}</p>}
                  {editing === s.id && (
                    <DetailsForm seller={s} onCancel={() => setEditing(null)}
                      onSaved={() => { setEditing(null); setNotice({ tone: 'success', text: 'Store details saved.' }); list.reload() }} />
                  )}
                </div>
                <div className="flex shrink-0 flex-wrap gap-2">
                  {editing !== s.id && (
                    <button type="button" className="btn-secondary" onClick={() => setEditing(s.id)}>Edit details</button>
                  )}
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
