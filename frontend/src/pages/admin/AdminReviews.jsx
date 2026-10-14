import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { ReviewItem } from '../../components/Reviews'
import { Alert, EmptyState, ErrorState, PageHeader, Pagination, Spinner } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { toQuery } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'

export default function AdminReviews() {
  const [params, setParams] = useSearchParams()
  const filter = params.get('filter') || ''
  const query = { '': {}, low: { max_rating: 2, hidden: 0 }, hidden: { hidden: 1 } }[filter] || {}
  const res = useFetch(`/admin/reviews?${toQuery({ ...query, page: params.get('page') || '' })}`)
  const [notice, setNotice] = useState(null)

  async function toggle(review) {
    let reason = null
    if (!review.is_hidden) {
      reason = window.prompt('Why hide this review? (kept in the audit log)')
      if (!reason?.trim()) return
    }
    try {
      await api.post(`/admin/reviews/${review.id}/${review.is_hidden ? 'restore' : 'hide'}`, { reason })
      setNotice({ tone: 'success', text: review.is_hidden ? 'Review is public again.' : 'Review hidden. Ratings were recalculated.' })
      res.reload()
    } catch (err) {
      const p = parseError(err)
      setNotice({ tone: 'error', text: Object.values(p.fields)[0] || p.message })
    }
  }

  return (
    <>
      <PageHeader title="Reviews" subtitle="Hide abuse, personal data or spam only, never honest bad reviews." />
      <div className="mb-4 flex gap-1 border-b border-stone-200" role="tablist">
        {[['', 'All'], ['low', '1 or 2 stars'], ['hidden', 'Hidden']].map(([k, label]) => (
          <button key={k} type="button" role="tab" aria-selected={filter === k} onClick={() => setParams(k ? { filter: k } : {})}
            className={`border-b-2 px-3 py-2 text-sm font-medium ${filter === k ? 'border-brand-700 text-brand-800' : 'border-transparent text-stone-600'}`}>{label}</button>
        ))}
      </div>
      {notice && <div className="mb-4"><Alert tone={notice.tone}>{notice.text}</Alert></div>}
      {res.loading && !res.data ? <Spinner /> : res.error ? <ErrorState error={res.error} /> : res.data.data.length === 0 ? <EmptyState title="No reviews here" /> : (
        <>
          <ul className="card divide-y divide-stone-100 px-4">
            {res.data.data.map((r) => (
              <ReviewItem key={r.id} review={r} showProduct>
                <p className="mt-2 text-xs text-stone-500">
                  {r.customer_email} · <Link to={`/sellers/${r.seller.slug}`} className="hover:underline">{r.seller.store_name}</Link> · {r.order_number}
                  {r.is_hidden && <span className="ml-1 font-semibold text-red-700">Hidden: {r.hidden_reason}</span>}
                </p>
                <button type="button" className={`btn-ghost mt-1 px-0 ${r.is_hidden ? 'text-brand-700' : 'text-red-700'}`} onClick={() => toggle(r)}>{r.is_hidden ? 'Make public again' : 'Hide review'}</button>
              </ReviewItem>
            ))}
          </ul>
          <Pagination meta={res.data.meta} onPage={(p) => setParams(toQuery({ filter, page: String(p) }))} />
        </>
      )}
    </>
  )
}
