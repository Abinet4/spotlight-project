import { useState } from 'react'
import { Alert, ErrorState, Field, PageHeader, Spinner } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { formatETB } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'

const STATE_LABELS = {
  active: ['Active', 'bg-emerald-100 text-emerald-800'],
  scheduled: ['Starts later', 'bg-sky-100 text-sky-800'],
  ended: ['Ended', 'bg-stone-100 text-stone-600'],
  used_up: ['Used up', 'bg-stone-100 text-stone-600'],
  off: ['Off', 'bg-stone-100 text-stone-600'],
}

const EMPTY = {
  code: '', description: '', kind: 'percent', percent_off: '', amount_off: '', max_discount: '', min_items: '',
  starts_on: '', ends_on: '', max_uses: '', max_uses_per_customer: '1', is_active: true,
}

const birr = (v) => (v ? String(v).replace(/\.00$/, '') : '')
const shortDay = (ymd) => new Date(`${ymd}T12:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })

function fromCoupon(c) {
  return {
    ...EMPTY, ...c,
    description: c.description || '', percent_off: c.percent_off ? String(c.percent_off) : '', amount_off: birr(c.amount_off),
    max_discount: birr(c.max_discount), min_items: birr(c.min_items), starts_on: c.starts_on || '', ends_on: c.ends_on || '',
    max_uses: c.max_uses ? String(c.max_uses) : '', max_uses_per_customer: c.max_uses_per_customer ? String(c.max_uses_per_customer) : '',
  }
}

function payload(form) {
  const v = (s) => (String(s ?? '').trim() === '' ? null : String(s).trim().replace(/,/g, ''))
  const percent = form.kind === 'percent'
  return {
    code: form.code.trim(), description: v(form.description), kind: form.kind,
    percent_off: percent ? v(form.percent_off) : null, amount_off: percent ? null : v(form.amount_off),
    max_discount: percent ? v(form.max_discount) : null, min_items: v(form.min_items),
    starts_on: v(form.starts_on), ends_on: v(form.ends_on),
    max_uses: v(form.max_uses), max_uses_per_customer: v(form.max_uses_per_customer), is_active: Boolean(form.is_active),
  }
}

function CouponForm({ initial, onSaved, onCancel }) {
  const [form, setForm] = useState(initial)
  const [errors, setErrors] = useState({})
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.type === 'checkbox' ? e.target.checked : e.target.value })
  const percent = form.kind === 'percent'

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    try {
      if (form.id) await api.put(`/admin/coupons/${form.id}`, payload(form))
      else await api.post('/admin/coupons', payload(form))
      onSaved(form.id ? 'Coupon saved.' : `Coupon ${form.code.trim().toUpperCase()} created.`)
    } catch (err) {
      const parsed = parseError(err)
      setErrors(parsed.fields)
      setError(Object.values(parsed.fields)[0] || parsed.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="card space-y-4 p-4" noValidate>
      {error && <Alert tone="error">{error}</Alert>}
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Code" name="c-code" error={errors.code} hint="What customers type, for example SAVE10">
          <input id="c-code" className="input uppercase" maxLength={32} autoComplete="off" value={form.code} onChange={set('code')} />
        </Field>
        <Field label="Note for the team (optional)" name="c-description" error={errors.description} hint="For example: Meskel promotion">
          <input id="c-description" className="input" maxLength={160} value={form.description} onChange={set('description')} />
        </Field>
      </div>

      <fieldset>
        <legend className="label">What it takes off the items</legend>
        <div className="flex flex-wrap gap-4 text-sm">
          <label className="flex items-center gap-2"><input type="radio" name="c-kind" checked={percent} onChange={() => setForm({ ...form, kind: 'percent' })} />A percent</label>
          <label className="flex items-center gap-2"><input type="radio" name="c-kind" checked={!percent} onChange={() => setForm({ ...form, kind: 'amount' })} />An amount in birr</label>
        </div>
      </fieldset>
      <div className="grid gap-3 sm:grid-cols-3">
        {percent ? (
          <>
            <Field label="Percent off" name="c-percent" error={errors.percent_off} hint="1 to 90">
              <input id="c-percent" className="input" inputMode="numeric" value={form.percent_off} onChange={set('percent_off')} />
            </Field>
            <Field label="Most off per order (ETB, optional)" name="c-max" error={errors.max_discount} hint="Empty: no limit">
              <input id="c-max" className="input" inputMode="decimal" value={form.max_discount} onChange={set('max_discount')} />
            </Field>
          </>
        ) : (
          <Field label="Amount off (ETB)" name="c-amount" error={errors.amount_off}>
            <input id="c-amount" className="input" inputMode="decimal" value={form.amount_off} onChange={set('amount_off')} />
          </Field>
        )}
        <Field label={percent ? 'Minimum spend on items (ETB, optional)' : 'Minimum spend on items (ETB)'} name="c-min" error={errors.min_items}
          hint={percent ? 'Empty: any order' : 'Must be more than the amount off'}>
          <input id="c-min" className="input" inputMode="decimal" value={form.min_items} onChange={set('min_items')} />
        </Field>
      </div>

      <div className="grid gap-3 sm:grid-cols-4">
        <Field label="First day (optional)" name="c-starts" error={errors.starts_on}>
          <input id="c-starts" type="date" className="input" value={form.starts_on} onChange={set('starts_on')} />
        </Field>
        <Field label="Last day (optional)" name="c-ends" error={errors.ends_on}>
          <input id="c-ends" type="date" className="input" value={form.ends_on} onChange={set('ends_on')} />
        </Field>
        <Field label="Uses in total (optional)" name="c-uses" error={errors.max_uses} hint="Empty: no limit">
          <input id="c-uses" className="input" inputMode="numeric" value={form.max_uses} onChange={set('max_uses')} />
        </Field>
        <Field label="Uses per customer" name="c-per" error={errors.max_uses_per_customer} hint="Empty: no limit">
          <input id="c-per" className="input" inputMode="numeric" value={form.max_uses_per_customer} onChange={set('max_uses_per_customer')} />
        </Field>
      </div>
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={form.is_active} onChange={set('is_active')} /> On (customers can use it)
      </label>
      <p className="text-xs text-stone-500">Betoch pays every coupon. The code comes off the customer's items only, not delivery or VAT, and each store is credited its share when its order is delivered.</p>
      <div className="flex gap-2">
        <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Saving...' : 'Save coupon'}</button>
        <button type="button" className="btn-ghost" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  )
}

export default function AdminCoupons() {
  const list = useFetch('/admin/coupons')
  const [editing, setEditing] = useState(null)
  const [notice, setNotice] = useState(null)
  const coupons = list.data?.data || []

  async function remove(c) {
    if (!window.confirm(`Delete coupon ${c.code}?`)) return
    setNotice(null)
    try {
      await api.delete(`/admin/coupons/${c.id}`)
      setNotice({ tone: 'success', text: `Coupon ${c.code} deleted.` })
    } catch (err) {
      const parsed = parseError(err)
      setNotice({ tone: 'error', text: Object.values(parsed.fields)[0] || parsed.message })
    }
    list.reload()
  }

  async function toggle(c) {
    setNotice(null)
    try {
      await api.put(`/admin/coupons/${c.id}`, payload({ ...fromCoupon(c), is_active: !c.is_active }))
      setNotice({ tone: 'success', text: `Coupon ${c.code} turned ${c.is_active ? 'off' : 'on'}.` })
    } catch (err) {
      const parsed = parseError(err)
      setNotice({ tone: 'error', text: Object.values(parsed.fields)[0] || parsed.message })
    }
    list.reload()
  }

  return (
    <>
      <PageHeader title="Coupons" subtitle="Codes customers enter at checkout. Betoch pays the discount, so stores still get their full price." />
      {!editing && <div className="mb-4"><button type="button" className="btn-primary" onClick={() => { setNotice(null); setEditing(EMPTY) }}>Add coupon</button></div>}
      {notice && <div className="mb-4"><Alert tone={notice.tone}>{notice.text}</Alert></div>}
      {editing && (
        <div className="mb-6">
          <CouponForm key={editing.id || 'new'} initial={editing} onCancel={() => setEditing(null)}
            onSaved={(text) => { setEditing(null); setNotice({ tone: 'success', text }); list.reload() }} />
        </div>
      )}
      {list.loading && !list.data && <Spinner />}
      {list.error && <ErrorState error={list.error} onRetry={list.reload} />}
      {list.data && (
        <div className="card relative overflow-x-auto">
          <table className="rtable w-full text-left text-sm">
            <thead className="border-b border-stone-200 text-xs text-stone-500 uppercase">
              <tr>
                <th className="px-4 py-3">Code</th><th className="px-4 py-3">Gives</th><th className="px-4 py-3">Dates</th>
                <th className="px-4 py-3">Used</th><th className="px-4 py-3">Discount given</th><th className="px-4 py-3">Status</th>
                <th className="px-4 py-3"><span className="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-stone-100">
              {coupons.length === 0 && <tr><td colSpan={7} className="px-4 py-6 text-center text-stone-500">No coupons yet.</td></tr>}
              {coupons.map((c) => {
                const [label, tone] = STATE_LABELS[c.state] || STATE_LABELS.off
                return (
                  <tr key={c.id}>
                    <td className="rtable-title px-4 py-3">
                      <span>
                        <span className="font-mono font-semibold text-stone-900">{c.code}</span>
                        {c.description && <span className="block text-xs font-normal text-stone-500">{c.description}</span>}
                      </span>
                    </td>
                    <td data-label="Gives" className="px-4 py-3">{c.label}</td>
                    <td data-label="Dates" className="px-4 py-3 whitespace-nowrap text-stone-600">
                      {c.starts_on || c.ends_on ? `${c.starts_on ? shortDay(c.starts_on) : 'Now'} to ${c.ends_on ? shortDay(c.ends_on) : 'no end'}` : 'Any time'}
                    </td>
                    <td data-label="Used" className="px-4 py-3">
                      <span>
                        {c.uses}{c.max_uses ? ` of ${c.max_uses}` : ''}
                        <span className="block text-xs whitespace-nowrap text-stone-500">{c.max_uses_per_customer ? `${c.max_uses_per_customer} per customer` : 'No limit per customer'}</span>
                      </span>
                    </td>
                    <td data-label="Discount given" className="px-4 py-3 whitespace-nowrap">{formatETB(c.given_santim) || 'ETB 0'}</td>
                    <td data-label="Status" className="px-4 py-3"><span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${tone}`}>{label}</span></td>
                    <td className="px-4 py-3">
                      <div className="-ml-3 flex flex-wrap gap-1 whitespace-nowrap sm:ml-0 sm:flex-nowrap sm:justify-end">
                        <button type="button" className="btn-ghost" onClick={() => { setNotice(null); setEditing(fromCoupon(c)) }}>Edit</button>
                        <button type="button" className="btn-ghost" onClick={() => toggle(c)}>{c.is_active ? 'Turn off' : 'Turn on'}</button>
                        {c.deletable && <button type="button" className="btn-ghost text-red-700" onClick={() => remove(c)}>Delete</button>}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
      <p className="mt-3 text-xs text-stone-500">Used and Discount given count orders that were not cancelled. Betoch pays a store its share when the order is delivered. A code used on orders cannot be deleted; turn it off instead.</p>
    </>
  )
}
