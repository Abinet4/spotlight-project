import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { api, onUnauthorized, tokenStore } from '../lib/api'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [ready, setReady] = useState(!tokenStore.get())

  const refresh = useCallback(async () => {
    if (!tokenStore.get()) {
      setUser(null)
      setReady(true)
      return null
    }
    try {
      const res = await api.get('/auth/me')
      setUser(res.data.data)
      return res.data.data
    } catch {
      setUser(null)
      return null
    } finally {
      setReady(true)
    }
  }, [])

  useEffect(() => {
    refresh()
    return onUnauthorized(() => setUser(null))
  }, [refresh])

  const signIn = useCallback((token, userData) => {
    tokenStore.set(token)
    setUser(userData)
  }, [])

  const signOut = useCallback(async () => {
    try {
      await api.post('/auth/logout')
    } catch {
      // Token may already be invalid; clear locally either way.
    }
    tokenStore.set(null)
    setUser(null)
  }, [])

  const value = useMemo(() => ({ user, ready, signIn, signOut, refresh }), [user, ready, signIn, signOut, refresh])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider')
  return ctx
}
