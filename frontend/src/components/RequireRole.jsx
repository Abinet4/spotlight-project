import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { EmptyState, Spinner } from './ui'

/**
 * Client-side gate for a better experience only. The API enforces every permission itself.
 */
export default function RequireRole({ roles, children }) {
  const { user, ready } = useAuth()
  const location = useLocation()

  if (!ready) return <Spinner />
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />
  if (roles && !roles.includes(user.role)) {
    return (
      <div className="container-page py-16">
        <EmptyState title="This area is not available for your account" />
      </div>
    )
  }
  return children
}
