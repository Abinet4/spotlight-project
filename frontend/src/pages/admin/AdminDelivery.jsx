import { useState } from 'react'
import { Alert, ErrorState, Field, PageHeader, Spinner } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { formatETB, santimToBirr } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'

/** Base fee, price per km, longest trip and carrying fee, stored as admin settings. */
function DistanceSettings() {
  const settings = useFetch('/admin/settings')
  const [form, setForm] = useState(null)
  const [errors, setErrors] = useState({})
  const [notice, setNotice] = useState(null)
  const [busy, setBusy] = useState(false)
  const keys = ['delivery_base_fee_birr', 'delivery_per_km_birr', 'delivery_max_km', 'delivery_per_floor_birr']

  if (settings.error) return <ErrorState error={settings.error} onRetry={settings.reload} />
  if (!settings.data) return null
  const current = settings.data.data
  const meta = settings.data.meta
  const f = form || { ...Object.fromEntries(keys.map((k) => [k, String(current[k])])), stores_set_delivery_fee: current.stores_set_delivery_fee }
  const set = (k) => (e) => setForm({ ...f, [k]: e.target.value })
  const example = (km) => {
    const base = Number(f.delivery_base_fee_birr) || 0
    const per = Number(f.delivery_per_km_birr) || 0
    return Math.ceil((base + per * km) / 10) * 10
  }

  async function save(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setNotice(null)
    try {
      await api.put('/admin/settings', { ...Object.fromEntries(keys.map((k) => [k, f[k] === '' ? null : Number(f[k])])), stores_set_delivery_fee: Boolean(f.stores_set_delivery_fee) })
      setNotice({ tone: 'success', text: 'Delivery prices saved.' })
      setForm(null)
      settings.reload()
    } catch (err) {
      const parsed = parseError(err)
      setErrors(parsed.fields)
      setNotice({ tone: 'error', text: Object.values(parsed.fields)[0] || parsed.message })
    } finally {
      setBusy(false)
    }
  }

  const box = (k, label, hint) => (
    <Field label={label} name={k} error={errors[k]} hint={hint}>
      <input id={k} className="input" type="number" inputMode="numeric" min={0} value={f[k]} onChange={set(k)} />
    </Field>
  )

  return (
    <form onSubmit={save} className="card mb-6 space-y-3 p-4" noValidate>
      <div>
        <h2 className="font-semibold text-stone-900">Price by distance</h2>
        <p className="text-sm text-stone-600">Used when the store and the address both have a map pin. Otherwise the zones below apply. A store can always offer free delivery on a listing.</p>
      </div>
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      <div className="grid gap-3 sm:grid-cols-4">
        {box('delivery_base_fee_birr', 'Base fee (ETB)')}
        {box('delivery_per_km_birr', 'Per km (ETB)', '0 turns it off')}
        {box('delivery_max_km', 'Longest trip (km)', 'Longer: store sets the fee. 0: no limit')}
        {box('delivery_per_floor_birr', 'Per floor, no lift (ETB)', 'Carrying up stairs')}
      </div>
      <div className="text-sm">
        <label className="flex items-start gap-2">
          <input id="stores_set_delivery_fee" type="checkbox" className="mt-0.5 size-4 accent-brand-700" checked={Boolean(f.stores_set_delivery_fee)}
            onChange={(e) => setForm({ ...f, stores_set_delivery_fee: e.target.checked })} />
          <span>Stores can set their own delivery fee on a listing
            <span className="block text-xs text-stone-500">
              {f.stores_set_delivery_fee ? 'A listing\'s own fee wins over the prices here.' : 'Off: the prices here apply to every store.'}
              {meta.listings_with_own_fee > 0 && ` ${meta.listings_with_own_fee} ${meta.listings_with_own_fee === 1 ? 'listing has' : 'listings have'} a fee of its own${f.stores_set_delivery_fee ? '' : ', not used while this is off'}.`}
            </span>
          </span>
        </label>
      </div>
      {Number(f.delivery_per_km_birr) > 0 && (
        <p className="text-xs text-stone-500">Examples: 3 km {formatETB(example(3) * 100)}, 8 km {formatETB(example(8) * 100)}, 15 km {formatETB(example(15) * 100)}. Fees round up to 10 birr.</p>
      )}
      <ul className="space-y-1 text-xs">
        <li className={meta.maps_server_key_set ? 'text-stone-500' : 'text-amber-800'}>
          {meta.maps_server_key_set ? 'Road distance comes from Google.' : 'No Google server key: distance is estimated from a straight line times 1.3.'}
        </li>
        <li className={meta.maps_browser_key_set ? 'text-stone-500' : 'text-amber-800'}>
          {meta.maps_browser_key_set ? 'The map shows on address and store forms.' : 'No Google browser key: forms offer "Use my location" instead of a map.'}
        </li>
        {meta.stores_without_pin > 0 && <li className="text-amber-800">{meta.stores_without_pin} delivering {meta.stores_without_pin === 1 ? 'store has' : 'stores have'} no map pin yet, so {meta.stores_without_pin === 1 ? 'it uses' : 'they use'} zone fees.</li>}
      </ul>
      <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Saving...' : 'Save prices'}</button>
    </form>
  )
}

const EMPTY = { name: '', fee: '', min_days: 1, max_days: 3, notes: '', is_active: true, area_ids: [] }

function ZoneForm({ initial, areas, zones, onSaved, onCancel }) {
  const [form, setForm] = useState(initial)
  const [errors, setErrors] = useState({})
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const zoneName = Object.fromEntries(zones.map((z) => [z.id, z.name]))
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.type === 'checkbox' ? e.target.checked : e.target.value })

  function toggleArea(id) {
    setForm((f) => ({ ...f, area_ids: f.area_ids.includes(id) ? f.area_ids.filter((x) => x !== id) : [...f.area_ids, id] }))
  }

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    const payload = { ...form, min_days: Number(form.min_days), max_days: Number(form.max_days), notes: form.notes || null }
    try {
      if (form.id) await api.put(`/admin/delivery-zones/${form.id}`, payload)
      else await api.post('/admin/delivery-zones', payload)
      onSaved(form.id ? 'Delivery zone updated.' : 'Delivery zone created.')
    } catch (err) {
      const parsed = parseError(err)
      setErrors(parsed.fields)
      setError(Object.values(parsed.fields)[0] || parsed.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="card space-y-3 p-4" noValidate>
      {error && <Alert tone="error">{error}</Alert>}
      <div className="grid gap-3 sm:grid-cols-4">
        <div className="sm:col-span-2">
          <Field label="Zone name" name="z-name" error={errors.name}>
            <input id="z-name" className="input" maxLength={100} value={form.name} onChange={set('name')} />
          </Field>
        </div>
        <Field label="Delivery fee (ETB)" name="z-fee" error={errors.fee}>
          <input id="z-fee" className="input" inputMode="decimal" value={form.fee} onChange={set('fee')} />
        </Field>
        <div className="grid grid-cols-2 gap-2">
          <Field label="Min days" name="z-min" error={errors.min_days}>
            <input id="z-min" type="number" min="0" max="60" className="input" value={form.min_days} onChange={set('min_days')} />
          </Field>
          <Field label="Max days" name="z-max" error={errors.max_days}>
            <input id="z-max" type="number" min="0" max="60" className="input" value={form.max_days} onChange={set('max_days')} />
          </Field>
        </div>
      </div>
      <Field label="Notes shown to customers (optional)" name="z-notes" error={errors.notes}>
        <input id="z-notes" className="input" maxLength={500} value={form.notes || ''} onChange={set('notes')} />
      </Field>
      <fieldset>
        <legend className="label">Sub-cities in this zone</legend>
        <div className="grid gap-1 sm:grid-cols-3">
          {areas.map((a) => {
            const elsewhere = a.delivery_zone_id && a.delivery_zone_id !== form.id
            return (
              <label key={a.id} className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={form.area_ids.includes(a.id)} onChange={() => toggleArea(a.id)} />
                {a.name}
                {elsewhere && !form.area_ids.includes(a.id) && <span className="text-xs text-stone-500">({zoneName[a.delivery_zone_id]})</span>}
              </label>
            )
          })}
        </div>
        <p className="mt-1 text-xs text-stone-500">Ticking a sub-city that belongs to another zone moves it here.</p>
      </fieldset>
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={form.is_active} onChange={set('is_active')} /> Active
      </label>
      <div className="flex gap-2">
        <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Saving...' : 'Save zone'}</button>
        <button type="button" className="btn-ghost" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  )
}

export default function AdminDelivery() {
  const list = useFetch('/admin/delivery-zones')
  const [editing, setEditing] = useState(null)
  const [notice, setNotice] = useState(null)

  const zones = list.data?.data.zones || []
  const areas = list.data?.data.areas || []
  const unassigned = areas.filter((a) => a.is_active && !a.delivery_zone_id)

  async function remove(z) {
    if (!window.confirm(`Delete "${z.name}"? Its sub-cities will show "fee confirmed by the seller" until assigned to another zone.`)) return
    setNotice(null)
    try {
      await api.delete(`/admin/delivery-zones/${z.id}`)
      setNotice({ tone: 'success', text: 'Delivery zone deleted.' })
    } catch (err) {
      setNotice({ tone: 'error', text: parseError(err).message })
    }
    list.reload()
  }

  return (
    <>
      <PageHeader title="Delivery" />
      <DistanceSettings />
      <div className="mb-3 flex flex-wrap items-end justify-between gap-2">
        <div>
          <h2 className="font-semibold text-stone-900">Zones by sub-city</h2>
          <p className="text-sm text-stone-600">Fee and delivery time when distance pricing does not apply.</p>
        </div>
        {!editing && <button type="button" className="btn-primary" onClick={() => setEditing(EMPTY)}>Add zone</button>}
      </div>
      {notice && <div className="mb-4"><Alert tone={notice.tone}>{notice.text}</Alert></div>}
      {editing && (
        <div className="mb-6">
          <ZoneForm key={editing.id || 'new'} initial={editing} areas={areas} zones={zones} onCancel={() => setEditing(null)}
            onSaved={(text) => { setEditing(null); setNotice({ tone: 'success', text }); list.reload() }} />
        </div>
      )}
      {list.loading && !list.data && <Spinner />}
      {list.error && <ErrorState error={list.error} onRetry={list.reload} />}
      {list.data && (
        <>
          {unassigned.length > 0 && (
            <div className="mb-4">
              <Alert tone="warning">Not in any zone: {unassigned.map((a) => a.name).join(', ')}. Customers there see "fee confirmed by the seller".</Alert>
            </div>
          )}
          <div className="card relative overflow-x-auto">
            <table className="rtable w-full text-left text-sm">
              <thead className="border-b border-stone-200 text-xs text-stone-500 uppercase">
                <tr><th className="px-4 py-3">Zone</th><th className="px-4 py-3">Fee</th><th className="px-4 py-3">Days</th><th className="px-4 py-3">Sub-cities</th><th className="px-4 py-3">Status</th><th className="px-4 py-3"><span className="sr-only">Actions</span></th></tr>
              </thead>
              <tbody className="divide-y divide-stone-100">
                {zones.length === 0 && <tr><td colSpan={6} className="px-4 py-6 text-center text-stone-500">No zones yet.</td></tr>}
                {zones.map((z) => (
                  <tr key={z.id}>
                    <td className="rtable-title px-4 py-3 font-medium text-stone-900">{z.name}</td>
                    <td data-label="Fee" className="px-4 py-3">{z.fee_santim === 0 ? 'Free' : formatETB(z.fee_santim)}</td>
                    <td data-label="Days" className="px-4 py-3">{z.min_days === z.max_days ? z.min_days : `${z.min_days} to ${z.max_days}`}</td>
                    <td data-label="Sub-cities" className="px-4 py-3 text-stone-600">{z.areas.map((a) => a.name).join(', ') || 'None'}</td>
                    <td data-label="Status" className="px-4 py-3">{z.is_active ? 'Active' : <span className="text-stone-500">Inactive</span>}</td>
                    <td className="px-4 py-3">
                      <div className="-ml-3 flex gap-1 sm:ml-0 sm:justify-end">
                        <button type="button" className="btn-ghost"
                          onClick={() => setEditing({ ...EMPTY, ...z, fee: santimToBirr(z.fee_santim), notes: z.notes || '', area_ids: z.areas.map((a) => a.id) })}>Edit</button>
                        <button type="button" className="btn-ghost text-red-700" onClick={() => remove(z)}>Delete</button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </>
  )
}
