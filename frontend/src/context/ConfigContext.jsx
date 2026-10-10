import { createContext, useContext, useEffect, useState } from 'react'
import { api } from '../lib/api'

const fallback = {
  name: import.meta.env.VITE_BRAND_NAME || 'Furniture Marketplace',
  currency: 'ETB',
  max_product_images: 8,
  max_image_kb: 5120,
}

const ConfigContext = createContext(fallback)

export function ConfigProvider({ children }) {
  const [config, setConfig] = useState(fallback)

  useEffect(() => {
    api
      .get('/config')
      .then((res) => setConfig({ ...fallback, ...res.data }))
      .catch(() => {})
  }, [])

  useEffect(() => {
    document.title = config.name
  }, [config.name])

  return <ConfigContext.Provider value={config}>{children}</ConfigContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useConfig() {
  return useContext(ConfigContext)
}
