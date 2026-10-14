import { useState } from 'react'
import MapPinPicker from '../components/MapPinPicker'
import { Alert, EmptyState, ErrorState, Field, PageHeader, Spinner } from '../components/ui'
import { useConfig } from '../context/ConfigContext'
import { api, parseError } from '../lib/api'
import { formatETB } from '../lib/format'
import { mapsLink } from '../lib/maps'
import { useFetch } from '../lib/useFetch'

const EMPTY = {
  label: '', recipient_name: '', phone: '', area_id: '', address_line: '', landmark: '', is_default: false,
  latitude: null, longitude: null, floor: '', has_lift: null, door_width_cm: '', stair_width_cm: '', lift_door_width_cm: '',
}
const NUMBERS = ['floor', 'door_width_cm', 'stair_width_cm', 'lift_door_width_cm']

function AddressForm({ initial, areas, onSaved, onCancel }) {
  const config = useConfig()
  const [form, setForm] = useState(initial)
  const [errors, setErrors] = useState({})
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.type === 'checkbox' ? e.target.checked : e.target.value })

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    const payload = { ...form, area_id: form.area_id ? Number(form.area_id) : null }
    for (const k of NUMBERS) payload[k] = form[k] === '' || form[k] === null ? null : Number(form[k])
    // The lift only matters above the ground floor; its door only when there is one.
    if (!(payload.floor > 0)) payload.has_lift = null
    if (!payload.has_lift) payload.lift_door_width_cm = null
    try {
      if (form.id) await api.put(`/addresses/${form.id}`, payload)
      else await api.post('/addresses', payload)
      onSaved(form.id ? 'Address updated.' : 'Address saved.')
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
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Label (optional)" name="a-label" error={errors.label} hint="For example Home or Office.">
          <input id="a-label" className="input" maxLength={40} value={form.label || ''} onChange={set('label')} />
        </Field>
        <Field label="Recipient name" name="a-name" error={errors.recipient_name}>
          <input id="a-name" className="input" maxLength={100} autoComplete="name" value={form.recipient_name} onChange={set('recipient_name')} />
        </Field>
        <Field label="Phone" name="a-phone" error={errors.phone} hint="The delivery driver calls this number.">
          <input id="a-phone" className="input" inputMode="tel" autoComplete="tel" placeholder="+251 9..." value={form.phone} onChange={set('phone')} />
        </Field>
        <Field label="Sub-city" name="a-area" error={errors.area_id}>
          <select id="a-area" className="input" value={form.area_id} onChange={set('area_id')}>
            <option value="">Choose a sub-city</option>
            {areas.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
          </select>
        </Field>
      </div>
      <Field label="Address" name="a-line" error={errors.address_line} hint="Woreda, street or neighborhood, building and house number.">
        <input id="a-line" className="input" maxLength={255} value={form.address_line} onChange={set('address_line')} />
      </Field>
      <Field label="Landmark (optional)" name="a-landmark" error={errors.landmark} hint="Most deliveries in Addis are found by landmark. Be specific.">
        <input id="a-landmark" className="input" maxLength={255} value={form.landmark || ''} onChange={set('landmark')} />
      </Field>
      <MapPinPicker id="a-pin" label="Pin on the map (recommended)" error={errors.latitude || errors.longitude}
        hint={config.delivery?.by_distance ? 'Delivery is priced by distance from the store to this pin. Use your location only when you are at this address.' : 'Helps the driver find you. Use your location only when you are at this address.'}
        value={{ latitude: form.latitude, longitude: form.longitude }} onChange={(pin) => setForm((f) => ({ ...f, ...pin }))} />
      <fieldset className="space-y-3 rounded-xl border border-stone-200 p-3">
        <legend className="px-1 text-sm font-medium text-stone-700">Building</legend>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Floor" name="a-floor" error={errors.floor} hint="0 is the ground floor.">
            <input id="a-floor" className="input" type="number" inputMode="numeric" min={0} max={60} value={form.floor ?? ''} onChange={set('floor')} />
          </Field>
          {Number(form.floor) > 0 && (
            <div>
              <p className="mb-1 text-sm font-medium text-stone-700">Is there a lift?</p>
              <div className="flex gap-4 pt-2 text-sm">
                <label className="flex items-center gap-1.5"><input type="radio" name="a-lift" checked={form.has_lift === true} onChange={() => setForm((f) => ({ ...f, has_lift: true }))} />Yes</label>
                <label className="flex items-center gap-1.5"><input type="radio" name="a-lift" checked={form.has_lift === false} onChange={() => setForm((f) => ({ ...f, has_lift: false }))} />No</label>
              </div>
              {errors.has_lift && <p className="mt-1 text-xs font-medium text-red-700">{errors.has_lift}</p>}
              {form.has_lift === false && config.delivery?.per_floor_santim > 0 && (
                <p className="mt-1 text-xs text-stone-500">Carrying up the stairs adds {formatETB(config.delivery.per_floor_santim)} per floor to delivery.</p>
              )}
            </div>
          )}
        </div>
        <p className="text-xs text-stone-500">Optional: measure the narrowest point in cm so listings can tell you whether a piece will fit.</p>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
          <Field label="Front door width" name="a-door" error={errors.door_width_cm}>
            <input id="a-door" className="input" type="number" inputMode="numeric" min={40} max={400} value={form.door_width_cm ?? ''} onChange={set('door_width_cm')} />
          </Field>
          {Number(form.floor) > 0 && (
            <Field label="Stair width" name="a-stairs" error={errors.stair_width_cm}>
              <input id="a-stairs" className="input" type="number" inputMode="numeric" min={40} max={400} value={form.stair_width_cm ?? ''} onChange={set('stair_width_cm')} />
            </Field>
          )}
          {Number(form.floor) > 0 && form.has_lift && (
            <Field label="Lift door width" name="a-liftdoor" error={errors.lift_door_width_cm}>
              <input id="a-liftdoor" className="input" type="number" inputMode="numeric" min={40} max={400} value={form.lift_door_width_cm ?? ''} onChange={set('lift_door_width_cm')} />
            </Field>
          )}
        </div>
      </fieldset>
      {!initial.is_default && (
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={form.is_default} onChange={set('is_default')} /> Use as my default address
        </label>
      )}
      <div className="flex gap-2">
        <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Saving...' : 'Save address'}</button>
        <button type="button" className="btn-ghost" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  )
}

export default function Addresses() {
  const list = useFetch('/addresses')
  const areas = useFetch('/areas')
  const [editing, setEditing] = useState(null)
  const [notice, setNotice] = useState(null)

  async function run(fn, text) {
    setNotice(null)
    try {
      await fn()
      setNotice({ tone: 'success', text })
    } catch (err) {
      setNotice({ tone: 'error', text: parseError(err).message })
    }
    list.reload()
  }

  const items = list.data?.data || []
  const areaList = areas.data?.data || []

  return (
    <div className="container-page max-w-3xl py-8">
      <PageHeader title="Delivery addresses" subtitle="Saved addresses are used for delivery estimates now and at checkout later."
        actions={!editing && <button type="button" className="btn-primary" onClick={() => setEditing(EMPTY)}>Add address</button>} />
      {notice && <div className="mb-4"><Alert tone={notice.tone}>{notice.text}</Alert></div>}
      {editing && (
        <div className="mb-6">
          <AddressForm key={editing.id || 'new'} initial={editing} areas={areaList} onCancel={() => setEditing(null)}
            onSaved={(text) => { setEditing(null); setNotice({ tone: 'success', text }); list.reload() }} />
        </div>
      )}
      {list.loading && !list.data && <Spinner />}
      {list.error && <ErrorState error={list.error} onRetry={list.reload} />}
      {list.data && (items.length ? (
        <ul className="space-y-3">
          {items.map((a) => (
            <li key={a.id} className="card flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
              <div className="text-sm">
                <p className="font-semibold text-stone-900">
                  {a.label || 'Address'}
                  {a.is_default && <span className="ml-2 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">Default</span>}
                </p>
                <p className="text-stone-700">{a.recipient_name}, {a.phone}</p>
                <p className="text-stone-700">{a.address_line}, {a.area}</p>
                {a.landmark && <p className="text-stone-500">Landmark: {a.landmark}</p>}
                <p className="mt-1 flex flex-wrap gap-x-3 text-xs text-stone-500">
                  {a.latitude != null ? <a href={mapsLink(a.latitude, a.longitude)} target="_blank" rel="noreferrer" className="text-brand-700 hover:underline">Map pin set</a> : <span>No map pin</span>}
                  {a.floor > 0 && <span>Floor {a.floor}, {a.has_lift ? 'with lift' : 'no lift'}</span>}
                  {a.door_width_cm && <span>Door {a.door_width_cm} cm</span>}
                </p>
              </div>
              <div className="flex shrink-0 flex-wrap gap-1">
                {!a.is_default && <button type="button" className="btn-ghost" onClick={() => run(() => api.post(`/addresses/${a.id}/default`), 'Default address changed.')}>Make default</button>}
                <button type="button" className="btn-ghost" onClick={() => setEditing({ ...EMPTY, ...Object.fromEntries(Object.entries(a).map(([k, v]) => [k, v ?? EMPTY[k] ?? v])), area_id: String(a.area_id), is_default: a.is_default })}>Edit</button>
                <button type="button" className="btn-ghost text-red-700" onClick={() => window.confirm('Remove this address?') && run(() => api.delete(`/addresses/${a.id}`), 'Address removed.')}>Remove</button>
              </div>
            </li>
          ))}
        </ul>
      ) : !editing && (
        <EmptyState title="No saved addresses" action={<button type="button" className="btn-primary" onClick={() => setEditing(EMPTY)}>Add address</button>}>
          Save where you want furniture delivered to see delivery fees on every listing.
        </EmptyState>
      ))}
    </div>
  )
}
