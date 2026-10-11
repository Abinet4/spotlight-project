import { useEffect, useState } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { useAlerts } from '../context/AlertsContext'
import { useAuth } from '../context/AuthContext'
import { useCart } from '../context/CartContext'
import { useConfig } from '../context/ConfigContext'
import { useInbox } from '../context/InboxContext'

function SearchBox({ className = '', id = 'site-search' }) {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [q, setQ] = useState(params.get('q') || '')

  useEffect(() => setQ(params.get('q') || ''), [params])

  return (
    <form
      role="search"
      className={className}
      onSubmit={(e) => {
        e.preventDefault()
        navigate(q.trim() ? `/search?q=${encodeURIComponent(q.trim())}` : '/search')
      }}
    >
      <label htmlFor={id} className="sr-only">Search furniture</label>
      <div className="relative">
        <input
          id={id}
          type="search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Search sofas, beds, wardrobes..."
          className="input pr-20"
          maxLength={100}
        />
        <button type="submit" className="absolute inset-y-1 right-1 rounded-md bg-brand-700 px-3 text-sm font-semibold text-white hover:bg-brand-800">
          Search
        </button>
      </div>
    </form>
  )
}

/**
 * Bell with the unread alert count. Icon only, because the header is crowded; it sits in the top bar on
 * every screen size so a new alert is visible without opening the menu.
 */
function AlertsLink({ onNavigate }) {
  const { unread } = useAlerts()
  return (
    <NavLink
      to="/notifications"
      onClick={onNavigate}
      aria-label={`Alerts${unread > 0 ? `, ${unread} unread` : ''}`}
      title="Alerts"
      className={({ isActive }) => `relative inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium ${isActive ? 'bg-brand-50 text-brand-800' : 'text-stone-700 hover:bg-stone-100'}`}
    >
      <svg viewBox="0 0 24 24" className="size-5 stroke-current" fill="none" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
        <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
      </svg>
      {unread > 0 && (
        <span aria-hidden="true" className="absolute -right-0.5 -top-0.5 inline-flex min-w-5 justify-center rounded-full bg-red-600 px-1.5 text-xs font-semibold text-white">
          {unread > 99 ? '99+' : unread}
        </span>
      )}
    </NavLink>
  )
}

function AccountLinks({ onNavigate, mobile = false }) {
  const { user, signOut } = useAuth()
  const { unread } = useInbox()
  const { count: cartCount } = useCart()
  const navigate = useNavigate()
  const linkClass = ({ isActive }) => `rounded-md px-3 py-2 text-sm font-medium ${isActive ? 'bg-brand-50 text-brand-800' : 'text-stone-700 hover:bg-stone-100'}`

  if (!user) {
    return (
      <>
        <NavLink to="/sell" className={linkClass} onClick={onNavigate}>Sell on our marketplace</NavLink>
        <NavLink to="/login" className={linkClass} onClick={onNavigate}>Sign in</NavLink>
        <Link to="/register" className="btn-primary" onClick={onNavigate}>Create account</Link>
      </>
    )
  }

  return (
    <>
      {user.role === 'seller' && <NavLink to="/seller" end className={linkClass} onClick={onNavigate}>Seller dashboard</NavLink>}
      {user.role === 'seller' && <NavLink to="/seller/orders" className={linkClass} onClick={onNavigate}>Orders</NavLink>}
      {user.role === 'admin' && <NavLink to="/admin" className={linkClass} onClick={onNavigate}>Admin</NavLink>}
      {user.role !== 'admin' && (
        <NavLink to="/messages" className={linkClass} onClick={onNavigate}>
          Messages
          {unread > 0 && (
            <span className="ml-1.5 inline-flex min-w-5 justify-center rounded-full bg-brand-700 px-1.5 text-xs font-semibold text-white">
              {unread > 99 ? '99+' : unread}
              <span className="sr-only"> unread</span>
            </span>
          )}
        </NavLink>
      )}
      {user.role !== 'admin' && <NavLink to="/wishlist" className={linkClass} onClick={onNavigate}>Wishlist</NavLink>}
      {user.role === 'customer' && <NavLink to="/orders" className={linkClass} onClick={onNavigate}>Orders</NavLink>}
      {user.role === 'customer' && (
        <NavLink to="/cart" className={linkClass} onClick={onNavigate}>
          Cart
          {cartCount > 0 && <span className="ml-1.5 inline-flex min-w-5 justify-center rounded-full bg-stone-800 px-1.5 text-xs font-semibold text-white">{cartCount}<span className="sr-only"> items</span></span>}
        </NavLink>
      )}
      <NavLink to="/account" end className={linkClass} onClick={onNavigate}>Account</NavLink>
      {!mobile && <AlertsLink onNavigate={onNavigate} />}
      <button
        type="button"
        className="rounded-md px-3 py-2 text-left text-sm font-medium text-stone-700 hover:bg-stone-100"
        onClick={async () => {
          onNavigate?.()
          await signOut()
          navigate('/')
        }}
      >
        Sign out
      </button>
    </>
  )
}

export default function Layout() {
  const { name } = useConfig()
  const { user } = useAuth()
  const [menuOpen, setMenuOpen] = useState(false)
  const location = useLocation()

  useEffect(() => setMenuOpen(false), [location.pathname])

  return (
    <div className="flex min-h-screen flex-col">
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:p-2">Skip to content</a>
      <header className="sticky top-0 z-30 border-b border-stone-200 bg-white/95 backdrop-blur">
        <div className="container-page flex h-16 items-center gap-4">
          <Link to="/" className="flex shrink-0 items-center gap-2 font-bold text-brand-800">
            <img src="/favicon.svg" alt="" className="size-8" />
            <span className="text-lg tracking-tight">{name}</span>
          </Link>
          <SearchBox className="hidden flex-1 md:block md:max-w-xl" />
          <nav className="ml-auto hidden items-center gap-1 lg:flex" aria-label="Account">
            <AccountLinks />
          </nav>
          {user && <div className="ml-auto lg:hidden"><AlertsLink /></div>}
          <button
            type="button"
            className={`${user ? '' : 'ml-auto'} rounded-md p-2 text-stone-700 hover:bg-stone-100 lg:hidden`}
            aria-expanded={menuOpen}
            aria-controls="mobile-menu"
            aria-label="Menu"
            onClick={() => setMenuOpen((o) => !o)}
          >
            <svg viewBox="0 0 24 24" className="size-6 stroke-current" fill="none" strokeWidth="2" aria-hidden="true">
              {menuOpen ? <path d="M6 6l12 12M18 6L6 18" /> : <path d="M4 7h16M4 12h16M4 17h16" />}
            </svg>
          </button>
        </div>
        <div className="container-page pb-3 md:hidden">
          <SearchBox id="site-search-mobile" />
        </div>
        {menuOpen && (
          <nav id="mobile-menu" className="container-page flex flex-col gap-1 border-t border-stone-200 py-3 lg:hidden" aria-label="Account">
            <AccountLinks onNavigate={() => setMenuOpen(false)} mobile />
          </nav>
        )}
      </header>

      <main id="main" className="flex-1">
        <Outlet />
      </main>

      <footer className="mt-16 border-t border-stone-200 bg-white">
        <div className="container-page grid gap-8 py-10 text-sm sm:grid-cols-3">
          <div>
            <p className="font-semibold text-stone-900">{name}</p>
            <p className="mt-2 text-stone-600">Furniture from trusted shops, workshops and makers in Addis Ababa.</p>
          </div>
          <div>
            <p className="font-semibold text-stone-900">Help</p>
            <ul className="mt-2 space-y-1.5 text-stone-600">
              <li><Link to="/help/delivery" className="hover:underline">Delivery information</Link></li>
              <li><Link to="/help/returns" className="hover:underline">Returns and refunds</Link></li>
              <li><Link to="/help/policies" className="hover:underline">Marketplace policies</Link></li>
            </ul>
          </div>
          <div>
            <p className="font-semibold text-stone-900">Sellers</p>
            <ul className="mt-2 space-y-1.5 text-stone-600">
              <li><Link to="/sell" className="hover:underline">Apply to sell</Link></li>
              <li><Link to="/help/selling" className="hover:underline">How selling works</Link></li>
            </ul>
          </div>
        </div>
        <div className="border-t border-stone-100 py-4 text-center text-xs text-stone-500">
          Prices in Ethiopian birr (ETB). Currently serving Addis Ababa.
        </div>
      </footer>
    </div>
  )
}
