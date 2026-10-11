import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { CartLine } from './Cart'
import { Alert, EmptyState, ErrorState, Field, PageHeader, Spinner } from '../components/ui'
import { useCart } from '../context/CartContext'
import { useConfig } from '../context/ConfigContext'
import { api, parseError } from '../lib/api'
import { formatETB, newIdempotencyKey, toQuery } from '../lib/format'
import { useFetch } from '../lib/useFetch'

export default function Checkout() {
  const navigate = useNavigate()
  const config = useConfig()
  const { refreshCart } = useCart()
  const addresses = useFetch('/addresses')
  const [addressId, setAddressId] = useState('')
  const [fulfillment, setFulfillment] = useState({})
  const [note, setNote] = useState('')
  const [preview, setPreview] = useState({ data: null, error: null, query: null })
  const [placing, setPlacing] = useState(false)
  const [error, setError] = useState(null)
  // One key per checkout attempt: a double click or a retry after a timeout cannot order twice.
  const [idempotencyKey, setIdempotencyKey] = useState(newIdempotencyKey)

  const list = addresses.data?.data || []
  // Until the customer picks one, use the default address (or the first).
  const selectedAddress = addressId || (list.length ? String((list.find((a) => a.is_default) || list[0]).id) : '')

  // A different address or delivery choice is a different order: never reuse the old key for it.
  function changeAddress(id) {
    setAddressId(id)
    setIdempotencyKey(newIdempotencyKey())
  }
  function changeFulfillment(sellerId, mode) {
    setFulfillment((f) => ({ ...f, [sellerId]: mode }))
    setIdempotencyKey(newIdempotencyKey())
  }

  const query = useMemo(() => {
    const params = new URLSearchParams(toQuery({ address_id: selectedAddress }))
    for (const [seller, mode] of Object.entries(fulfillment)) params.set(`fulfillment[${seller}]`, mode)
    return params.toString()
  }, [selectedAddress, fulfillment])

  useEffect(() => {
    if (addresses.loading) return undefined
    let current = true
    api.get(`/cart?${query}`)
      .then((res) => current && setPreview({ data: res.data.data, error: null, query }))
      .catch((err) => current && setPreview({ data: null, error: parseError(err), query }))
    return () => {
      current = false
    }
  }, [query, addresses.loading])
  // The totals on screen belong to an older address or delivery choice until the new preview arrives.
  const stale = preview.query !== query

  async function place() {
    setPlacing(true)
    setError(null)
    try {
      const res = await api.post('/checkout', {
        address_id: Number(selectedAddress),
        payment_method: 'cod',
        fulfillment,
        expected_total_santim: preview.data.grand_total_santim,
        note: note.trim() || null,
      }, { headers: { 'Idempotency-Key': idempotencyKey } })
      refreshCart()
      navigate('/orders', { replace: true, state: { placed: res.data.data } })
    } catch (err) {
      if (err.response?.status === 409 && err.response.data?.data) {
        // Prices, stock or fees changed: show the fresh numbers and let the customer confirm again.
        setPreview({ data: err.response.data.data, error: null, query })
        setIdempotencyKey(newIdempotencyKey())
        setError(err.response.data.message)
      } else {
        const e = parseError(err)
        setError(Object.values(e.fields)[0] || e.message)
      }
      setPlacing(false)
    }
  }

  if (addresses.loading || (!preview.data && !preview.error)) return <Spinner />
  if (addresses.error || preview.error) return <div className="container-page py-10"><ErrorState error={addresses.error || preview.error} /></div>

  const p = preview.data
  if (p.line_count === 0) {
    return <div className="container-page py-10"><EmptyState title="Your cart is empty" action={<Link to="/search" className="btn-primary">Browse furniture</Link>} /></div>
  }

  const codOff = config.cod_enabled === false
  const canPlace = selectedAddress && !stale && !p.has_problems && !placing && !codOff

  return (
    <div className="container-page max-w-4xl py-8">
      <PageHeader title="Checkout" />
      {error && <div className="mb-4"><Alert tone="error">{error}</Alert></div>}
      <div className="grid gap-6 lg:grid-cols-[1fr_300px]">
        <div className="space-y-4">
          <section className="card space-y-3 p-4">
            <h2 className="font-semibold text-stone-900">Deliver to</h2>
            {list.length === 0 ? (
              <Alert tone="warning">Add a delivery address first. <Link to="/account/addresses" className="font-semibold underline">Add address</Link></Alert>
            ) : (
              <>
                <Field label="Address" name="checkout-address">
                  <select id="checkout-address" className="input" value={selectedAddress} onChange={(e) => changeAddress(e.target.value)}>
                    {list.map((a) => <option key={a.id} value={a.id}>{a.label ? `${a.label}: ` : ''}{a.address_line}, {a.area} ({a.recipient_name})</option>)}
                  </select>
                </Field>
                <Link to="/account/addresses" className="text-sm text-brand-700 hover:underline">Manage addresses</Link>
              </>
            )}
          </section>

          {p.groups.map((g) => (
            <section key={g.seller.id} className="card px-4 py-3">
              <h2 className="text-sm font-semibold text-stone-700">Order from {g.seller.store_name}</h2>
              <ul className="divide-y divide-stone-100">
                {g.lines.map((l) => <CartLine key={l.id} line={l} readOnly />)}
              </ul>
              {g.delivery_available && g.pickup_available && (
                <fieldset className="mt-2 flex flex-wrap gap-4 text-sm">
                  <legend className="sr-only">Delivery or pickup from {g.seller.store_name}</legend>
                  {['delivery', 'pickup'].map((mode) => (
                    <label key={mode} className="flex items-center gap-2">
                      <input type="radio" name={`fulfillment-${g.seller.id}`} checked={g.fulfillment === mode}
                        onChange={() => changeFulfillment(g.seller.id, mode)} />
                      {mode === 'delivery' ? 'Deliver to my address' : `Pick up from the store${g.seller.area ? ` in ${g.seller.area}` : ''}`}
                    </label>
                  ))}
                </fieldset>
              )}
              <div className="mt-2 flex justify-between border-t border-stone-100 pt-2 text-sm">
                <span>{g.fulfillment === 'pickup' ? 'Pickup' : 'Delivery'}</span>
                <span className="font-medium">
                  {g.fulfillment === 'pickup' ? 'Free' : g.delivery_fee_confirmed ? (g.delivery_fee_santim === 0 ? 'Free' : formatETB(g.delivery_fee_santim)) : 'Seller confirms the fee'}
                </span>
              </div>
              {g.problem && <p className="mt-1 text-sm font-medium text-red-700">{g.problem}</p>}
            </section>
          ))}

          <section className="card p-4">
            <Field label="Note for the sellers (optional)" name="checkout-note" hint="Delivery timing, floor and access, or anything else they should know.">
              <textarea id="checkout-note" className="input min-h-20" maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} />
            </Field>
          </section>
        </div>

        <aside className="card h-fit space-y-3 p-4">
          <h2 className="font-semibold text-stone-900">Payment</h2>
          {codOff ? <Alert tone="warning">Ordering is paused right now. Please try again later.</Alert> : (
            <label className="flex items-start gap-2 text-sm">
              <input type="radio" checked readOnly className="mt-1" />
              <span><span className="font-medium">Cash on delivery</span><br /><span className="text-stone-500">Pay the seller when you receive your furniture.</span></span>
            </label>
          )}
          <dl className="space-y-1 border-t border-stone-100 pt-3 text-sm">
            <div className="flex justify-between"><dt>Items</dt><dd>{formatETB(p.items_total_santim)}</dd></div>
            <div className="flex justify-between"><dt>Delivery</dt><dd>{formatETB(p.delivery_total_santim) || 'ETB 0'}{p.delivery_to_confirm && ' + to confirm'}</dd></div>
            <div className="flex justify-between text-base font-bold text-stone-900"><dt>Total</dt><dd>{formatETB(p.grand_total_santim)}</dd></div>
          </dl>
          {p.delivery_to_confirm && <p className="text-xs text-stone-500">A seller will confirm their delivery fee before preparing your order. You can cancel if it does not suit you.</p>}
          {p.groups.length > 1 && <p className="text-xs text-stone-500">This creates {p.groups.length} orders, one per seller.</p>}
          <button type="button" className="btn-primary w-full" disabled={!canPlace} onClick={place}>{placing ? 'Placing order...' : stale ? 'Updating total...' : 'Place order'}</button>
        </aside>
      </div>
    </div>
  )
}
