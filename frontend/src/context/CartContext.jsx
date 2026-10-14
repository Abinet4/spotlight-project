import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { api } from '../lib/api'
import { useAuth } from './AuthContext'

const CartContext = createContext({ count: 0, refreshCart: () => {} })

/**
 * Number of lines in the customer's cart, for the header. Pages that change the cart call refreshCart().
 */
export function CartProvider({ children }) {
  const { user } = useAuth()
  const [count, setCount] = useState(0)
  const enabled = user?.role === 'customer'

  const refreshCart = useCallback(async () => {
    if (!enabled) return
    try {
      const res = await api.get('/cart/count')
      setCount(res.data.data.count)
    } catch {
      // Keep the last known count.
    }
  }, [enabled])

  useEffect(() => {
    if (enabled) refreshCart()
    else setCount(0)
  }, [enabled, refreshCart])

  const value = useMemo(() => ({ count, refreshCart }), [count, refreshCart])
  return <CartContext.Provider value={value}>{children}</CartContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useCart() {
  return useContext(CartContext)
}
