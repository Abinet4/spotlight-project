import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Alert, Pagination, Spinner } from './ui'
import { api, parseError } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { useFetch } from '../lib/useFetch'

const STAR = 'M10 1.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L10 14.9l-5.2 2.7 1-5.8L1.5 7.7l5.9-.9z'

/**
 * Read-only stars. `value` may be fractional (3.5 shows three and a half).
 */
export function Stars({ value, size = 'size-4', label }) {
  const pct = Math.max(0, Math.min(5, value || 0)) * 20
  return (
    <span className="relative inline-flex shrink-0" role="img" aria-label={label || `${value ?? 0} out of 5 stars`}>
      <span className="flex text-stone-300">{[0, 1, 2, 3, 4].map((i) => <svg key={i} viewBox="0 0 20 20" className={size} fill="currentColor" aria-hidden="true"><path d={STAR} /></svg>)}</span>
      <span className="absolute inset-0 flex overflow-hidden text-amber-500" style={{ width: `${pct}%` }}>
        {[0, 1, 2, 3, 4].map((i) => <svg key={i} viewBox="0 0 20 20" className={`${size} shrink-0`} fill="currentColor" aria-hidden="true"><path d={STAR} /></svg>)}
      </span>
    </span>
  )
}

export function RatingSummary({ rating, className = '' }) {
  if (!rating?.count) return null
  return (
    <span className={`inline-flex items-center gap-1 text-sm text-stone-700 ${className}`}>
      <Stars value={rating.average} />
      <span className="font-medium">{rating.average}</span>
      <span className="text-stone-500">({rating.count})</span>
    </span>
  )
}

function StarInput({ value, onChange, name }) {
  return (
    <fieldset className="flex gap-1">
      <legend className="sr-only">Rating</legend>
      {[1, 2, 3, 4, 5].map((n) => (
        <label key={n} className="cursor-pointer">
          <input type="radio" name={name} value={n} checked={value === n} onChange={() => onChange(n)} className="peer sr-only" />
          <svg viewBox="0 0 20 20" className={`size-7 rounded peer-focus-visible:ring-2 peer-focus-visible:ring-brand-600 ${n <= value ? 'text-amber-500' : 'text-stone-300 hover:text-amber-300'}`} fill="currentColor" aria-hidden="true"><path d={STAR} /></svg>
          <span className="sr-only">{n} star{n > 1 ? 's' : ''}</span>
        </label>
      ))}
    </fieldset>
  )
}

/**
 * Write or edit the review of one delivered order line.
 */
export function ItemReview({ order, item, onSaved }) {
  const existing = item.review
  const open = order.reviews_open
  const [editing, setEditing] = useState(false)
  const [rating, setRating] = useState(existing?.rating || 0)
  const [body, setBody] = useState(existing?.body || '')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  if (!editing) {
    if (existing) {
      return (
        <div className="mt-2 rounded-lg bg-stone-50 p-3 text-sm">
          <div className="flex flex-wrap items-center gap-2">
            <Stars value={existing.rating} />
            <span className="text-stone-600">Your review{existing.is_hidden && ' (hidden by the marketplace team)'}</span>
            {open && <button type="button" className="text-brand-700 hover:underline" onClick={() => setEditing(true)}>Edit</button>}
          </div>
          {existing.body && <p className="mt-1 whitespace-pre-line text-stone-700">{existing.body}</p>}
          {existing.seller_reply && <p className="mt-2 border-l-2 border-brand-300 pl-2 text-stone-600"><span className="font-medium">Seller reply:</span> {existing.seller_reply}</p>}
        </div>
      )
    }
    if (!open) return null
    return <button type="button" className="btn-secondary mt-2" onClick={() => setEditing(true)}>Rate this item</button>
  }

  async function save(e) {
    e.preventDefault()
    if (!rating) {
      setError('Choose 1 to 5 stars.')
      return
    }
    setBusy(true)
    setError(null)
    try {
      if (existing) await api.put(`/reviews/${existing.id}`, { rating, body })
      else await api.post(`/orders/${order.id}/items/${item.id}/review`, { rating, body })
      setEditing(false)
      onSaved()
    } catch (err) {
      const p = parseError(err)
      setError(Object.values(p.fields)[0] || p.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={save} className="mt-2 space-y-2 rounded-lg border border-stone-200 p-3" noValidate>
      <StarInput value={rating} onChange={setRating} name={`rating-${item.id}`} />
      <label htmlFor={`review-${item.id}`} className="sr-only">Your review</label>
      <textarea id={`review-${item.id}`} className="input min-h-20" maxLength={2000} placeholder="Quality, delivery, anything other buyers should know (optional)" value={body} onChange={(e) => setBody(e.target.value)} />
      {error && <p className="text-sm font-medium text-red-700">{error}</p>}
      <div className="flex gap-2">
        <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Saving...' : existing ? 'Save changes' : 'Post review'}</button>
        <button type="button" className="btn-ghost" onClick={() => setEditing(false)}>Cancel</button>
      </div>
      <p className="text-xs text-stone-500">Shown with your first name and last initial. You can edit it until {new Date(order.review_until).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}.</p>
    </form>
  )
}

export function ReviewItem({ review, showProduct, children }) {
  return (
    <li className="py-4">
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <Stars value={review.rating} />
        <span className="font-medium text-stone-800">{review.reviewer}</span>
        <span className="rounded bg-emerald-50 px-1.5 text-xs text-emerald-800">Verified purchase</span>
        <span className="text-stone-500">{formatDateTime(review.created_at).split(',')[0]}</span>
      </div>
      {showProduct && review.product && <p className="mt-1 text-xs text-stone-500">On <Link to={`/products/${review.product.slug}`} className="hover:underline">{review.product.name}</Link></p>}
      {review.body && <p className="mt-1 whitespace-pre-line text-sm text-stone-700">{review.body}</p>}
      {review.seller_reply && <p className="mt-2 border-l-2 border-brand-300 pl-3 text-sm text-stone-600"><span className="font-medium text-stone-800">Reply from the seller:</span> {review.seller_reply}</p>}
      {children}
    </li>
  )
}

/**
 * Public reviews with an average, a 5-to-1 breakdown and pages of 10.
 */
export function ReviewList({ url, showProduct = false, emptyText = 'No reviews yet.' }) {
  const [page, setPage] = useState(1)
  const res = useFetch(`${url}?page=${page}`)

  if (res.loading && !res.data) return <Spinner />
  if (res.error) return <Alert tone="error">{res.error.message}</Alert>
  const { data, meta, summary } = res.data

  if (!summary.count) return <p className="text-sm text-stone-500">{emptyText}</p>

  return (
    <div className="grid gap-6 md:grid-cols-[220px_1fr]">
      <div>
        <p className="text-3xl font-bold text-stone-900">{summary.average}</p>
        <Stars value={summary.average} size="size-5" />
        <p className="text-sm text-stone-500">{summary.count} review{summary.count === 1 ? '' : 's'}</p>
        <dl className="mt-3 space-y-1 text-xs">
          {[5, 4, 3, 2, 1].map((n) => (
            <div key={n} className="flex items-center gap-2">
              <dt className="w-10 shrink-0">{n} star</dt>
              <dd className="h-2 flex-1 overflow-hidden rounded bg-stone-200"><span className="block h-full bg-amber-500" style={{ width: `${(100 * (summary.breakdown[n] || 0)) / summary.count}%` }} /></dd>
              <dd className="w-6 text-right text-stone-500">{summary.breakdown[n] || 0}</dd>
            </div>
          ))}
        </dl>
      </div>
      <div>
        <ul className="divide-y divide-stone-100">{data.map((r) => <ReviewItem key={r.id} review={r} showProduct={showProduct} />)}</ul>
        <Pagination meta={meta} onPage={setPage} />
      </div>
    </div>
  )
}
