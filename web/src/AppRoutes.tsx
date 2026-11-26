import { Route, Routes } from 'react-router-dom';
import Layout from './components/Layout';
import { GuestRoute, ProtectedRoute, RoleRoute } from './auth/guards';
import LoginPage from './pages/LoginPage';
import ForgotPasswordPage from './pages/ForgotPasswordPage';
import ResetPasswordPage from './pages/ResetPasswordPage';
import HomePage from './pages/HomePage';
import StaffPage from './pages/StaffPage';
import AdminPage from './pages/AdminPage';
import NotFoundPage from './pages/NotFoundPage';
import CatalogPage from './features/catalog/CatalogPage';
import ProductDetailPage from './features/catalog/ProductDetailPage';
import CartPage from './features/cart/CartPage';
import CheckoutPage from './features/cart/CheckoutPage';
import OrdersPage from './features/orders/OrdersPage';
import OrderDetailPage from './features/orders/OrderDetailPage';
import QuotesPage from './features/quotes/QuotesPage';
import QuoteDetailPage from './features/quotes/QuoteDetailPage';
import QuoteFormPage from './features/quotes/QuoteFormPage';

export default function AppRoutes() {
  return (
    <Routes>
      <Route element={<Layout />}>
        <Route element={<GuestRoute />}>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/forgot-password" element={<ForgotPasswordPage />} />
          <Route path="/reset-password" element={<ResetPasswordPage />} />
        </Route>
        <Route element={<ProtectedRoute />}>
          <Route path="/" element={<HomePage />} />
          <Route path="/catalog" element={<CatalogPage />} />
          <Route path="/catalog/:id" element={<ProductDetailPage />} />
          <Route path="/orders" element={<OrdersPage />} />
          <Route path="/orders/:number" element={<OrderDetailPage />} />
          <Route path="/quotes" element={<QuotesPage />} />
          <Route path="/quotes/:number" element={<QuoteDetailPage />} />
          <Route element={<RoleRoute roles={['customer']} />}>
            <Route path="/cart" element={<CartPage />} />
            <Route path="/checkout" element={<CheckoutPage />} />
            <Route path="/quotes/new" element={<QuoteFormPage />} />
          </Route>
          <Route element={<RoleRoute roles={['staff', 'admin']} />}>
            <Route path="/staff" element={<StaffPage />} />
          </Route>
          <Route element={<RoleRoute roles={['admin']} />}>
            <Route path="/admin" element={<AdminPage />} />
          </Route>
        </Route>
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  );
}
