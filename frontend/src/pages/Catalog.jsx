import { useMemo, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { ProductGrid } from '../components/ProductCard'
import { EmptyState, ErrorState, Pagination, Spinner } from '../components/ui'
import { toQuery } from '../lib/format'
import { useFetch } from '../lib/useFetch'

const SORTS = [
  ['recommended', 'Recommended'],
  ['newest', 'Newest'],
  ['price_asc', 'Price: low to high'],
  ['price_desc', 'Price: high to low'],
]

const FILTER_KEYS = ['q', 'min_price', 'max_price', 'condition', 'stock_type', 'area', 'custom', 'delivery', 'in_stock', 'sort', 'page']

function Filters({ params, update, areas, onClose }) {
  const [minPrice, setMinPrice] = useState(params.get('min_price') || '')
  const [maxPrice, setMaxPrice] = useState(params.get('max_price') || '')
  const check = (key) => params.get(key) === '1'

  return (
    <div className="space-y-6 text-sm">
      <form
        onSubmit={(e) => {
          e.preventDefault()
          update({ min_price: minPrice.replace(/[^\d.]/g, ''), max_price: maxPrice.replace(/[^\d.]/g, '') })
          onClose?.()
        }}
      >
        <p className="mb-2 font-semibold text-stone-900">Price (ETB)</p>
        <div className="flex items-center gap-2">
          <input aria-label="Minimum price" inputMode="decimal" className="input" placeholder="Min" value={minPrice} onChange={(e) => setMinPrice(e.target.value)} />
          <span className="text-stone-400">to</span>
          <input aria-label="Maximum price" inputMode="decimal" className="input" placeholder="Max" value={maxPrice} onChange={(e) => setMaxPrice(e.target.value)} />
        </div>
        <button type="submit" className="btn-secondary mt-2 w-full">Apply price</button>
      </form>

      <fieldset>
        <legend className="mb-2 font-semibold text-stone-900">Condition</legend>
        <select className="input" value={params.get('condition') || ''} onChange={(e) => update({ condition: e.target.value })}>
          <option value="">Any condition</option>
          <option value="new">New</option>
          <option value="used">Used</option>
          <option value="refurbished">Refurbished</option>
        </select>
      </fieldset>

      <fieldset>
        <legend className="mb-2 font-semibold text-stone-900">Availability</legend>
        <select className="input" value={params.get('stock_type') || ''} onChange={(e) => update({ stock_type: e.target.value })}>
          <option value="">Ready-made and made to order</option>
          <option value="in_stock">Ready-made only</option>
          <option value="made_to_order">Made to order only</option>
        </select>
      </fieldset>

      {areas?.length > 0 && (
        <fieldset>
          <legend className="mb-2 font-semibold text-stone-900">Seller location</legend>
          <select className="input" value={params.get('area') || ''} onChange={(e) => update({ area: e.target.value })}>
            <option value="">All of Addis Ababa</option>
            {areas.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
          </select>
        </fieldset>
      )}

      <fieldset className="space-y-2">
        <legend className="mb-2 font-semibold text-stone-900">Options</legend>
        {[
          ['in_stock', 'Available now'],
          ['delivery', 'Delivery offered'],
          ['custom', 'Custom orders accepted'],
        ].map(([key, label]) => (
          <label key={key} className="flex items-center gap-2">
            <input type="checkbox" className="size-4 accent-brand-700" checked={check(key)} onChange={(e) => update({ [key]: e.target.checked ? '1' : '' })} />
            {label}
          </label>
        ))}
      </fieldset>
    </div>
  )
}

export default function Catalog() {
  const { slug } = useParams()
  const [params, setParams] = useSearchParams()
  const [filtersOpen, setFiltersOpen] = useState(false)
  const categories = useFetch('/categories')
  const areas = useFetch('/areas')

  const query = useMemo(() => {
    const q = {}
    FILTER_KEYS.forEach((k) => { q[k] = params.get(k) || '' })
    return toQuery({ ...q, category: slug || '' })
  }, [params, slug])

  const products = useFetch(`/products?${query}`)

  const category = useMemo(() => {
    const all = (categories.data?.data || []).flatMap((c) => [c, ...(c.children || [])])
    return all.find((c) => c.slug === slug)
  }, [categories.data, slug])

  function update(changes) {
    const next = new URLSearchParams(params)
    Object.entries(changes).forEach(([k, v]) => (v ? next.set(k, v) : next.delete(k)))
    if (!('page' in changes)) next.delete('page')
    setParams(next)
  }

  const term = params.get('q')
  const title = category ? category.name : term ? `Results for "${term}"` : 'All furniture'
  const activeFilters = FILTER_KEYS.filter((k) => !['q', 'sort', 'page'].includes(k) && params.get(k)).length
  const meta = products.data?.meta

  return (
    <div className="container-page py-8">
      <nav aria-label="Breadcrumb" className="mb-3 text-sm text-stone-500">
        <Link to="/" className="hover:underline">Home</Link> <span aria-hidden="true">/</span>{' '}
        <span className="text-stone-700">{title}</span>
      </nav>
      <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-stone-900 sm:text-3xl">{title}</h1>
          {category?.description && <p className="mt-1 text-sm text-stone-600">{category.description}</p>}
          {meta && <p className="mt-1 text-sm text-stone-500">{meta.total} {meta.total === 1 ? 'listing' : 'listings'}</p>}
        </div>
        <div className="flex gap-2">
          <button type="button" className="btn-secondary lg:hidden" onClick={() => setFiltersOpen((o) => !o)} aria-expanded={filtersOpen}>
            Filters{activeFilters ? ` (${activeFilters})` : ''}
          </button>
          <label htmlFor="sort" className="sr-only">Sort by</label>
          <select id="sort" className="input w-auto" value={params.get('sort') || 'recommended'} onChange={(e) => update({ sort: e.target.value === 'recommended' ? '' : e.target.value })}>
            {SORTS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </div>
      </div>

      {categories.data && !slug && (
        <div className="-mx-4 mb-6 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0">
          {categories.data.data.map((c) => (
            <Link key={c.id} to={`/category/${c.slug}${params.get('q') ? `?q=${encodeURIComponent(params.get('q'))}` : ''}`} className="shrink-0 rounded-full border border-stone-300 bg-white px-3 py-1.5 text-sm hover:border-brand-500">
              {c.name}
            </Link>
          ))}
        </div>
      )}

      <div className="grid gap-8 lg:grid-cols-[240px_1fr]">
        <aside className={`${filtersOpen ? 'block' : 'hidden'} card p-4 lg:block lg:self-start`} aria-label="Filters">
          <Filters key={query} params={params} update={update} areas={areas.data?.data} onClose={() => setFiltersOpen(false)} />
          {activeFilters > 0 && (
            <button type="button" className="btn-ghost mt-4 w-full" onClick={() => setParams(term ? { q: term } : {})}>
              Clear filters
            </button>
          )}
        </aside>

        <section aria-live="polite">
          {products.loading && <Spinner label="Loading furniture" />}
          {products.error && <ErrorState error={products.error} onRetry={products.reload} />}
          {products.data && !products.loading && (products.data.data.length ? (
            <>
              <ProductGrid products={products.data.data} />
              <Pagination meta={meta} onPage={(p) => { update({ page: String(p) }); window.scrollTo({ top: 0 }) }} />
            </>
          ) : (
            <EmptyState
              title={slug && categories.data && !category ? 'Category not found' : 'No furniture matches your search'}
              action={<Link to="/search" className="btn-secondary">Browse all furniture</Link>}
            >
              Try fewer filters or a different keyword. Looking for something specific? Many sellers build custom furniture to order.
            </EmptyState>
          ))}
        </section>
      </div>
    </div>
  )
}
