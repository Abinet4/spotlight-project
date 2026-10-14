import { useEffect, useState } from 'react'
import { useAuth } from '../context/AuthContext'
import { api, parseError } from '../lib/api'
import { formatETB } from '../lib/format'
import { useFetch } from '../lib/useFetch'

function days(min, max) {
  if (min === null) return null
  if (max === null || max === min) return `about ${min} day${min === 1 ? '' : 's'}`
  return `${min} to ${max} days`
}

/**
 * "Deliver to" estimate on the product page. Signed-in customers can pick a saved address
 * (its map pin prices delivery by distance, its floor adds carrying) and start with their
 * default one; anyone can pick a sub-city.
 */
export default function DeliveryEstimate({ slug }) {
  const { user } = useAuth()
  const areas = useFetch('/areas')
  const addresses = useFetch(user?.role === 'customer' ? '/addresses' : null)
  // "a:<address id>" or "s:<sub-city id>"
  const [choice, setChoice] = useState('')
  const [touched, setTouched] = useState(false)
  const [result, setResult] = useState({ data: null, error: null, loading: false })

  const saved = addresses.data?.data || []
  const defaultId = saved.find((a) => a.is_default)?.id
  useEffect(() => {
    if (defaultId && !touched) setChoice(`a:${defaultId}`)
  }, [defaultId, touched])

  useEffect(() => {
    if (!choice) {
      setResult({ data: null, error: null, loading: false })
      return undefined
    }
    let current = true
    setResult({ data: null, error: null, loading: true })
    const [kind, id] = choice.split(':')
    api.get(`/products/${encodeURIComponent(slug)}/delivery-estimate`, { params: kind === 'a' ? { address_id: id } : { area_id: id } })
      .then((res) => current && setResult({ data: res.data.data, error: null, loading: false }))
      .catch((err) => current && setResult({ data: null, error: parseError(err), loading: false }))
    return () => {
      current = false
    }
  }, [slug, choice])

  const list = areas.data?.data || []
  const e = result.data
  const when = e && days(e.min_days, e.max_days)

  return (
    <div className="space-y-2">
      <label htmlFor="delivery-area" className="text-sm font-medium text-stone-700">Deliver to</label>
      <select id="delivery-area" className="input" value={choice} onChange={(ev) => { setTouched(true); setChoice(ev.target.value) }}>
        <option value="">Choose your sub-city</option>
        {saved.length > 0 && (
          <optgroup label="Your addresses">
            {saved.map((a) => <option key={a.id} value={`a:${a.id}`}>{a.label || 'Address'}, {a.area}</option>)}
          </optgroup>
        )}
        <optgroup label="Sub-cities">
          {list.map((a) => <option key={a.id} value={`s:${a.id}`}>{a.name}</option>)}
        </optgroup>
      </select>
      <div aria-live="polite" className="text-sm">
        {result.loading && <p className="text-stone-500">Checking delivery...</p>}
        {result.error && <p className="text-red-700">{result.error.message}</p>}
        {e && !e.delivery_available && <p className="text-stone-700">This seller does not deliver this item. {e.pickup_available && 'Pickup is available.'}</p>}
        {e && e.delivery_available && (
          <p className="text-stone-800">
            {e.fee_source === 'seller_quote'
              ? `The seller confirms the delivery fee to ${e.area} before you pay.`
              : `Delivery to ${e.area}${e.distance_km !== null ? ` (${e.distance_km} km)` : ''}: ${e.fee_santim === 0 ? 'free' : formatETB(e.fee_santim)}.`}
            {when && ` Estimated ${when}${e.fee_source === 'seller_quote' ? ' plus delivery' : ''}.`}
          </p>
        )}
        {e?.delivery_available && e.too_far && <p className="text-xs text-stone-500">That is further than distance pricing covers, so the seller sets the fee.</p>}
        {e?.delivery_available && e.carry_fee_santim > 0 && <p className="text-xs text-stone-500">{e.fee_santim === null ? "Plus" : "Includes"} {formatETB(e.carry_fee_santim)} for carrying up the stairs (no lift).</p>}
      </div>
    </div>
  )
}
