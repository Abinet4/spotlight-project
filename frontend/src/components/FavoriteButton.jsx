import { useEffect, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { api } from '../lib/api'

export default function FavoriteButton({ productId, initial = false, compact = false }) {
  const { user } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [saved, setSaved] = useState(Boolean(initial))
  const [busy, setBusy] = useState(false)

  useEffect(() => setSaved(Boolean(initial)), [initial])

  async function toggle() {
    if (!user) {
      navigate('/login', { state: { from: location.pathname + location.search } })
      return
    }
    setBusy(true)
    const next = !saved
    setSaved(next) // optimistic
    try {
      if (next) await api.post(`/favorites/${productId}`)
      else await api.delete(`/favorites/${productId}`)
    } catch {
      setSaved(!next)
    } finally {
      setBusy(false)
    }
  }

  const label = saved ? 'Remove from wishlist' : 'Save to wishlist'
  return (
    <button
      type="button"
      onClick={toggle}
      disabled={busy}
      aria-pressed={saved}
      aria-label={label}
      title={label}
      className={compact
        ? 'flex size-9 items-center justify-center rounded-full bg-white/90 shadow-sm hover:bg-white'
        : 'btn-secondary'}
    >
      <svg viewBox="0 0 24 24" className={`size-5 ${saved ? 'fill-red-600 stroke-red-600' : 'fill-none stroke-stone-700'}`} strokeWidth="2" aria-hidden="true">
        <path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z" strokeLinejoin="round" />
      </svg>
      {!compact && <span>{saved ? 'Saved' : 'Save'}</span>}
    </button>
  )
}
