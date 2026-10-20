import { useState } from 'react'
import { CodeEntry } from '../components/CodeEntry'
import { Link } from 'react-router-dom'
import { Alert, ErrorState, Field, PageHeader, Spinner } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { api, parseError } from '../lib/api'
import { useFetch } from '../lib/useFetch'

const ROLE_LABEL = { customer: 'Customer account', seller: 'Seller account', admin: 'Administrator' }

const sameEmail = (a, b) => (a || '').trim().toLowerCase() === (b || '').trim().toLowerCase()

function useSubmit() {
  const [errors, setErrors] = useState({})
  const [notice, setNotice] = useState(null)
  const [busy, setBusy] = useState(false)

  async function run(action) {
    setBusy(true)
    setErrors({})
    setNotice(null)
    try {
      await action()
    } catch (err) {
      const p = parseError(err)
      setErrors(p.fields)
      setNotice({ tone: 'error', text: Object.values(p.fields)[0] || p.message })
    } finally {
      setBusy(false)
    }
  }

  return { errors, notice, setNotice, busy, run }
}

function DetailsForm({ profile, onSaved }) {
  const { refresh } = useAuth()
  const [form, setForm] = useState({ name: profile.name, email: profile.email, phone: profile.phone || '', current_password: '' })
  const { errors, notice, setNotice, busy, run } = useSubmit()
  const emailChanging = !sameEmail(form.email, profile.email)
  const phoneLocked = profile.two_step || (profile.two_step_required && profile.phone_verified)
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.value })
  const invalid = (key) => ({ 'aria-invalid': Boolean(errors[key]), 'aria-describedby': errors[key] ? `${key}-error` : undefined })

  function save(e) {
    e.preventDefault()
    run(async () => {
      const payload = { name: form.name, email: form.email }
      if (!phoneLocked) payload.phone = form.phone
      if (emailChanging) payload.current_password = form.current_password
      const res = await api.put('/account/profile', payload)
      const saved = res.data.data
      onSaved(saved)
      setForm({ name: saved.name, email: saved.email, phone: saved.phone || '', current_password: '' })
      setNotice({ tone: 'success', text: emailChanging ? `Saved. We sent a notice to your old address, ${profile.email}.` : 'Your details are saved.' })
      refresh()
    })
  }

  return (
    <section aria-labelledby="details-title" className="card p-4">
      <h2 id="details-title" className="text-lg font-semibold text-stone-900">Your details</h2>
      <form onSubmit={save} className="mt-4 space-y-4" noValidate>
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
        <Field label="Full name" name="name" error={errors.name}>
          <input id="name" className="input" autoComplete="name" maxLength={100} value={form.name} onChange={set('name')} {...invalid('name')} />
        </Field>
        <Field label="Email" name="email" error={errors.email} hint="You sign in with this email, and order emails go here.">
          <input id="email" type="email" className="input" autoComplete="email" maxLength={190} value={form.email} onChange={set('email')} {...invalid('email')} />
        </Field>
        {emailChanging && (
          <Field label="Current password" name="current_password" error={errors.current_password} hint="Needed to change your email.">
            <input id="current_password" type="password" className="input" autoComplete="current-password" value={form.current_password} onChange={set('current_password')} {...invalid('current_password')} />
          </Field>
        )}
        <Field label="Phone (optional)" name="phone" error={errors.phone} hint={phoneLocked ? 'Sign-in codes go to this number. Change it under Two-step sign-in below.' : 'For example 0911 234 567. Order texts go to this number if it is a mobile.'}>
          <input id="phone" className="input" inputMode="tel" autoComplete="tel" maxLength={16} value={form.phone} onChange={set('phone')} readOnly={phoneLocked} {...invalid('phone')} />
        </Field>
        <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Saving...' : 'Save details'}</button>
      </form>
    </section>
  )
}

function PasswordForm({ role, onSaved }) {
  const empty = { current_password: '', password: '', password_confirmation: '' }
  const [form, setForm] = useState(empty)
  const { errors, notice, setNotice, busy, run } = useSubmit()
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.value })
  const invalid = (key) => ({ 'aria-invalid': Boolean(errors[key]), 'aria-describedby': errors[key] ? `pw-${key}-error` : undefined })
  const rule = role === 'admin'
    ? 'At least 12 characters, with letters, numbers and a symbol.'
    : 'At least 8 characters, with letters and numbers.'

  function save(e) {
    e.preventDefault()
    run(async () => {
      const res = await api.put('/account/password', form)
      onSaved(res.data.data)
      setForm(empty)
      const n = res.data.data.signed_out
      setNotice({ tone: 'success', text: n > 0 ? `Password changed. ${n === 1 ? 'Your other device was' : `Your ${n} other devices were`} signed out.` : 'Password changed.' })
    })
  }

  return (
    <section aria-labelledby="password-title" className="card p-4">
      <h2 id="password-title" className="text-lg font-semibold text-stone-900">Change password</h2>
      <p className="mt-1 text-sm text-stone-600">Changing it signs you out on every other device.</p>
      <form onSubmit={save} className="mt-4 space-y-4" noValidate>
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
        <Field label="Current password" name="pw-current_password" error={errors.current_password}>
          <input id="pw-current_password" type="password" className="input" autoComplete="current-password" value={form.current_password} onChange={set('current_password')} {...invalid('current_password')} />
        </Field>
        <Field label="New password" name="pw-password" error={errors.password} hint={rule}>
          <input id="pw-password" type="password" className="input" autoComplete="new-password" maxLength={200} value={form.password} onChange={set('password')} {...invalid('password')} />
        </Field>
        <Field label="Repeat new password" name="pw-password_confirmation" error={errors.password_confirmation}>
          <input id="pw-password_confirmation" type="password" className="input" autoComplete="new-password" maxLength={200} value={form.password_confirmation} onChange={set('password_confirmation')} {...invalid('password_confirmation')} />
        </Field>
        <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Saving...' : 'Change password'}</button>
      </form>
    </section>
  )
}

function TwoStepCard({ profile, onSaved }) {
  const { refresh } = useAuth()
  const { errors, notice, setNotice, busy, run } = useSubmit()
  const [phone, setPhone] = useState(profile.phone_verified ? '' : profile.phone || '')
  const [password, setPassword] = useState('')
  const [pending, setPending] = useState(null)
  const [code, setCode] = useState('')
  const [changing, setChanging] = useState(!profile.phone_verified)
  const [resendsLeft, setResendsLeft] = useState(null)
  const codesGoThere = profile.two_step || profile.two_step_required
  const save = (data) => {
    onSaved({ ...profile, ...data })
    refresh()
  }

  if (!profile.codes_available) {
    return (
      <section aria-labelledby="two-step-title" className="card p-4">
        <h2 id="two-step-title" className="text-lg font-semibold text-stone-900">Two-step sign-in</h2>
        <p className="mt-1 text-sm text-stone-600">Texting sign-in codes is not set up on this site yet.</p>
      </section>
    )
  }

  function sendCode(e) {
    e.preventDefault()
    run(async () => {
      const payload = { phone }
      if (codesGoThere) payload.current_password = password
      const res = await api.post('/account/phone/code', payload)
      setPending(res.data.data)
      setResendsLeft(null)
      setCode('')
    })
  }

  async function resend() {
    let next = null
    await run(async () => {
      const res = await api.post('/account/phone/resend')
      setNotice({ tone: 'success', text: `We texted a new code to ${res.data.data.phone}. Earlier codes no longer work.` })
      setResendsLeft(res.data.data.resends_left)
      next = res.data.data.resend_in
    })
    return next
  }

  function verify(e) {
    e.preventDefault()
    run(async () => {
      const res = await api.post('/account/phone/verify', { code })
      save(res.data.data)
      setPending(null)
      setPassword('')
      setPhone('')
      setChanging(false)
      setNotice({ tone: 'success', text: res.data.data.two_step || res.data.data.two_step_required ? 'Number checked. Sign-in codes now go to it.' : 'Number checked. You can now turn on two-step sign-in.' })
    })
  }

  function toggle(e) {
    e.preventDefault()
    run(async () => {
      const res = await api.put('/account/two-step', { enabled: !profile.two_step, current_password: password })
      save(res.data.data)
      setPassword('')
      setNotice({ tone: 'success', text: res.data.data.two_step ? 'Two-step sign-in is on. Next time you sign in, we will text you a code.' : 'Two-step sign-in is off.' })
    })
  }

  const passwordField = (id) => (
    <Field label="Current password" name={id} error={errors.current_password}>
      <input id={id} type="password" className="input" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)}
        aria-invalid={Boolean(errors.current_password)} aria-describedby={errors.current_password ? `${id}-error` : undefined} />
    </Field>
  )

  return (
    <section aria-labelledby="two-step-title" className="card p-4">
      <h2 id="two-step-title" className="text-lg font-semibold text-stone-900">Two-step sign-in</h2>
      <p className="mt-1 text-sm text-stone-600">
        {profile.two_step || profile.two_step_required
          ? 'After your password, we text a code to your phone. Someone who learns your password still cannot sign in.'
          : 'Add a texted code after your password, so someone who learns your password still cannot sign in.'}
      </p>
      <div className="mt-4 space-y-4">
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

        {profile.phone_verified && !changing && (
          <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
            <span><span className="text-stone-500">Checked phone:</span> <span className="font-medium">{profile.phone}</span></span>
            <button type="button" className="btn-secondary" onClick={() => { setChanging(true); setNotice(null) }}>Change number</button>
          </div>
        )}

        {changing && !pending && (
          <form onSubmit={sendCode} className="space-y-4" noValidate>
            <Field label={profile.phone_verified ? 'New mobile number' : 'Mobile number'} name="ts-phone" error={errors.phone} hint="An Ethiopian mobile, for example 0911 234 567. We text a code to check it is yours.">
              <input id="ts-phone" className="input" inputMode="tel" autoComplete="tel" maxLength={20} value={phone} onChange={(e) => setPhone(e.target.value)}
                aria-invalid={Boolean(errors.phone)} aria-describedby={errors.phone ? 'ts-phone-error' : undefined} />
            </Field>
            {codesGoThere && passwordField('ts-phone-password')}
            <div className="flex flex-wrap gap-2">
              <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Sending...' : 'Text me a code'}</button>
              {profile.phone_verified && <button type="button" className="btn-secondary" onClick={() => { setChanging(false); setNotice(null) }}>Cancel</button>}
            </div>
          </form>
        )}

        {pending && (
          <form onSubmit={verify} className="space-y-4" noValidate>
            <CodeEntry id="ts-code" phone={pending.phone} value={code} onChange={setCode} error={errors.code} resendIn={pending.resend_in} resendsLeft={resendsLeft} onResend={resend} busy={busy} />
            <div className="flex flex-wrap gap-2">
              <button type="submit" className="btn-primary" disabled={busy || code.replace(/\D/g, '').length !== 6}>{busy ? 'Checking...' : 'Check code'}</button>
              <button type="button" className="btn-secondary" onClick={() => { setPending(null); setNotice(null) }}>Use another number</button>
            </div>
          </form>
        )}

        {profile.phone_verified && !changing && (
          profile.two_step_required ? (
            <p className="text-sm text-stone-600">Two-step sign-in is required{profile.role === 'admin' ? ' (Admin > Settings)' : ' on this site'}, so it stays on.</p>
          ) : (
            <form onSubmit={toggle} className="space-y-4 border-t border-stone-100 pt-4" noValidate>
              <p className="text-sm">Two-step sign-in is <span className="font-semibold">{profile.two_step ? 'on' : 'off'}</span>.</p>
              {passwordField('ts-password')}
              <button type="submit" className={profile.two_step ? 'btn-secondary' : 'btn-primary'} disabled={busy}>
                {profile.two_step ? 'Turn off two-step sign-in' : 'Turn on two-step sign-in'}
              </button>
            </form>
          )
        )}
      </div>
    </section>
  )
}

function DevicesCard({ profile, onSaved }) {
  const { notice, setNotice, busy, run } = useSubmit()
  const others = profile.other_sessions

  function signOutOthers() {
    run(async () => {
      const res = await api.post('/account/sign-out-others')
      onSaved(res.data.data)
      setNotice({ tone: 'success', text: 'Signed out of your other devices.' })
    })
  }

  return (
    <section aria-labelledby="devices-title" className="card p-4">
      <h2 id="devices-title" className="text-lg font-semibold text-stone-900">Signed-in devices</h2>
      <div className="mt-2 space-y-3">
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
        <p className="text-sm text-stone-600">
          {others > 0
            ? `You are also signed in on ${others} other ${others === 1 ? 'device or browser' : 'devices or browsers'}.`
            : 'You are not signed in anywhere else.'}
        </p>
        <button type="button" className="btn-secondary" disabled={busy || others === 0} onClick={signOutOthers}>
          Sign out other devices
        </button>
      </div>
    </section>
  )
}

export default function Account() {
  const res = useFetch('/account/profile')

  if (res.loading && !res.data) return <Spinner />
  if (res.error) return <div className="container-page py-8"><ErrorState error={res.error} onRetry={res.reload} /></div>

  const profile = res.data.data
  const onSaved = (data) => res.setData({ data })
  const since = profile.created_at ? new Date(profile.created_at).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }) : null

  return (
    <div className="container-page max-w-2xl py-8">
      <PageHeader title="My account" subtitle={[ROLE_LABEL[profile.role], since && `member since ${since}`].filter(Boolean).join(', ')} />
      <div className="space-y-6">
        {profile.two_step_required && !profile.phone_verified && (
          <Alert tone="warning">Two-step sign-in is required on this site. Check your phone number below; until then this account is less protected.</Alert>
        )}
        <DetailsForm key={`${profile.phone}|${profile.phone_verified}`} profile={profile} onSaved={onSaved} />
        <TwoStepCard profile={profile} onSaved={onSaved} />
        <PasswordForm role={profile.role} onSaved={onSaved} />
        <DevicesCard profile={profile} onSaved={onSaved} />
        <nav aria-label="More settings" className="card divide-y divide-stone-100">
          <Link to="/account/alerts" className="block p-4 text-sm hover:bg-stone-50">
            <span className="font-medium text-stone-900">Alert settings</span>
            <span className="block text-stone-600">Text messages and email for orders and messages.</span>
          </Link>
          {profile.role === 'customer' && (
            <Link to="/account/addresses" className="block p-4 text-sm hover:bg-stone-50">
              <span className="font-medium text-stone-900">Delivery addresses</span>
              <span className="block text-stone-600">Saved addresses for checkout.</span>
            </Link>
          )}
          {profile.role === 'seller' && (
            <Link to="/seller/profile" className="block p-4 text-sm hover:bg-stone-50">
              <span className="font-medium text-stone-900">Store profile</span>
              <span className="block text-stone-600">Store name, phone, location and description shown to customers.</span>
            </Link>
          )}
        </nav>
      </div>
    </div>
  )
}
