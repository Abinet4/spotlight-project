import {
  BarChart3, ChevronDown, CreditCard, History, LayoutDashboard, MessageSquare, Package, Settings, ShoppingBag, Star, Store, Tags, Truck,
  Users, Wallet,
} from 'lucide-react'
import { Suspense, useEffect, useRef, useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { Spinner } from '../../components/ui'
import { useFetch } from '../../lib/useFetch'

// Grouped by how often an admin needs them. `count` picks a number from /admin/summary to show
// beside the link when something is waiting.
const GROUPS = [
  ['Daily', [
    { to: '/admin', label: 'Overview', icon: LayoutDashboard, end: true },
    { to: '/admin/orders', label: 'Orders', icon: ShoppingBag },
    { to: '/admin/payments', label: 'Payments', icon: CreditCard, count: (s) => s.refunds_due },
    { to: '/admin/sellers', label: 'Sellers', icon: Store, count: (s) => s.sellers.pending },
    { to: '/admin/listings', label: 'Listings', icon: Package, count: (s) => s.products.pending_review },
    { to: '/admin/reviews', label: 'Reviews', icon: Star },
  ]],
  ['Money', [
    { to: '/admin/settlements', label: 'Settlements', icon: Wallet },
    { to: '/admin/reports', label: 'Reports', icon: BarChart3 },
  ]],
  ['Setup', [
    { to: '/admin/categories', label: 'Categories', icon: Tags },
    { to: '/admin/delivery', label: 'Delivery', icon: Truck },
    { to: '/admin/settings', label: 'Settings', icon: Settings },
  ]],
  ['Records', [
    { to: '/admin/users', label: 'Users', icon: Users },
    { to: '/admin/sms', label: 'Text messages', icon: MessageSquare },
    { to: '/admin/audit', label: 'Audit log', icon: History },
  ]],
]
const LINKS = GROUPS.flatMap(([, links]) => links)

function current(pathname) {
  return LINKS.filter((l) => (l.end ? pathname === l.to || pathname === `${l.to}/` : pathname.startsWith(l.to)))
    .sort((a, b) => b.to.length - a.to.length)[0]
}

function Nav({ summary }) {
  return (
    <nav aria-label="Admin" className="space-y-4">
      {GROUPS.map(([group, links]) => (
        <div key={group}>
          <p className="mb-1 px-3 text-xs font-semibold tracking-wide text-stone-400 uppercase">{group}</p>
          <ul>
            {links.map(({ to, label, icon: Icon, end, count }) => {
              const n = summary && count ? count(summary) : 0
              return (
                <li key={to}>
                  <NavLink to={to} end={end}
                    className={({ isActive }) => `flex items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm ${isActive ? 'bg-brand-50 font-semibold text-brand-800' : 'text-stone-700 hover:bg-stone-100'}`}>
                    <Icon className="size-4 shrink-0" aria-hidden="true" />
                    <span className="flex-1">{label}</span>
                    {n > 0 && <span className="rounded-full bg-amber-100 px-1.5 text-xs font-semibold text-amber-800">{n}<span className="sr-only"> waiting</span></span>}
                  </NavLink>
                </li>
              )
            })}
          </ul>
        </div>
      ))}
    </nav>
  )
}

export default function AdminLayout() {
  const { pathname } = useLocation()
  const summary = useFetch('/admin/summary')
  const { reload } = summary
  const [menuOpen, setMenuOpen] = useState(false)
  const here = current(pathname)

  // Counts beside the links stay fresh as the admin works through them (useFetch loads the first time).
  const first = useRef(true)
  useEffect(() => {
    setMenuOpen(false)
    if (first.current) {
      first.current = false
      return
    }
    reload()
  }, [pathname, reload])

  const s = summary.data?.data
  const waiting = s ? LINKS.reduce((n, l) => n + (l.count ? l.count(s) : 0), 0) : 0

  return (
    <div className="container-page py-5 lg:grid lg:grid-cols-[12.5rem_minmax(0,1fr)] lg:gap-8 lg:py-6">
      <aside className="hidden lg:block">
        <div className="sticky top-20"><Nav summary={s} /></div>
      </aside>

      {/* Phones and tablets: one button that opens the same grouped menu. */}
      <div className="mb-4 lg:hidden">
        <button type="button" className="btn-secondary w-full justify-between" aria-expanded={menuOpen} aria-controls="admin-menu" onClick={() => setMenuOpen((o) => !o)}>
          <span className="flex items-center gap-2">
            {here && <here.icon className="size-4" aria-hidden="true" />}
            {here?.label ?? 'Admin'}
          </span>
          <span className="flex items-center gap-2">
            {waiting > 0 && <span className="rounded-full bg-amber-100 px-2 text-xs font-semibold text-amber-800">{waiting} to do</span>}
            <ChevronDown className={`size-4 transition ${menuOpen ? 'rotate-180' : ''}`} aria-hidden="true" />
          </span>
        </button>
        {menuOpen && <div id="admin-menu" className="card mt-2 p-2"><Nav summary={s} /></div>}
      </div>

      <div className="min-w-0">
        <Suspense fallback={<Spinner />}>
          <Outlet context={summary} />
        </Suspense>
      </div>
    </div>
  )
}
