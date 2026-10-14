import { Link, useSearchParams } from 'react-router-dom'
import { ProductGrid } from '../components/ProductCard'
import { EmptyState, ErrorState, PageHeader, Pagination, Spinner } from '../components/ui'
import { useFetch } from '../lib/useFetch'

export default function Wishlist() {
  const [params, setParams] = useSearchParams()
  const { data, error, loading, reload } = useFetch(`/favorites?page=${params.get('page') || 1}`)

  return (
    <div className="container-page py-8">
      <PageHeader title="Your wishlist" subtitle="Furniture you saved to compare or buy later." />
      {loading && <Spinner />}
      {error && <ErrorState error={error} onRetry={reload} />}
      {data && (data.data.length ? (
        <>
          <ProductGrid products={data.data} />
          <Pagination meta={data.meta} onPage={(p) => setParams({ page: String(p) })} />
        </>
      ) : (
        <EmptyState title="Your wishlist is empty" action={<Link to="/search" className="btn-primary">Browse furniture</Link>}>
          Tap the heart on any listing to save it here.
        </EmptyState>
      ))}
    </div>
  )
}
