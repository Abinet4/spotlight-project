import { useCallback, useEffect, useRef, useState } from 'react'
import { api, parseError } from './api'

/**
 * Minimal data loader: GET `url` whenever it changes. Pass null to skip.
 * Ignores responses from superseded requests so fast filter changes never show stale data.
 */
export function useFetch(url) {
  const [state, setState] = useState({ data: null, error: null, loading: Boolean(url) })
  const latest = useRef(0)

  const load = useCallback(async () => {
    if (!url) return
    const id = ++latest.current
    setState((s) => ({ ...s, loading: true, error: null }))
    try {
      const res = await api.get(url)
      if (id === latest.current) setState({ data: res.data, error: null, loading: false })
    } catch (err) {
      if (id === latest.current) setState({ data: null, error: parseError(err), loading: false })
    }
  }, [url])

  useEffect(() => {
    load()
  }, [load])

  return { ...state, reload: load, setData: (data) => setState((s) => ({ ...s, data })) }
}
