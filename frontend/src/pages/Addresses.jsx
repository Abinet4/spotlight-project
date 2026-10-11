import { useState } from 'react'
import { Alert, EmptyState, ErrorState, Field, PageHeader, Spinner } from '../components/ui'
import { api, parseError } from '../lib/api'
import { useFetch } from '../lib/useFetch'

const EMPTY = { label: '', recipient_name: '', phone: '', area_id: '', address_line: '', landmark: '', is_default: false }

function AddressForm({ initial, areas, onSaved, onCancel }) {
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
              </div>
              <div className="flex shrink-0 flex-wrap gap-1">
                {!a.is_default && <button type="button" className="btn-ghost" onClick={() => run(() => api.post(`/addresses/${a.id}/default`), 'Default address changed.')}>Make default</button>}
                <button type="button" className="btn-ghost" onClick={() => setEditing({ ...EMPTY, ...a, area_id: String(a.area_id), is_default: a.is_default })}>Edit</button>
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
