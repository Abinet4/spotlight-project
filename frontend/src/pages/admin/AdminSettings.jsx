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
    if (settings.data && !form) setForm({ ...settings.data.data, commission_rate_percent: String(settings.data.data.commission_rate_percent) })
  }, [settings.data, form])

  if (settings.loading || !form) return settings.error ? <ErrorState error={settings.error} /> : <Spinner />

  async function save(e) {
    e.preventDefault()
    setNotice(null)
    setErrors({})
    try {
      const res = await api.put('/admin/settings', form)
      setForm({ ...res.data.data, commission_rate_percent: String(res.data.data.commission_rate_percent) })
      setNotice({ tone: 'success', text: 'Settings saved. New orders use them from now on.' })
    } catch (err) {
      const p = parseError(err)
      setErrors(p.fields)
      setNotice({ tone: 'error', text: Object.values(p.fields)[0] || p.message })
    }
  }

  return (
    <>
      <PageHeader title="Settings" subtitle="Changes apply to orders placed after saving. Existing orders keep the rate they were placed with." />
      {notice && <div className="mb-4"><Alert tone={notice.tone}>{notice.text}</Alert></div>}
      <form onSubmit={save} className="card max-w-lg space-y-4 p-4" noValidate>
        <Field label="Commission rate (%)" name="commission" error={errors.commission_rate_percent} hint="Charged on item prices, not on delivery fees. Up to 2 decimals, maximum 50.">
          <input id="commission" className="input" inputMode="decimal" value={form.commission_rate_percent}
            onChange={(e) => setForm({ ...form, commission_rate_percent: e.target.value })} />
        </Field>
        <label className="flex items-start gap-2 text-sm">
          <input type="checkbox" className="mt-1" checked={form.cod_enabled} onChange={(e) => setForm({ ...form, cod_enabled: e.target.checked })} />
          <span><span className="font-medium">Accept cash on delivery</span><br /><span className="text-stone-500">Turning this off pauses checkout until an online payment method is added.</span></span>
        </label>
        <button type="submit" className="btn-primary">Save settings</button>
      </form>
    </>
  )
}
