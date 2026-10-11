import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import AddToCart from '../components/AddToCart'
import ContactSeller from '../components/ContactSeller'
import DeliveryEstimate from '../components/DeliveryEstimate'
import FavoriteButton from '../components/FavoriteButton'
import { ProductImage } from '../components/ProductCard'
import { ErrorState, Spinner } from '../components/ui'
import { BUSINESS_TYPES, CONDITION_LABELS, formatDimensions, formatETB } from '../lib/format'
import { useFetch } from '../lib/useFetch'

function Spec({ label, value }) {
  if (value === null || value === undefined || value === '') return null
  return (
    <div className="grid grid-cols-[140px_1fr] gap-3 border-b border-stone-100 py-2.5 text-sm last:border-0">
      <dt className="text-stone-500">{label}</dt>
      <dd className="font-medium text-stone-900">{value}</dd>
    </div>
  )
}

export default function ProductDetail() {
  const { slug } = useParams()
  const { data, error, loading, reload } = useFetch(`/products/${encodeURIComponent(slug)}`)
  const seller = useFetch(data ? `/sellers/${data.data.seller.slug}` : null)
  const [active, setActive] = useState(0)
  const [contactOpen, setContactOpen] = useState(false)

  if (loading) return <Spinner />
  if (error) {
    return (
      <div className="container-page py-12">
        <ErrorState error={error} onRetry={error.message?.includes('connection') ? reload : undefined} />
      </div>
    )
  }

  const p = data.data
  const images = p.images || []
  const madeToOrder = p.stock_type === 'made_to_order'
  const store = seller.data?.data
  const phone = store?.phone?.replace(/\s/g, '')

  return (
    <div className="container-page py-8">
      <nav aria-label="Breadcrumb" className="mb-4 text-sm text-stone-500">
        <Link to="/" className="hover:underline">Home</Link> /{' '}
        <Link to={`/category/${p.category.slug}`} className="hover:underline">{p.category.name}</Link> /{' '}
        <span className="text-stone-700">{p.name}</span>
      </nav>

      <div className="grid gap-8 lg:grid-cols-[1.2fr_1fr]">
        <div>
          <ProductImage src={images[active]?.url} alt={images[active]?.alt || p.name} className="aspect-[4/3] w-full rounded-xl border border-stone-200" />
          {images.length > 1 && (
            <div className="mt-3 flex gap-2 overflow-x-auto">
              {images.map((img, i) => (
                <button key={img.id} type="button" onClick={() => setActive(i)} aria-label={`Show photo ${i + 1}`} aria-current={i === active}
                  className={`shrink-0 overflow-hidden rounded-lg border-2 ${i === active ? 'border-brand-600' : 'border-transparent'}`}>
                  <img src={img.url} alt="" className="size-20 object-cover" />
                </button>
              ))}
            </div>
          )}
        </div>

        <div>
          <h1 className="text-2xl font-bold tracking-tight text-stone-900 sm:text-3xl">{p.name}</h1>
          <p className="mt-1 text-sm text-stone-600">
            Sold by <Link to={`/sellers/${p.seller.slug}`} className="font-semibold text-brand-700 hover:underline">{p.seller.store_name}</Link>
          </p>

          <div className="mt-4 flex items-baseline gap-3">
            <span className="text-3xl font-bold text-stone-900">{formatETB(p.price_santim)}</span>
            {p.price_negotiable && <span className="rounded bg-sand-200 px-2 py-0.5 text-xs font-semibold text-stone-700">Price negotiable</span>}
          </div>

          <p className={`mt-3 text-sm font-medium ${p.is_available ? 'text-emerald-700' : 'text-red-700'}`}>
            {madeToOrder
              ? `Made to order. Estimated production time ${p.production_days} days.`
              : p.is_available ? `In stock (${p.stock_quantity} available)` : 'Out of stock. Ask the seller about restocking.'}
          </p>

          <div className="card mt-5 space-y-3 p-4 text-sm">
            <p className="font-semibold text-stone-900">Delivery and pickup</p>
            <ul className="space-y-1 text-stone-700">
              {p.delivery.offers_delivery && (
                <li>Delivery in Addis Ababa: {p.delivery.fee_santim === null ? 'fee confirmed by the seller before you pay' : p.delivery.fee_santim === 0 ? 'free' : formatETB(p.delivery.fee_santim)}</li>
              )}
              {p.delivery.pickup_available && <li>Pickup available{p.area ? ` in ${p.area}` : ''}</li>}
              {p.delivery.notes && <li className="text-stone-600">{p.delivery.notes}</li>}
            </ul>
            {p.delivery.offers_delivery && <DeliveryEstimate slug={p.slug} />}
          </div>

          <div className="mt-5"><AddToCart product={p} /></div>
          <div className="mt-3 flex flex-wrap gap-2">
            {!contactOpen && <button type="button" className="btn-secondary" onClick={() => setContactOpen(true)}>Message seller</button>}
            {phone && <a href={`tel:${phone}`} className="btn-secondary">Call {store.phone}</a>}
            <FavoriteButton productId={p.id} initial={p.is_favorite} />
          </div>
          {contactOpen && <div className="mt-4"><ContactSeller product={p} onCancel={() => setContactOpen(false)} /></div>}
          <p className="mt-2 text-xs text-stone-500">
            Pay cash on delivery. The seller confirms your order and delivery details before preparing it.
          </p>

          {p.custom_order_available && (
            <div className="mt-5 rounded-lg border border-brand-200 bg-brand-50 p-4 text-sm text-brand-900">
              This seller accepts custom orders. Send a custom request to ask about other sizes, materials or colors and get a quote.
            </div>
          )}
        </div>
      </div>

      <div className="mt-10 grid gap-8 lg:grid-cols-[1.2fr_1fr]">
        <section>
          <h2 className="mb-3 text-lg font-bold text-stone-900">Description</h2>
          <p className="whitespace-pre-line text-stone-700">{p.description}</p>
        </section>
        <section>
          <h2 className="mb-3 text-lg font-bold text-stone-900">Specifications</h2>
          <dl className="card px-4">
            <Spec label="Category" value={p.category.name} />
            <Spec label="Condition" value={CONDITION_LABELS[p.condition]} />
            <Spec label="Dimensions" value={formatDimensions(p.dimensions)} />
            <Spec label="Material" value={p.material} />
            <Spec label="Color" value={p.color} />
            <Spec label="Location" value={p.area} />
            <Spec label="Seller type" value={store ? BUSINESS_TYPES[store.business_type] : null} />
          </dl>
        </section>
      </div>
    </div>
  )
}
