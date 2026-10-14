import { useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { useInbox } from '../context/InboxContext'
import { api, parseError } from '../lib/api'
import { INQUIRY_TYPES } from '../lib/format'
import { Alert, Field } from './ui'

const PLACEHOLDERS = {
  question: 'Ask about size, material, finish or anything else.',
  availability: 'When could I get this? Do you have it in another color?',
  negotiation: 'What price would you accept? You can explain your offer here.',
  custom_request: 'Describe what you need: size in cm, material, color, quantity and when you need it.',
}

/**
 * In-app inquiry form. Either `product` (listing page) or `seller` (storefront) is given.
 * Only types the seller accepts are offered; the API enforces the same rules.
 */
export default function ContactSeller({ product, seller, onCancel }) {
  const { user } = useAuth()
  const { refreshUnread } = useInbox()
  const navigate = useNavigate()
  const location = useLocation()

  const types = ['question']
  if (product) {
    types.push('availability')
    if (product.price_negotiable) types.push('negotiation')
    if (product.custom_order_available) types.push('custom_request')
  } else if (seller?.offers_custom_orders) {
    types.push('custom_request')
  }

  const [type, setType] = useState(types[0])
  const [body, setBody] = useState('')
  const [subject, setSubject] = useState('')
  const [errors, setErrors] = useState({})
  const [error, setError] = useState(null)
  const [sending, setSending] = useState(false)

  if (!user) {
    return (
      <Alert>
        <Link to="/login" state={{ from: location.pathname }} className="font-semibold underline">Sign in</Link> or{' '}
        <Link to="/register" className="font-semibold underline">create a free account</Link> to message this seller.
      </Alert>
    )
  }
  if (user.role !== 'customer') {
    return <Alert>Messaging sellers needs a customer account. Store and admin accounts cannot start inquiries.</Alert>
  }

  async function submit(e) {
    e.preventDefault()
    setErrors({})
    setError(null)
    if (body.trim().length < 2) {
      setErrors({ body: 'Write a message first.' })
      return
    }
    setSending(true)
    try {
      const res = await api.post('/conversations', {
        product_slug: product?.slug ?? null,
        seller_slug: product ? null : seller.slug,
        type,
        subject: subject.trim() || null,
        body,
      })
      refreshUnread()
      navigate(`/messages/${res.data.data.id}`)
    } catch (err) {
      const parsed = parseError(err)
      setErrors(parsed.fields)
      setError(Object.values(parsed.fields)[0] || parsed.message)
      setSending(false)
    }
  }

  return (
    <form onSubmit={submit} className="card space-y-3 p-4" noValidate>
      <p className="font-semibold text-stone-900">Message {product ? 'the seller' : seller.store_name}</p>
      {error && <Alert tone="error">{error}</Alert>}
      {types.length > 1 && (
        <fieldset>
          <legend className="label">What is it about?</legend>
          <div className="flex flex-wrap gap-2">
            {types.map((t) => (
              <label key={t} className={`cursor-pointer rounded-full border px-3 py-1.5 text-sm ${type === t ? 'border-brand-700 bg-brand-50 font-semibold text-brand-800' : 'border-stone-300 text-stone-700'}`}>
                <input type="radio" name="inquiry-type" value={t} checked={type === t} onChange={() => setType(t)} className="sr-only" />
                {INQUIRY_TYPES[t]}
              </label>
            ))}
          </div>
        </fieldset>
      )}
      {!product && (
        <Field label="Subject (optional)" name="inquiry-subject" error={errors.subject}>
          <input id="inquiry-subject" className="input" maxLength={160} value={subject} onChange={(e) => setSubject(e.target.value)} />
        </Field>
      )}
      <Field label="Your message" name="inquiry-body" error={errors.body} hint="Keep payment and delivery arrangements in the app so they are covered by marketplace support.">
        <textarea id="inquiry-body" className="input min-h-28" maxLength={2000} value={body} placeholder={PLACEHOLDERS[type]}
          aria-invalid={Boolean(errors.body)} aria-describedby={errors.body ? 'inquiry-body-error' : undefined}
          onChange={(e) => setBody(e.target.value)} />
      </Field>
      <div className="flex flex-wrap gap-2">
        <button type="submit" className="btn-primary" disabled={sending}>{sending ? 'Sending...' : 'Send message'}</button>
        {onCancel && <button type="button" className="btn-ghost" onClick={onCancel}>Cancel</button>}
      </div>
    </form>
  )
}
