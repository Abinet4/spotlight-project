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
 * "Deliver to [sub-city]" estimate on the product page. Signed-in customers start
 * with the sub-city of their default address.
 */
export default function DeliveryEstimate({ slug }) {
  const { user } = useAuth()
  const areas = useFetch('/areas')
  const addresses = useFetch(user?.role === 'customer' ? '/addresses' : null)
  const [areaId, setAreaId] = useState('')
  const [touched, setTouched] = useState(false)
  const [result, setResult] = useState({ data: null, error: null, loading: false })

  const defaultArea = addresses.data?.data?.find((a) => a.is_default)?.area_id
  useEffect(() => {
    if (defaultArea && !touched) setAreaId(String(defaultArea))
  }, [defaultArea, touched])

  useEffect(() => {
    if (!areaId) {
      setResult({ data: null, error: null, loading: false })
      return undefined
    }
    let current = true
    setResult({ data: null, error: null, loading: true })
    api.get(`/products/${encodeURIComponent(slug)}/delivery-estimate`, { params: { area_id: areaId } })
      .then((res) => current && setResult({ data: res.data.data, error: null, loading: false }))
      .catch((err) => current && setResult({ data: null, error: parseError(err), loading: false }))
    return () => {
      current = false
    }
  }, [slug, areaId])

  const list = areas.data?.data || []
  const e = result.data
  const when = e && days(e.min_days, e.max_days)

  return (
    <div className="space-y-2">
      <label htmlFor="delivery-area" className="text-sm font-medium text-stone-700">Deliver to</label>
      <select id="delivery-area" className="input" value={areaId} onChange={(ev) => { setTouched(true); setAreaId(ev.target.value) }}>
        <option value="">Choose your sub-city</option>
        {list.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
      </select>
      <div aria-live="polite" className="text-sm">
        {result.loading && <p className="text-stone-500">Checking delivery...</p>}
        {result.error && <p className="text-red-700">{result.error.message}</p>}
        {e && !e.delivery_available && <p className="text-stone-700">This seller does not deliver this item. {e.pickup_available && 'Pickup is available.'}</p>}
        {e && e.delivery_available && (
          <p className="text-stone-800">
            {e.fee_source === 'seller_quote'
              ? `The seller confirms the delivery fee to ${e.area} before you pay.`
              : `Delivery to ${e.area}: ${e.fee_santim === 0 ? 'free' : formatETB(e.fee_santim)}.`}
            {when && ` Estimated ${when}${e.fee_source === 'seller_quote' ? ' plus delivery' : ''}.`}
          </p>
        )}
      </div>
    </div>
  )
}
