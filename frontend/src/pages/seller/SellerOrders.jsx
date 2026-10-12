import { useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import OrderView from '../../components/OrderView'
import { Alert, ErrorState, Field, Spinner } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { formatETB, orderActionLabel, toQuery } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'
import { CancelForm, OrderList } from '../Orders'
import { SELLER_ORDER_LABELS, useSellerSummary } from './shared'

const TABS = ['', 'pending_confirmation', 'confirmed', 'processing', 'ready', 'dispatched', 'delivered', 'cancelled']

export function SellerOrders() {
  const [params, setParams] = useSearchParams()
  const status = params.get('status') || ''
  const summary = useSellerSummary().data?.data
  const counts = summary?.orders || {}
  // Statuses with no orders are left out (the one being viewed always shows).
  const tabs = TABS.filter((t) => !t || t === status || counts[t] > 0)

  return (
    <>
      <h1 className="mb-4 text-2xl font-bold tracking-tight text-stone-900">Orders</h1>
      {summary?.balance.over_limit && (
        <div className="mb-4"><Alert tone="error">Your unpaid commission is over the limit, so you cannot confirm new cash orders. <Link to="/seller/balance" className="font-semibold underline">See how to pay</Link></Alert></div>
      )}
      {tabs.length > 1 && (
        <div className="no-scrollbar mb-4 flex gap-1 overflow-x-auto border-b border-stone-200" role="tablist">
          {tabs.map((t) => (
            <button key={t} type="button" role="tab" aria-selected={status === t} onClick={() => setParams(t ? { status: t } : {})}
              className={`shrink-0 border-b-2 px-3 py-2 text-sm font-medium ${status === t ? 'border-brand-700 text-brand-800' : 'border-transparent text-stone-600 hover:text-stone-900'}`}>
              {t ? SELLER_ORDER_LABELS[t] : 'All'}{t && counts[t] ? <span className={`ml-1 ${t === 'pending_confirmation' ? 'rounded-full bg-amber-100 px-1.5 text-xs font-semibold text-amber-800' : 'text-stone-400'}`}>{counts[t]}</span> : null}
            </button>
          ))}
        </div>
      )}
      <OrderList key={status} url={`/seller/orders?${toQuery({ status })}`} viewer="seller" base="/seller/orders" />
    </>
  )
}

function nextSteps(order) {
  const flow = order.fulfillment === 'pickup' ? ['processing', 'ready', 'delivered'] : ['processing', 'ready', 'dispatched', 'delivered']
  const order_ = ['confirmed', ...flow]
  const at = order_.indexOf(order.status)
  return at === -1 ? [] : flow.filter((s) => order_.indexOf(s) > at)
}

/** What the store should know before typing a delivery fee: trip length and the carrying fee added on top. */
function feeHint(order) {
  const parts = [order.delivery_distance_km != null
    ? `The trip is about ${order.delivery_distance_km} km, longer than the marketplace prices by itself.`
    : 'No fee was set for this address.', 'Enter 0 for free delivery.']
  if (order.carry_fee_santim > 0) parts.push(`${formatETB(order.carry_fee_santim)} for carrying up the stairs is added on top.`)
  return parts.join(' ')
}

function ConfirmForm({ order, onDone }) {
  const needsFee = order.fulfillment === 'delivery' && order.delivery_fee_santim === null
  const [fee, setFee] = useState('')
  const [note, setNote] = useState('')
  const [errors, setErrors] = useState({})
  const [busy, setBusy] = useState(false)

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    try {
      const res = await api.post(`/seller/orders/${order.id}/confirm`, { delivery_fee: needsFee ? fee : null, note: note || null })
      onDone(res.data)
    } catch (err) {
      const p = parseError(err)
      // Errors about the fee belong under the fee box; anything else (store over its limit, order changed) goes on top.
      const { delivery_fee: fee, ...rest } = p.fields
      setErrors({ delivery_fee: fee, _: Object.values(rest)[0] || (fee ? null : p.message) })
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-3" noValidate>
      <p className="text-sm font-medium text-amber-900">Check stock and timing, then confirm. The customer is told right away.</p>
      {errors._ && <Alert tone="error">{errors._}</Alert>}
      {needsFee && (
        <Field label="Delivery fee (ETB)" name="confirm-fee" error={errors.delivery_fee} hint={feeHint(order)}>
          <input id="confirm-fee" className="input" inputMode="decimal" value={fee} onChange={(e) => setFee(e.target.value)} />
        </Field>
      )}
      <Field label="Note to the customer (optional)" name="confirm-note" hint="For example the delivery day.">
        <input id="confirm-note" className="input" maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} />
      </Field>
      <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Confirming...' : 'Confirm order'}</button>
    </form>
  )
}

export function SellerOrderDetail() {
  const { id } = useParams()
  const order = useFetch(`/seller/orders/${id}`)
  const summary = useSellerSummary()
  const [error, setError] = useState(null)

  // Each step changes the menu counts (orders to confirm, balance), so refresh them too.
  function updated(res) {
    order.setData(res)
    summary.reload()
  }

  if (order.loading && !order.data) return <Spinner />
  if (order.error) return <ErrorState error={order.error} />
  const o = order.data.data

  async function call(request) {
    setError(null)
    try {
      const res = await request
      updated(res.data)
    } catch (err) {
      const p = parseError(err)
      setError(Object.values(p.fields)[0] || p.message)
      order.reload()
    }
  }

  const steps = nextSteps(o)
  const canCancel = ['pending_confirmation', 'confirmed', 'processing', 'ready'].includes(o.status)

  const actions = (
    <div className="space-y-3">
      {error && <Alert tone="error">{error}</Alert>}
      {o.status === 'pending_confirmation' && <ConfirmForm order={o} onDone={updated} />}
      {steps.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {steps.map((s, i) => (
            <button key={s} type="button" className={i === 0 ? 'btn-primary' : 'btn-secondary'}
              onClick={() => call(api.post(`/seller/orders/${o.id}/status`, { status: s }))}>
              {orderActionLabel(s, o.fulfillment, o.payment_method)}
            </button>
          ))}
        </div>
      )}
      {steps.includes('delivered') && (
        <p className="text-xs text-stone-500">
          {o.payment_method === 'online'
            ? `Paid online: do not collect cash. Use "${orderActionLabel('delivered', o.fulfillment, 'online')}" once the customer has the furniture; the marketplace then owes you this sale minus commission.`
            : `Use "${orderActionLabel('delivered', o.fulfillment)}" only after the customer has the furniture and you have the cash.`} Later steps can be skipped.
        </p>
      )}
      {canCancel && <CancelForm onCancel={(reason) => call(api.post(`/seller/orders/${o.id}/cancel`, { reason }))} hint={o.payment_method === 'online' ? 'Reserved stock goes back to your listing. The customer paid online and gets a full refund. The customer sees your reason.' : 'Reserved stock goes back to your listing. The customer sees your reason.'} />}
    </div>
  )

  return (
    <>
      <Link to="/seller/orders" className="text-sm text-brand-700 hover:underline">Back to orders</Link>
      <div className="mt-3"><OrderView order={o} viewer="seller" actions={actions} /></div>
    </>
  )
}
