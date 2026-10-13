import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import App from './App'
import { AlertsProvider } from './context/AlertsContext'
import { AuthProvider } from './context/AuthContext'
import { CartProvider } from './context/CartContext'
import { CompareProvider } from './context/CompareContext'
import { ConfigProvider } from './context/ConfigContext'
import { InboxProvider } from './context/InboxContext'
import './index.css'

// After a new version of the site is published, a tab that was already open may ask for page
// files that no longer exist. Reload once to pick up the new version instead of showing an error.
window.addEventListener('vite:preloadError', (event) => {
  try {
    const last = Number(sessionStorage.getItem('mp_reloaded_at') || 0)
    if (Date.now() - last < 10000) return // just reloaded and still failing: show the error instead
    sessionStorage.setItem('mp_reloaded_at', String(Date.now()))
  } catch {
    return // without storage a reload could repeat forever
  }
  event.preventDefault()
  window.location.reload()
})

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <BrowserRouter>
      <ConfigProvider>
        <AuthProvider>
          <InboxProvider>
            <AlertsProvider>
              <CartProvider>
                <CompareProvider>
                  <App />
                </CompareProvider>
              </CartProvider>
            </AlertsProvider>
          </InboxProvider>
        </AuthProvider>
      </ConfigProvider>
    </BrowserRouter>
  </StrictMode>,
)
