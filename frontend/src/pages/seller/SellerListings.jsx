import { Plus } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { ProductImage } from '../../components/ProductCard'
import { Alert, EmptyState, ErrorState, Pagination, Spinner, StatusBadge } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { formatETB, toQuery } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'
import { StoreStatus, useSellerSummary } from './shared'

const TABS = [
  ['', 'All'],
  ['published', 'Published'],
  ['pending_review', 'In review'],
  ['draft', 'Drafts'],
  ['rejected', 'Rejected'],
  ['archived', 'Archived'],
]

export default function SellerListings() {
  const [params, setParams] = useSearchParams()
  const status = params.get('status') || ''
  const summary = useSellerSummary()
  const products = useFetch(`/seller/products?${toQuery({ status, page: params.get('page') || '' })}`)
  const [notice, setNotice] = useState(null)

  async function act(product, action) {
    if (action === 'delete' && !window.confirm(`Delete "${product.name}"? This cannot be undone.`)) return
    setNotice(null)
    try {
      if (action === 'delete') await api.delete(`/seller/products/${product.id}`)
      else await api.post(`/seller/products/${product.id}/${action}`)
      setNotice({ tone: 'success', text: { submit: 'Submitted for review.', archive: 'Listing hidden from the marketplace.', delete: 'Listing deleted.' }[action] })
      products.reload()
      summary.reload()
    } catch (err) {
      const e = parseError(err)
      setNotice({ tone: 'error', text: Object.values(e.fields)[0] || e.message })
    }
  }

  const s = summary.data?.data
  const store = s?.store
  const canWrite = ['pending', 'approved'].includes(store?.status)
  const counts = s?.products || {}
  // Statuses with nothing in them are left out (the one being viewed always shows).
  const tabs = TABS.filter(([value]) => !value || value === status || counts[value] > 0)

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold tracking-tight text-stone-900">Listings</h1>
        {canWrite && <Link to="/seller/products/new" className="btn-primary"><Plus className="size-4" aria-hidden="true" />Add listing</Link>}
      </div>
      <StoreStatus store={store} />
      {notice && <div className="mb-4"><Alert tone={notice.tone}>{notice.text}</Alert></div>}

      {tabs.length > 1 && (
        <div className="no-scrollbar mb-4 flex gap-1 overflow-x-auto border-b border-stone-200" role="tablist">
          {tabs.map(([value, label]) => (
            <button key={value} role="tab" aria-selected={status === value} type="button" onClick={() => setParams(value ? { status: value } : {})}
              className={`shrink-0 border-b-2 px-3 py-2 text-sm font-medium ${status === value ? 'border-brand-700 text-brand-800' : 'border-transparent text-stone-600 hover:text-stone-900'}`}>
              {label}{value && counts[value] ? <span className="ml-1 text-stone-400">{counts[value]}</span> : null}
            </button>
          ))}
        </div>
      )}

      {products.loading && !products.data && <Spinner />}
      {products.error && <ErrorState error={products.error} onRetry={products.reload} />}
      {products.data && (products.data.data.length ? (
        <>
          <ul className="card divide-y divide-stone-100">
            {products.data.data.map((item) => (
              <li key={item.id} className="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3">
                <ProductImage src={item.image} alt="" className="size-14 shrink-0 rounded-lg" />
                <div className="min-w-0 flex-1 basis-40">
                  <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <p className="truncate font-medium text-stone-900">{item.name}</p>
                    <StatusBadge status={item.status} />
                  </div>
                  <p className="text-sm text-stone-600">
                    {formatETB(item.price_santim)} · {item.stock_quantity} in stock
                  </p>
                  {item.status === 'rejected' && item.moderation_note && <p className="mt-1 text-sm text-red-700">Reviewer note: {item.moderation_note}</p>}
                </div>
                <div className="flex w-full flex-wrap justify-end gap-1 sm:w-auto">
                  {['draft', 'rejected', 'archived'].includes(item.status) && store?.status === 'approved' && (
                    <button type="button" className="btn-primary px-3 py-1.5" onClick={() => act(item, 'submit')}>Submit for review</button>
                  )}
                  {item.status !== 'archived' && canWrite && <Link to={`/seller/products/${item.id}/edit`} className="btn-secondary px-3 py-1.5">Edit</Link>}
                  {['published', 'pending_review'].includes(item.status) && (
                    <button type="button" className="btn-ghost px-3 py-1.5" onClick={() => act(item, 'archive')}>Hide</button>
                  )}
                  <button type="button" className="btn-ghost px-3 py-1.5 text-stone-500 hover:text-red-700" onClick={() => act(item, 'delete')}>Delete</button>
                </div>
              </li>
            ))}
          </ul>
          <Pagination meta={products.data.meta} onPage={(pg) => setParams(toQuery({ status, page: pg }))} />
        </>
      ) : (
        <EmptyState title={status ? 'Nothing here' : 'No listings yet'} action={canWrite && !status && <Link to="/seller/products/new" className="btn-primary">Add your first listing</Link>}>
          {status ? 'No listings with this status.' : 'Add photos, dimensions, materials and delivery terms so customers can compare with confidence.'}
        </EmptyState>
      ))}
    </>
  )
}
