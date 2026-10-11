import { useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { Alert, EmptyState, ErrorState, Field, PageHeader, Pagination, Spinner, StatusBadge } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { METHOD_LABELS, formatETB, toQuery } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'
import { BalanceCards, LedgerTable } from '../seller/SellerBalance'

export function AdminSettlements() {
  const [params, setParams] = useSearchParams()
  const owing = params.get('owing') === '1'
  const q = params.get('q') || ''
  const [term, setTerm] = useState(q)
  const list = useFetch(`/admin/settlements?${toQuery({ owing: owing ? 1 : '', q, page: params.get('page') || '' })}`)

  return (
    <>
      <PageHeader title="Settlements" subtitle="Sellers collect cash from customers and owe the marketplace its commission. Record their payments here." />
      {list.data && (
        <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3">
          <div className="card p-4"><p className="text-sm text-stone-600">Outstanding</p><p className="text-2xl font-bold">{formatETB(list.data.meta.outstanding_santim)}</p></div>
          <div className="card p-4"><p className="text-sm text-stone-600">Collected so far</p><p className="text-2xl font-bold">{formatETB(list.data.meta.collected_santim)}</p></div>
          <div className="card p-4"><p className="text-sm text-stone-600">Credit limit</p><p className="text-2xl font-bold">{list.data.meta.credit_limit_santim ? formatETB(list.data.meta.credit_limit_santim) : 'Off'}</p><Link to="/admin/settings" className="text-xs text-brand-700 hover:underline">Change</Link></div>
        </div>
      )}
      <form className="mb-4 flex flex-wrap items-center gap-3" onSubmit={(e) => { e.preventDefault(); setParams(toQuery({ owing: owing ? '1' : '', q: term.trim() })) }}>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={owing} onChange={(e) => setParams(toQuery({ owing: e.target.checked ? '1' : '', q }))} /> Only stores that owe</label>
        <label htmlFor="settle-q" className="sr-only">Store name</label>
        <input id="settle-q" className="input w-56" placeholder="Store name" value={term} onChange={(e) => setTerm(e.target.value)} />
        <button type="submit" className="btn-secondary">Search</button>
      </form>
      {list.loading && !list.data ? <Spinner /> : list.error ? <ErrorState error={list.error} /> : list.data.data.length === 0 ? (
        <EmptyState title="Nothing owed">No store matches.</EmptyState>
      ) : (
        <>
          <ul className="card divide-y divide-stone-100">
            {list.data.data.map((s) => (
              <li key={s.id}>
                <Link to={`/admin/settlements/${s.id}`} className="flex items-center justify-between gap-3 p-4 hover:bg-stone-50">
                  <span className="min-w-0">
                    <span className="block truncate font-medium text-stone-900">{s.store_name}</span>
                    <span className="text-xs text-stone-500">{s.phone}</span>
                  </span>
                  <span className="flex shrink-0 items-center gap-2">
                    {s.over_limit && <span className="rounded bg-red-100 px-1.5 text-xs font-semibold text-red-800">Over limit</span>}
                    {s.status !== 'approved' && <StatusBadge status={s.status} />}
                    <span className={`font-semibold ${s.balance_santim < 0 ? 'text-emerald-700' : ''}`}>{s.balance_santim < 0 ? `Credit ${formatETB(-s.balance_santim)}` : formatETB(s.balance_santim)}</span>
                  </span>
                </Link>
              </li>
            ))}
          </ul>
          <Pagination meta={list.data.meta} onPage={(p) => setParams(toQuery({ owing: owing ? '1' : '', q, page: String(p) }))} />
        </>
      )}
    </>
  )
}

function MoneyForm({ kind, sellerId, onDone }) {
  const payment = kind === 'payment'
  const [form, setForm] = useState({ amount: '', method: 'cash', reference: '', note: '' })
  const [errors, setErrors] = useState({})
  const [busy, setBusy] = useState(false)
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  async function submit(e) {
    e.preventDefault()
    const amount = form.amount.trim()
    const label = payment ? `Record a payment of ETB ${amount}?` : `Adjust the balance by ETB ${amount}? This cannot be edited later, only corrected with another adjustment.`
    if (!window.confirm(label)) return
    setBusy(true)
    setErrors({})
    try {
      const body = payment ? { ...form, amount } : { amount, note: form.note }
      const res = await api.post(`/admin/settlements/${sellerId}/${payment ? 'payments' : 'adjustments'}`, body)
      setForm({ amount: '', method: 'cash', reference: '', note: '' })
      onDone(res.data, payment ? 'Payment recorded.' : 'Adjustment saved.')
    } catch (err) {
      const p = parseError(err)
      setErrors({ ...p.fields, _: Object.keys(p.fields).length ? null : p.message })
    } finally {
      setBusy(false)
    }
  }

  const id = (k) => `${kind}-${k}`
  return (
    <form onSubmit={submit} className="card space-y-3 p-4" noValidate>
      <h2 className="font-semibold text-stone-900">{payment ? 'Record a payment' : 'Adjust the balance'}</h2>
      {errors._ && <Alert tone="error">{errors._}</Alert>}
      <Field label="Amount (ETB)" name={id('amount')} error={errors.amount} hint={payment ? 'Money the store paid you.' : 'Positive adds to what the store owes. Negative forgives part of it, e.g. -200.'}>
        <input id={id('amount')} className="input" inputMode="decimal" value={form.amount} onChange={set('amount')} />
      </Field>
      {payment && (
        <>
          <Field label="Method" name={id('method')} error={errors.method}>
            <select id={id('method')} className="input" value={form.method} onChange={set('method')}>
              {Object.entries(METHOD_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </Field>
          <Field label={`Reference${['bank_transfer', 'telebirr'].includes(form.method) ? '' : ' (optional)'}`} name={id('reference')} error={errors.reference} hint="Transfer or transaction number. The same reference cannot be recorded twice.">
            <input id={id('reference')} className="input" maxLength={100} value={form.reference} onChange={set('reference')} />
          </Field>
        </>
      )}
      <Field label={payment ? 'Note (optional)' : 'Reason'} name={id('note')} error={errors.note}>
        <input id={id('note')} className="input" maxLength={500} value={form.note} onChange={set('note')} />
      </Field>
      <button type="submit" className={payment ? 'btn-primary' : 'btn-secondary'} disabled={busy}>{payment ? 'Record payment' : 'Save adjustment'}</button>
    </form>
  )
}

export function AdminSettlementDetail() {
  const { id } = useParams()
  const [page, setPage] = useState(1)
  const res = useFetch(`/admin/settlements/${id}?page=${page}`)
  const [notice, setNotice] = useState(null)

  if (res.loading && !res.data) return <Spinner />
  if (res.error) return <ErrorState error={res.error} />
  const d = res.data.data
  const entries = res.data.entries

  function done(data, text) {
    setPage(1)
    res.setData(data)
    setNotice(text)
  }

  return (
    <>
      <Link to="/admin/settlements" className="text-sm text-brand-700 hover:underline">Back to settlements</Link>
      <PageHeader title={d.seller.store_name} subtitle={<>Phone {d.seller.phone} · <Link to={`/sellers/${d.seller.slug}`} className="text-brand-700 hover:underline">storefront</Link></>} />
      {notice && <div className="mb-4"><Alert tone="success">{notice}</Alert></div>}
      <BalanceCards b={d} />
      <div className="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">
        <section className="card min-w-0 p-4">
          <h2 className="mb-2 font-semibold text-stone-900">Statement</h2>
          <LedgerTable entries={entries.data} admin />
          <Pagination meta={entries.meta} onPage={setPage} />
        </section>
        <div className="space-y-4">
          <MoneyForm kind="payment" sellerId={d.seller.id} onDone={done} />
          <MoneyForm kind="adjustment" sellerId={d.seller.id} onDone={done} />
        </div>
      </div>
    </>
  )
}
