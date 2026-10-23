import { useEffect, useState } from 'react'
import { Alert, ErrorState, Field, PageHeader, Spinner } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { useFetch } from '../../lib/useFetch'

export default function AdminSettings() {
  const settings = useFetch('/admin/settings')
  const [form, setForm] = useState(null)
  const [notice, setNotice] = useState(null)
  const [errors, setErrors] = useState({})

  useEffect(() => {
    if (settings.data && !form) setForm({ ...settings.data.data, commission_rate_percent: String(settings.data.data.commission_rate_percent), vat_rate_percent: String(settings.data.data.vat_rate_percent) })
  }, [settings.data, form])

  if (settings.loading || !form) return settings.error ? <ErrorState error={settings.error} /> : <Spinner />

  async function save(e) {
    e.preventDefault()
    setNotice(null)
    setErrors({})
    try {
      const res = await api.put('/admin/settings', form)
      setForm({ ...res.data.data, commission_rate_percent: String(res.data.data.commission_rate_percent), vat_rate_percent: String(res.data.data.vat_rate_percent) })
      setNotice({ tone: 'success', text: 'Settings saved. New orders use them from now on.' })
    } catch (err) {
      const p = parseError(err)
      setErrors(p.fields)
      setNotice({ tone: 'error', text: Object.values(p.fields)[0] || p.message })
    }
  }

  return (
    <>
      <PageHeader title="Settings" subtitle="Changes apply to new orders only." />
      {notice && <div className="mb-4"><Alert tone={notice.tone}>{notice.text}</Alert></div>}
      <form onSubmit={save} className="card max-w-lg space-y-4 p-4" noValidate>
        <Field label="Commission rate (%)" name="commission" error={errors.commission_rate_percent} hint="Charged on item prices, not on delivery fees. Up to 2 decimals, maximum 50.">
          <input id="commission" className="input" inputMode="decimal" value={form.commission_rate_percent}
            onChange={(e) => setForm({ ...form, commission_rate_percent: e.target.value })} />
        </Field>
        <Field label="VAT rate (%)" name="vat-rate" error={errors.vat_rate_percent} hint="Ethiopia: 15%. Added at checkout on every order, on the items and the delivery fee, and shown as its own line. Orders keep the rate of the day they were placed. 0 turns VAT off.">
          <input id="vat-rate" className="input" inputMode="decimal" value={form.vat_rate_percent}
            onChange={(e) => setForm({ ...form, vat_rate_percent: e.target.value })} />
        </Field>
        <label className="flex items-start gap-2 text-sm">
          <input type="checkbox" className="mt-1" checked={form.cod_enabled} onChange={(e) => setForm({ ...form, cod_enabled: e.target.checked })} />
          <span><span className="font-medium">Accept cash on delivery</span><br /><span className="text-stone-500">With this off, customers can only pay online (and checkout pauses if online payment is off too).</span></span>
        </label>
        <label className="flex items-start gap-2 text-sm">
          <input type="checkbox" className="mt-1" checked={!!form.online_payments_enabled} onChange={(e) => setForm({ ...form, online_payments_enabled: e.target.checked })} />
          <span>
            <span className="font-medium">Accept online payment</span><br />
            <span className="text-stone-500">
              {settings.data.meta?.payment_gateway_ready
                ? `Through ${settings.data.meta.payment_gateway === 'fake' ? 'the test payment page' : 'Chapa'}${settings.data.meta.payment_test_mode ? ' in test mode (no real money moves)' : ''}. Customers have ${settings.data.meta.payment_window_minutes} minutes to pay before the order is cancelled.`
                : 'No payment provider is set up on the server yet (Chapa keys), so customers cannot choose it.'}
            </span>
          </span>
        </label>
        <Field label="Commission credit limit (ETB)" name="credit-limit" error={errors.commission_credit_limit_birr} hint="A store that owes more than this cannot confirm new cash orders until it pays (orders paid online still go ahead). 0 means no limit.">
          <input id="credit-limit" className="input" inputMode="numeric" value={form.commission_credit_limit_birr}
            onChange={(e) => setForm({ ...form, commission_credit_limit_birr: e.target.value })} />
        </Field>
        <label className="flex items-start gap-2 text-sm">
          <input type="checkbox" className="mt-1" checked={form.sms_enabled} onChange={(e) => setForm({ ...form, sms_enabled: e.target.checked })} />
          <span>
            <span className="font-medium">Send text message (SMS) alerts</span><br />
            <span className="text-stone-500">
              {settings.data.meta?.sms_delivers
                ? `Sent through ${settings.data.meta.sms_driver}. Turn off to pause all texts; in-app and email alerts continue.`
                : 'No SMS provider is set up yet, so texts are only logged in Text messages. In-app and email alerts work either way.'}
            </span>
          </span>
        </label>
        <label className="flex items-start gap-2 text-sm">
          <input type="checkbox" className="mt-1" checked={!!form.admins_must_use_two_step} onChange={(e) => setForm({ ...form, admins_must_use_two_step: e.target.checked })}
            aria-invalid={errors.admins_must_use_two_step ? true : undefined} />
          <span>
            <span className="font-medium">Administrators must use two-step sign-in</span><br />
            <span className="text-stone-500">
              {!settings.data.meta?.sign_in_codes_available
                ? 'Needs an SMS provider on the server first, so codes can be texted.'
                : settings.data.meta.admins_without_checked_phone?.length
                  ? `Every administrator signs in with a texted code after the password. First, these administrators must check their phone in My account: ${settings.data.meta.admins_without_checked_phone.join(', ')}.`
                  : 'Every administrator signs in with a texted code after the password, and cannot turn it off.'}
            </span>
          </span>
        </label>
        <label className="flex items-start gap-2 text-sm">
          <input type="checkbox" className="mt-1" checked={!!form.everyone_must_use_two_step} onChange={(e) => setForm({ ...form, everyone_must_use_two_step: e.target.checked })}
            aria-invalid={errors.everyone_must_use_two_step ? true : undefined} />
          <span>
            <span className="font-medium">Everyone must use two-step sign-in</span><br />
            <span className="text-stone-500">
              {!settings.data.meta?.sign_in_codes_available
                ? 'Needs an SMS provider on the server first, so codes can be texted.'
                : `Customers, stores and admins check a mobile number when they sign up, and type a texted code at every sign-in. Each sign-in costs one text. ${settings.data.meta.accounts_without_checked_phone} existing ${settings.data.meta.accounts_without_checked_phone === 1 ? 'account has' : 'accounts have'} no checked phone yet; they add one the next time they sign in. People already signed in stay signed in.`}
            </span>
          </span>
        </label>
        <Field label="How sellers pay their commission" name="instructions" error={errors.settlement_instructions} hint="Shown on every seller's balance page. For example the bank account and what to write as the reference.">
          <textarea id="instructions" className="input min-h-24" maxLength={1000} value={form.settlement_instructions}
            onChange={(e) => setForm({ ...form, settlement_instructions: e.target.value })} />
        </Field>
        <button type="submit" className="btn-primary">Save settings</button>
      </form>
    </>
  )
}
