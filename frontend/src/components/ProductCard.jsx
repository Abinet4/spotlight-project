import { Link } from 'react-router-dom'
import { CONDITION_LABELS, formatETB } from '../lib/format'
import { RatingSummary } from './Reviews'
import FavoriteButton from './FavoriteButton'

export function ProductImage({ src, alt, className = '' }) {
  if (!src) {
    return (
      <div className={`flex items-center justify-center bg-sand-200 text-xs text-stone-500 ${className}`} aria-label="No photo">
        No photo
      </div>
    )
  }
  return <img src={src} alt={alt} loading="lazy" className={`object-cover ${className}`} />
}

export default function ProductCard({ product }) {
  const madeToOrder = product.stock_type === 'made_to_order'
  return (
    <article className="group card relative flex flex-col overflow-hidden transition-shadow hover:shadow-md">
      <Link to={`/products/${product.slug}`} className="block">
        <ProductImage src={product.image} alt={product.name} className="aspect-[4/3] w-full" />
      </Link>
      <div className="absolute top-2 right-2">
        <FavoriteButton productId={product.id} initial={product.is_favorite} compact />
      </div>
      <div className="flex flex-1 flex-col gap-1.5 p-4">
        <div className="flex flex-wrap gap-1.5 text-xs">
          {product.condition !== 'new' && (
            <span className="rounded bg-stone-100 px-1.5 py-0.5 font-medium text-stone-700">{CONDITION_LABELS[product.condition]}</span>
          )}
          {madeToOrder && <span className="rounded bg-brand-50 px-1.5 py-0.5 font-medium text-brand-800">Made to order</span>}
          {!product.is_available && <span className="rounded bg-red-50 px-1.5 py-0.5 font-medium text-red-700">Out of stock</span>}
        </div>
        <h3 className="line-clamp-2 font-semibold text-stone-900">
          <Link to={`/products/${product.slug}`} className="hover:underline">
            {product.name}
          </Link>
        </h3>
        {product.seller && <p className="text-xs text-stone-500">{product.seller.store_name}{product.area ? `, ${product.area}` : ''}</p>}
        <RatingSummary rating={product.rating} className="text-xs" />
        <div className="mt-auto flex flex-wrap items-baseline gap-x-2 pt-2">
          <span className="text-base font-bold whitespace-nowrap text-stone-900 sm:text-lg">{formatETB(product.price_santim)}</span>
          {product.price_negotiable && <span className="text-xs text-stone-500">Negotiable</span>}
        </div>
      </div>
    </article>
  )
}

export function ProductGrid({ products }) {
  return (
    <div className="grid grid-cols-2 gap-3 sm:gap-5 md:grid-cols-3 xl:grid-cols-4">
      {products.map((p) => (
        <ProductCard key={p.id} product={p} />
      ))}
    </div>
  )
}
