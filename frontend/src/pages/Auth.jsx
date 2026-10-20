import { useState } from 'react'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import { CodeEntry } from '../components/CodeEntry'
import { Alert, Field } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { useConfig } from '../context/ConfigContext'
import { api, parseError } from '../lib/api'
import { BUSINESS_TYPES } from '../lib/format'
import { useFetch } from '../lib/useFetch'

function homeFor(user) {
  if (user.role === 'admin') return '/admin'
  if (user.role === 'seller') return '/seller'
  return '/'
}

function useForm(initial) {
  const [values, setValues] = useState(initial)
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)
  const [busy, setBusy] = useState(false)

  const bind = (name, type = 'text') => ({
    id: name,
    name,
    type,
    value: type === 'checkbox' ? undefined : values[name] ?? '',
    checked: type === 'checkbox' ? Boolean(values[name]) : undefined,
    onChange: (e) => setValues((v) => ({ ...v, [name]: type === 'checkbox' ? e.target.checked : e.target.value })),
    'aria-invalid': errors[name] ? true : undefined,
    'aria-describedby': errors[name] ? `${name}-error` : undefined,
  })

  async function submit(fn) {
    setBusy(true)
    setErrors({})
    setMessage(null)
    try {
      await fn(values)
    } catch (err) {
      const parsed = parseError(err)
      setErrors(parsed.fields)
      // An error about something not on this form (like the texted code) is shown as the message.
      const offForm = Object.keys(parsed.fields).find((k) => !(k in values))
      setMessage(offForm ? parsed.fields[offForm] : Object.keys(parsed.fields).length ? 'Please fix the highlighted fields.' : parsed.message)
    } finally {
      setBusy(false)
    }
  }

  return { values, errors, message, busy, bind, submit }
}

function AuthCard({ title, subtitle, children, wide = false }) {
  return (
    <div className="container-page py-10">
      <div className={`card mx-auto p-6 sm:p-8 ${wide ? 'max-w-2xl' : 'max-w-md'}`}>
        <h1 className="text-2xl font-bold text-stone-900">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-stone-600">{subtitle}</p>}
        <div className="mt-6">{children}</div>
      </div>
    </div>
  )
}

/** Where to go after signing in. An administrator who must use two-step but has no checked phone adds one first. */
function afterSignIn(user, from) {
  if (user.two_step_required && !user.phone_verified) return '/account'
  return from || homeFor(user)
}

export function Login() {
  const { user, signIn } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const form = useForm({ email: '', password: '' })
  const [step, setStep] = useState(null)

  if (user) return <Navigate to={afterSignIn(user, location.state?.from)} replace />

  function finish(data) {
    signIn(data.token, data.user)
    navigate(afterSignIn(data.user, location.state?.from), { replace: true })
  }

  if (step) {
    return (
      <TwoStepForm
        key={step.challenge}
        step={step}
        onDone={finish}
        onRestart={() => setStep(null)}
      />
    )
  }

  return (
    <AuthCard title="Sign in" subtitle="Welcome back.">
      <form
        className="space-y-4"
        noValidate
        onSubmit={(e) => {
          e.preventDefault()
          form.submit(async (v) => {
            const res = await api.post('/auth/login', v)
            if (res.data.two_step) setStep(res.data.two_step)
            else finish(res.data)
          })
        }}
      >
        {form.message && <Alert tone="error">{form.message}</Alert>}
        <Field label="Email" name="email" error={form.errors.email}>
          <input className="input" autoComplete="email" required {...form.bind('email', 'email')} />
        </Field>
        <Field label="Password" name="password" error={form.errors.password}>
          <input className="input" autoComplete="current-password" required {...form.bind('password', 'password')} />
        </Field>
        <button type="submit" className="btn-primary w-full" disabled={form.busy}>{form.busy ? 'Signing in...' : 'Sign in'}</button>
      </form>
      <p className="mt-6 text-center text-sm text-stone-600">
        New here? <Link to="/register" className="font-semibold text-brand-700 hover:underline">Create an account</Link>
      </p>
    </AuthCard>
  )
}

// Errors after which this code can no longer work: the only way on is the password again.
const DEAD_END = /expired|Too many wrong codes|No more codes/

/**
 * The code step after the password (or after signing up). An account with no checked phone yet
 * first gives a mobile number; the code then goes there, and that number becomes the account's.
 */
function TwoStepForm({ step, onDone, onRestart, signUp = false }) {
  const [pending, setPending] = useState(step)
  const [askPhone, setAskPhone] = useState(step.needs_phone)
  const [phone, setPhone] = useState('')
  const [code, setCode] = useState('')
  const [error, setError] = useState(step.error || null)
  const [phoneError, setPhoneError] = useState(null)
  const [notice, setNotice] = useState(null)
  const [busy, setBusy] = useState(false)
  const [resendsLeft, setResendsLeft] = useState(step.resends_left ?? null)
  const addsPhone = signUp || step.needs_phone

  function show(err) {
    const p = parseError(err)
    setError(p.fields.code || p.fields.phone || p.fields.challenge || p.message)
  }

  async function submit(e) {
    e.preventDefault()
    if (code.replace(/\D/g, '').length !== 6) {
      setError('Enter the 6 digits from the text.')
      return
    }
    setBusy(true)
    setError(null)
    setNotice(null)
    try {
      const res = await api.post('/auth/two-step', { challenge: step.challenge, code })
      onDone(res.data)
    } catch (err) {
      show(err)
      setCode('')
    } finally {
      setBusy(false)
    }
  }

  async function sendToPhone(e) {
    e.preventDefault()
    setBusy(true)
    setPhoneError(null)
    setError(null)
    setNotice(null)
    try {
      const res = await api.post('/auth/two-step/phone', { challenge: step.challenge, phone })
      setPending(res.data.two_step)
      setResendsLeft(res.data.two_step.resends_left)
      setCode('')
      setAskPhone(false)
    } catch (err) {
      const p = parseError(err)
      setPhoneError(p.fields.phone || p.fields.code || p.message)
    } finally {
      setBusy(false)
    }
  }

  async function resend() {
    setBusy(true)
    setError(null)
    setNotice(null)
    try {
      const res = await api.post('/auth/two-step/resend', { challenge: step.challenge })
      setResendsLeft(res.data.two_step.resends_left)
      setNotice(`We texted a new code to ${res.data.two_step.phone}. Earlier codes no longer work.`)
      return res.data.two_step.resend_in
    } catch (err) {
      show(err)
      return null
    } finally {
      setBusy(false)
    }
  }

  const deadEnd = error && DEAD_END.test(error)
  const restartLabel = signUp ? 'Go to sign in' : 'Sign in again'

  if (askPhone) {
    const dead = phoneError && DEAD_END.test(phoneError)
    return (
      <AuthCard title="Add your phone" subtitle="Signing in now needs a code texted to your phone. Enter your mobile number and we will text you a code.">
        <form className="space-y-4" noValidate onSubmit={sendToPhone}>
          <Field label="Mobile number" name="tsi-phone" error={phoneError} hint="An Ethiopian mobile, for example 0911 234 567.">
            <input id="tsi-phone" className="input" inputMode="tel" autoComplete="tel" maxLength={20} autoFocus value={phone}
              onChange={(e) => setPhone(e.target.value)} aria-invalid={phoneError ? true : undefined} aria-describedby={phoneError ? 'tsi-phone-error' : undefined} />
          </Field>
          {dead
            ? <button type="button" className="btn-primary w-full" onClick={onRestart}>{restartLabel}</button>
            : <button type="submit" className="btn-primary w-full" disabled={busy}>{busy ? 'Sending...' : 'Text me a code'}</button>}
        </form>
        <p className="mt-6 text-center text-sm text-stone-600">
          <button type="button" className="font-semibold text-brand-700 hover:underline" onClick={onRestart}>Use a different account</button>
        </p>
      </AuthCard>
    )
  }

  return (
    <AuthCard
      title={addsPhone ? 'Check your phone' : 'Enter your code'}
      subtitle={addsPhone ? 'We texted you a code to make sure this number is yours.' : 'Your account uses two-step sign-in, so we texted you a code.'}
    >
      <form className="space-y-4" noValidate onSubmit={submit}>
        {notice && <Alert tone="success">{notice}</Alert>}
        <CodeEntry
          key={`${pending.phone}|${pending.resends_left}`}
          id="code"
          phone={pending.phone}
          value={code}
          onChange={setCode}
          error={error}
          resendIn={pending.resend_in}
          resendsLeft={deadEnd ? 0 : resendsLeft}
          onResend={resend}
          busy={busy}
        />
        {deadEnd
          ? <button type="button" className="btn-primary w-full" onClick={onRestart}>{restartLabel}</button>
          : <button type="submit" className="btn-primary w-full" disabled={busy}>{busy ? 'Checking...' : signUp ? 'Continue' : 'Sign in'}</button>}
      </form>
      <p className="mt-6 text-center text-sm text-stone-600">
        {addsPhone
          ? <button type="button" className="font-semibold text-brand-700 hover:underline" onClick={() => { setAskPhone(true); setPhoneError(null) }}>Wrong number? Use another one</button>
          : <button type="button" className="font-semibold text-brand-700 hover:underline" onClick={onRestart}>Use a different account</button>}
      </p>
      {!addsPhone && <p className="mt-2 text-center text-xs text-stone-500">Lost your phone? Contact support and we can turn two-step sign-in off after checking it is you.</p>}
    </AuthCard>
  )
}

function AccountFields({ form }) {
  const { phone_required: phoneRequired } = useConfig()
  return (
    <>
      <Field label="Full name" name="name" error={form.errors.name}>
        <input className="input" autoComplete="name" {...form.bind('name')} />
      </Field>
      <Field label="Email" name="email" error={form.errors.email}>
        <input className="input" autoComplete="email" {...form.bind('email', 'email')} />
      </Field>
      <Field label={phoneRequired ? 'Mobile number' : 'Phone (optional)'} name="phone" error={form.errors.phone}
        hint={phoneRequired ? 'An Ethiopian mobile, for example 0911 234 567. We text you a code to check it.' : 'For example +251 911 234 567'}>
        <input className="input" autoComplete="tel" inputMode="tel" {...form.bind('phone', 'tel')} />
      </Field>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Password" name="password" error={form.errors.password} hint="At least 8 characters with letters and numbers">
          <input className="input" autoComplete="new-password" {...form.bind('password', 'password')} />
        </Field>
        <Field label="Confirm password" name="password_confirmation" error={form.errors.password_confirmation}>
          <input className="input" autoComplete="new-password" {...form.bind('password_confirmation', 'password')} />
        </Field>
      </div>
    </>
  )
}

export function Register() {
  const { user, signIn } = useAuth()
  const navigate = useNavigate()
  const form = useForm({ name: '', email: '', phone: '', password: '', password_confirmation: '' })
  const [step, setStep] = useState(null)

  if (user) return <Navigate to={homeFor(user)} replace />

  function finish(data) {
    signIn(data.token, data.user)
    navigate('/', { replace: true })
  }

  if (step) return <TwoStepForm key={step.challenge} step={step} signUp onDone={finish} onRestart={() => navigate('/login')} />

  return (
    <AuthCard title="Create your account" subtitle="Save favorites, order furniture and ask Betoch Support anything.">
      <form
        className="space-y-4"
        noValidate
        onSubmit={(e) => {
          e.preventDefault()
          form.submit(async (v) => {
            const res = await api.post('/auth/register', v)
            if (res.data.two_step) setStep(res.data.two_step)
            else finish(res.data)
          })
        }}
      >
        {form.message && <Alert tone="error">{form.message}</Alert>}
        <AccountFields form={form} />
        <button type="submit" className="btn-primary w-full" disabled={form.busy}>{form.busy ? 'Creating account...' : 'Create account'}</button>
      </form>
      <p className="mt-6 text-center text-sm text-stone-600">
        Already registered? <Link to="/login" className="font-semibold text-brand-700 hover:underline">Sign in</Link>
        <br />
        Want to sell furniture? <Link to="/sell" className="font-semibold text-brand-700 hover:underline">Apply as a seller</Link>
      </p>
    </AuthCard>
  )
}

export function SellerApply() {
  const { user, signIn } = useAuth()
  const navigate = useNavigate()
  const areas = useFetch('/areas')
  const form = useForm({
    name: '', email: '', phone: '', password: '', password_confirmation: '',
    store_name: '', business_type: 'workshop', store_phone: '', area_id: '', address_line: '',
    description: '', tin_number: '', offers_custom_orders: false, offers_delivery: true,
  })
  const [step, setStep] = useState(null)

  if (user?.role === 'seller') return <Navigate to="/seller" replace />
  if (user) {
    return (
      <AuthCard title="Apply to sell">
        <Alert tone="info">
          You are signed in as a customer. Seller accounts are separate for now: sign out and apply with a different email, or contact support to convert this account.
        </Alert>
      </AuthCard>
    )
  }

  function finish(data) {
    signIn(data.token, data.user)
    navigate('/seller', { replace: true })
  }

  if (step) return <TwoStepForm key={step.challenge} step={step} signUp onDone={finish} onRestart={() => navigate('/login')} />

  return (
    <AuthCard wide title="Sell your furniture" subtitle="Create a seller account. Our team reviews every store before listings go live, usually within two business days.">
      <form
        className="space-y-6"
        noValidate
        onSubmit={(e) => {
          e.preventDefault()
          form.submit(async (v) => {
            const res = await api.post('/auth/register-seller', v)
            if (res.data.two_step) setStep(res.data.two_step)
            else finish(res.data)
          })
        }}
      >
        {form.message && <Alert tone="error">{form.message}</Alert>}
        <fieldset className="space-y-4">
          <legend className="mb-2 text-sm font-bold tracking-wide text-stone-500 uppercase">Your account</legend>
          <AccountFields form={form} />
        </fieldset>
        <fieldset className="space-y-4">
          <legend className="mb-2 text-sm font-bold tracking-wide text-stone-500 uppercase">Your store</legend>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Store name" name="store_name" error={form.errors.store_name}>
              <input className="input" {...form.bind('store_name')} />
            </Field>
            <Field label="Business type" name="business_type" error={form.errors.business_type}>
              <select className="input" {...form.bind('business_type')}>
                {Object.entries(BUSINESS_TYPES).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </Field>
            <Field label="Store phone (for Betoch and order texts, not shown to customers)" name="store_phone" error={form.errors.store_phone}>
              <input className="input" inputMode="tel" {...form.bind('store_phone', 'tel')} />
            </Field>
            <Field label="Sub-city" name="area_id" error={form.errors.area_id}>
              <select className="input" {...form.bind('area_id')}>
                <option value="">Choose a sub-city</option>
                {(areas.data?.data || []).map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
              </select>
            </Field>
          </div>
          <Field label="Address or landmark (optional)" name="address_line" error={form.errors.address_line}>
            <input className="input" {...form.bind('address_line')} />
          </Field>
          <Field label="About your store (optional)" name="description" error={form.errors.description}>
            <textarea rows={3} className="input" {...form.bind('description')} />
          </Field>
          <Field label="TIN number (optional, speeds up verification)" name="tin_number" error={form.errors.tin_number}>
            <input className="input" {...form.bind('tin_number')} />
          </Field>
          <div className="flex flex-col gap-2 text-sm">
            <label className="flex items-center gap-2"><input className="size-4 accent-brand-700" {...form.bind('offers_delivery', 'checkbox')} /> I can deliver within Addis Ababa</label>
            <label className="flex items-center gap-2"><input className="size-4 accent-brand-700" {...form.bind('offers_custom_orders', 'checkbox')} /> I accept custom furniture orders</label>
          </div>
        </fieldset>
        <button type="submit" className="btn-primary w-full" disabled={form.busy}>{form.busy ? 'Submitting...' : 'Submit application'}</button>
      </form>
    </AuthCard>
  )
}
