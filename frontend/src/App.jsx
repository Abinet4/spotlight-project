import { Route, Routes } from 'react-router-dom'
import Layout from './components/Layout'
import RequireRole from './components/RequireRole'
import { NotFound } from './components/ui'
import AdminAudit from './pages/admin/AdminAudit'
import AdminCategories from './pages/admin/AdminCategories'
import AdminDelivery from './pages/admin/AdminDelivery'
import AdminLayout from './pages/admin/AdminLayout'
import { AdminOrderDetail, AdminOrders } from './pages/admin/AdminOrders'
import AdminListings from './pages/admin/AdminListings'
import AdminOverview from './pages/admin/AdminOverview'
import AdminSellers from './pages/admin/AdminSellers'
import AdminSettings from './pages/admin/AdminSettings'
import Addresses from './pages/Addresses'
import { Login, Register, SellerApply } from './pages/Auth'
import Cart from './pages/Cart'
import Checkout from './pages/Checkout'
import Catalog from './pages/Catalog'
import Help from './pages/Help'
import Home from './pages/Home'
import { ConversationView, Inbox } from './pages/Messages'
import { OrderDetail, Orders } from './pages/Orders'
import ProductDetail from './pages/ProductDetail'
import SellerDashboard from './pages/seller/SellerDashboard'
import { SellerOrderDetail, SellerOrders } from './pages/seller/SellerOrders'
import SellerProductForm from './pages/seller/SellerProductForm'
import SellerProfile from './pages/seller/SellerProfile'
import Storefront from './pages/Storefront'
import Wishlist from './pages/Wishlist'

export default function App() {
  return (
    <Routes>
      <Route element={<Layout />}>
        <Route index element={<Home />} />
        <Route path="search" element={<Catalog />} />
        <Route path="category/:slug" element={<Catalog />} />
        <Route path="products/:slug" element={<ProductDetail />} />
        <Route path="sellers/:slug" element={<Storefront />} />
        <Route path="help/:topic" element={<Help />} />
        <Route path="login" element={<Login />} />
        <Route path="register" element={<Register />} />
        <Route path="sell" element={<SellerApply />} />

        <Route path="wishlist" element={<RequireRole><Wishlist /></RequireRole>} />
        <Route path="messages" element={<RequireRole roles={['customer', 'seller']}><Inbox /></RequireRole>} />
        <Route path="messages/:id" element={<RequireRole roles={['customer', 'seller']}><ConversationView /></RequireRole>} />
        <Route path="account/addresses" element={<RequireRole roles={['customer']}><Addresses /></RequireRole>} />
        <Route path="cart" element={<RequireRole roles={['customer']}><Cart /></RequireRole>} />
        <Route path="checkout" element={<RequireRole roles={['customer']}><Checkout /></RequireRole>} />
        <Route path="orders" element={<RequireRole roles={['customer']}><Orders /></RequireRole>} />
        <Route path="orders/:id" element={<RequireRole roles={['customer']}><OrderDetail /></RequireRole>} />

        <Route path="seller" element={<RequireRole roles={['seller']}><SellerDashboard /></RequireRole>} />
        <Route path="seller/orders" element={<RequireRole roles={['seller']}><SellerOrders /></RequireRole>} />
        <Route path="seller/orders/:id" element={<RequireRole roles={['seller']}><SellerOrderDetail /></RequireRole>} />
        <Route path="seller/profile" element={<RequireRole roles={['seller']}><SellerProfile /></RequireRole>} />
        <Route path="seller/products/new" element={<RequireRole roles={['seller']}><SellerProductForm /></RequireRole>} />
        <Route path="seller/products/:id/edit" element={<RequireRole roles={['seller']}><SellerProductForm /></RequireRole>} />

        <Route path="admin" element={<RequireRole roles={['admin']}><AdminLayout /></RequireRole>}>
          <Route index element={<AdminOverview />} />
          <Route path="orders" element={<AdminOrders />} />
          <Route path="orders/:id" element={<AdminOrderDetail />} />
          <Route path="sellers" element={<AdminSellers />} />
          <Route path="listings" element={<AdminListings />} />
          <Route path="categories" element={<AdminCategories />} />
          <Route path="delivery" element={<AdminDelivery />} />
          <Route path="settings" element={<AdminSettings />} />
          <Route path="audit" element={<AdminAudit />} />
        </Route>

        <Route path="*" element={<NotFound />} />
      </Route>
    </Routes>
  )
}
