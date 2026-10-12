import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { ReviewItem, Stars } from '../../components/Reviews'
import { EmptyState, ErrorState, Pagination, Spinner } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { toQuery } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'
import { useSellerSummary } from './shared'

function ReplyForm({ review, onSaved }) {
  const [open, setOpen] = useState(false)
  const [text, setText] = useState(review.seller_reply || '')
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  if (!open) return <button type="button" className="btn-ghost mt-1 px-0 text-brand-700" onClick={() => setOpen(true)}>{review.seller_reply ? 'Edit reply' : 'Reply publicly'}</button>

  async function save(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const res = await api.post(`/seller/reviews/${review.id}/reply`, { reply: text })
      setOpen(false)
      onSaved(res.data.data)
    } catch (err) {
      setError(parseError(err).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={save} className="mt-2 space-y-2">
      <label htmlFor={`reply-${review.id}`} className="sr-only">Reply</label>
      <textarea id={`reply-${review.id}`} className="input min-h-20" maxLength={1000} value={text} onChange={(e) => setText(e.target.value)} placeholder="Thank the customer or explain how you fixed the problem. Everyone can read this." />
      {error && <p className="text-sm text-red-700">{error}</p>}
      <div className="flex gap-2">
        <button type="submit" className="btn-primary" disabled={busy}>Save reply</button>
        <button type="button" className="btn-ghost" onClick={() => setOpen(false)}>Cancel</button>
      </div>
    </form>
  )
}

export default function SellerReviews() {
  const [params, setParams] = useSearchParams()
  const unanswered = params.get('unanswered') === '1'
  const page = params.get('page') || ''
  const res = useFetch(`/seller/reviews?${toQuery({ unanswered: unanswered ? 1 : '', page })}`)
  const summary = useSellerSummary()

  const total = summary.data?.data.reviews
  const filters = [['', 'All', total?.count], ['1', 'Without a reply', total?.unanswered]]

  return (
    <div className="max-w-3xl">
      <h1 className="mb-3 text-2xl font-bold tracking-tight text-stone-900">Reviews</h1>
      {res.data?.summary.count > 0 && (
        <p className="mb-3 flex items-center gap-2 text-sm"><Stars value={res.data.summary.average} /> <span className="font-semibold">{res.data.summary.average}</span> from {res.data.summary.count} {res.data.summary.count === 1 ? 'review' : 'reviews'}</p>
      )}
      <div className="mb-4 flex gap-1 border-b border-stone-200" role="tablist">
        {filters.map(([value, label, n]) => (
          <button key={label} type="button" role="tab" aria-selected={(unanswered ? '1' : '') === value} onClick={() => setParams(value ? { unanswered: value } : {})}
            className={`shrink-0 border-b-2 px-3 py-2 text-sm font-medium ${(unanswered ? '1' : '') === value ? 'border-brand-700 text-brand-800' : 'border-transparent text-stone-600 hover:text-stone-900'}`}>
            {label}{n ? <span className="ml-1 text-stone-400">{n}</span> : null}
          </button>
        ))}
      </div>
      {res.loading && !res.data ? <Spinner /> : res.error ? <ErrorState error={res.error} /> : res.data.data.length === 0 ? (
        <EmptyState title={unanswered ? 'All caught up' : 'No reviews yet'}>{unanswered ? 'Every review has a reply.' : 'Reviews appear here after customers receive their orders.'}</EmptyState>
      ) : (
        <>
          <p className="mb-2 text-xs text-stone-500">Everyone can read your replies. A short, polite one helps future buyers trust you.</p>
          <ul className="card divide-y divide-stone-100 px-4">
            {res.data.data.map((r) => (
              <ReviewItem key={r.id} review={r} showProduct>
                <ReplyForm review={r} onSaved={() => { res.reload(); summary.reload() }} />
              </ReviewItem>
            ))}
          </ul>
          <Pagination meta={res.data.meta} onPage={(p) => setParams(toQuery({ unanswered: unanswered ? '1' : '', page: String(p) }))} />
        </>
      )}
    </div>
  )
}
