import { useEffect, useState } from 'react'
import MapPinPicker from '../../components/MapPinPicker'
import { Alert, ErrorState, Field, PageHeader, Spinner, StatusBadge } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { useFetch } from '../../lib/useFetch'

export default function SellerProfile() {
  const profile = useFetch('/seller/profile')
  const areas = useFetch('/areas')
  const [values, setValues] = useState(null)
  const [errors, setErrors] = useState({})
  const [notice, setNotice] = useState(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    const p = profile.data?.data
    if (p) {
      setValues({
        store_name: p.store_name, description: p.description ?? '', phone: p.phone, tin_number: p.tin_number ?? '',
        area_id: String(p.area_id ?? ''), address_line: p.address_line ?? '',
        offers_custom_orders: p.offers_custom_orders, offers_delivery: p.offers_delivery,
        latitude: p.latitude, longitude: p.longitude,
      })
    }
  }, [profile.data])

  if (profile.loading || !values) return profile.error ? <ErrorState error={profile.error} /> : <Spinner />

  const bind = (name, type = 'text') => ({
    id: name, name, type,
    ...(type === 'checkbox'
      ? { checked: Boolean(values[name]), onChange: (e) => setValues((v) => ({ ...v, [name]: e.target.checked })) }
      : { value: values[name] ?? '', onChange: (e) => setValues((v) => ({ ...v, [name]: e.target.value })) }),
    'aria-invalid': errors[name] ? true : undefined,
  })

  async function save(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setNotice(null)
    try {
      const body = Object.fromEntries(Object.entries(values).map(([k, v]) => [k, v === '' ? null : v]))
      await api.put('/seller/profile', body)
      setNotice({ tone: 'success', text: 'Store profile saved.' })
      profile.reload()
    } catch (err) {
      const parsed = parseError(err)
      setErrors(parsed.fields)
      setNotice({ tone: 'error', text: Object.keys(parsed.fields).length ? 'Please fix the highlighted fields.' : parsed.message })
    } finally {
      setBusy(false)
    }
  }

  const p = profile.data.data
  return (
    <div className="max-w-2xl">
      <PageHeader title="Store profile" subtitle={<span className="inline-flex items-center gap-2">Status: <StatusBadge status={p.status} /></span>} />
      {notice && <div className="mb-4"><Alert tone={notice.tone}>{notice.text}</Alert></div>}
      <form onSubmit={save} className="card space-y-4 p-5" noValidate>
        <Field label="Store name" name="store_name" error={errors.store_name}><input className="input" {...bind('store_name')} /></Field>
        <Field label="About your store" name="description" error={errors.description}><textarea rows={4} className="input" {...bind('description')} /></Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Store phone" name="phone" error={errors.phone}><input className="input" inputMode="tel" {...bind('phone', 'tel')} /></Field>
          <Field label="TIN number" name="tin_number" error={errors.tin_number}><input className="input" {...bind('tin_number')} /></Field>
          <Field label="Sub-city" name="area_id" error={errors.area_id}>
            <select className="input" {...bind('area_id')}>
              {(areas.data?.data || []).map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
            </select>
          </Field>
          <Field label="Address or landmark" name="address_line" error={errors.address_line}><input className="input" {...bind('address_line')} /></Field>
        </div>
        <MapPinPicker id="store-pin" label="Where deliveries leave from" error={errors.latitude || errors.longitude}
          hint="Delivery fees are worked out from this pin to the customer's. Customers never see it."
          value={{ latitude: values.latitude, longitude: values.longitude }} onChange={(pin) => setValues((v) => ({ ...v, ...pin }))} />
        <div className="flex flex-col gap-2 text-sm">
          <label className="flex items-center gap-2"><input className="size-4 accent-brand-700" {...bind('offers_delivery', 'checkbox')} /> I deliver within Addis Ababa</label>
          <label className="flex items-center gap-2"><input className="size-4 accent-brand-700" {...bind('offers_custom_orders', 'checkbox')} /> I accept custom orders</label>
        </div>
        <div className="flex justify-end"><button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Saving...' : 'Save profile'}</button></div>
      </form>
    </div>
  )
}
