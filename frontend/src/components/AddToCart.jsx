import { useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { useCart } from '../context/CartContext'
import { api, parseError } from '../lib/api'

/**
 * Quantity picker and "Add to cart" for a listing. Only customer accounts can buy.
 */
export default function AddToCart({ product }) {
  const { user } = useAuth()
  const { refreshCart } = useCart()
  const location = useLocation()
  const [quantity, setQuantity] = useState(1)
  const [state, setState] = useState({ busy: false, message: null, tone: null })

  const inStock = product.stock_type === 'in_stock'
  const max = Math.min(inStock ? product.stock_quantity : 10, 99)
  if (inStock && product.stock_quantity < 1) return null

  if (!user) {
    return <Link to="/login" state={{ from: location.pathname }} className="btn-primary">Sign in to buy</Link>
  }
  if (user.role !== 'customer') return null

  async function add() {
    setState({ busy: true, message: null, tone: null })
    try {
      await api.post('/cart/items', { product_slug: product.slug, quantity })
      refreshCart()
      setState({ busy: false, tone: 'success', message: 'Added to your cart.' })
    } catch (err) {
      const e = parseError(err)
      setState({ busy: false, tone: 'error', message: Object.values(e.fields)[0] || e.message })
    }
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      <label htmlFor="qty" className="sr-only">Quantity</label>
      <select id="qty" className="input w-20" value={quantity} onChange={(e) => setQuantity(Number(e.target.value))}>
        {Array.from({ length: max }, (_, i) => i + 1).map((n) => <option key={n} value={n}>{n}</option>)}
      </select>
      <button type="button" className="btn-primary" disabled={state.busy} onClick={add}>{state.busy ? 'Adding...' : 'Add to cart'}</button>
      {state.message && (
        <p role="status" className={`w-full text-sm ${state.tone === 'error' ? 'text-red-700' : 'text-emerald-700'}`}>
          {state.message} {state.tone === 'success' && <Link to="/cart" className="font-semibold underline">View cart</Link>}
        </p>
      )}
    </div>
  )
}
