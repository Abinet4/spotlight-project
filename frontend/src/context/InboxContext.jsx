import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { api } from '../lib/api'
import { useAuth } from './AuthContext'

const InboxContext = createContext({ unread: 0, refreshUnread: () => {} })
const POLL_MS = 60000

/**
 * Unread conversation count for the header badge. Polls while the tab is visible;
 * pages that read or send messages call refreshUnread() to update it at once.
 */
export function InboxProvider({ children }) {
  const { user } = useAuth()
  const [unread, setUnread] = useState(0)
  const enabled = user?.role === 'customer' || user?.role === 'seller'

  const refreshUnread = useCallback(async () => {
    if (!enabled) return
    try {
      const res = await api.get('/conversations/unread-count')
      setUnread(res.data.data.unread)
    } catch {
      // A failed poll keeps the last known count.
    }
  }, [enabled])

  useEffect(() => {
    if (!enabled) {
      setUnread(0)
      return undefined
    }
    refreshUnread()
    const timer = setInterval(() => {
      if (document.visibilityState === 'visible') refreshUnread()
    }, POLL_MS)
    const onVisible = () => document.visibilityState === 'visible' && refreshUnread()
    document.addEventListener('visibilitychange', onVisible)
    return () => {
      clearInterval(timer)
      document.removeEventListener('visibilitychange', onVisible)
    }
  }, [enabled, refreshUnread])

  const value = useMemo(() => ({ unread, refreshUnread }), [unread, refreshUnread])
  return <InboxContext.Provider value={value}>{children}</InboxContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useInbox() {
  return useContext(InboxContext)
}
