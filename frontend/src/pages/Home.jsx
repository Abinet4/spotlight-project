import { Link } from 'react-router-dom'
import { ProductGrid } from '../components/ProductCard'
import { EmptyState, ErrorState, Spinner } from '../components/ui'
import { useConfig } from '../context/ConfigContext'
import { useFetch } from '../lib/useFetch'

export default function Home() {
  const { name } = useConfig()
  const categories = useFetch('/categories')
  const featured = useFetch('/products?per_page=8&sort=recommended')
  const newest = useFetch('/products?per_page=4&sort=newest')

  return (
    <>
      <section className="bg-brand-800 text-white">
        <div className="container-page grid gap-8 py-12 sm:py-16 lg:grid-cols-[1.4fr_1fr] lg:items-center">
          <div>
            <h1 className="text-3xl font-bold tracking-tight sm:text-4xl lg:text-5xl">
              Furniture for every home in Addis Ababa
            </h1>
            <p className="mt-4 max-w-xl text-brand-100">
              Compare sofas, beds, wardrobes and office furniture from verified local shops and workshops. Buy ready-made or ask a maker to build it for you.
            </p>
            <div className="mt-6 flex flex-wrap gap-3">
              <Link to="/search" className="btn bg-white text-brand-800 hover:bg-sand-100">Browse all furniture</Link>
              <Link to="/category/custom-made" className="btn border border-brand-200/60 text-white hover:bg-brand-700">Request custom furniture</Link>
            </div>
          </div>
          <ul className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3 lg:grid-cols-1">
            {[
              ['Verified sellers', 'Every store is reviewed by our team before it can list.'],
              ['Clear specifications', 'Dimensions, materials and delivery terms on every listing.'],
              ['Talk to the maker', 'Ask about availability, finishes or custom sizes.'],
            ].map(([title, text]) => (
              <li key={title} className="rounded-lg bg-brand-700/60 px-4 py-3">
                <p className="font-semibold">{title}</p>
                <p className="text-brand-100">{text}</p>
              </li>
            ))}
          </ul>
        </div>
      </section>

      <section className="container-page py-10">
        <h2 className="mb-4 text-xl font-bold text-stone-900">Shop by category</h2>
        {categories.loading && <Spinner />}
        {categories.error && <ErrorState error={categories.error} onRetry={categories.reload} />}
        {categories.data && (
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {categories.data.data.map((c) => (
              <Link key={c.id} to={`/category/${c.slug}`} className="card group px-4 py-4 hover:border-brand-300 hover:bg-brand-50">
                <p className="font-semibold text-stone-900 group-hover:text-brand-800">{c.name}</p>
                <p className="mt-0.5 text-xs text-stone-500">{c.products_count} {c.products_count === 1 ? 'listing' : 'listings'}</p>
              </Link>
            ))}
          </div>
        )}
      </section>

      <section className="container-page py-4">
        <div className="mb-4 flex items-end justify-between">
          <h2 className="text-xl font-bold text-stone-900">Featured furniture</h2>
          <Link to="/search" className="text-sm font-semibold text-brand-700 hover:underline">See all</Link>
        </div>
        {featured.loading && <Spinner />}
        {featured.error && <ErrorState error={featured.error} onRetry={featured.reload} />}
        {featured.data && (featured.data.data.length ? (
          <ProductGrid products={featured.data.data} />
        ) : (
          <EmptyState title="No listings yet" action={<Link to="/sell" className="btn-primary">Become a seller</Link>}>
            {name} is just getting started. Sellers are adding their furniture now.
          </EmptyState>
        ))}
      </section>

      {newest.data?.data?.length > 0 && (
        <section className="container-page py-10">
          <h2 className="mb-4 text-xl font-bold text-stone-900">Just listed</h2>
          <ProductGrid products={newest.data.data} />
        </section>
      )}
    </>
  )
}
