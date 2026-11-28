import { lazy } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import Layout from './components/Layout';
import { GuestRoute, ProtectedRoute, RoleRoute } from './auth/guards';
import LoginPage from './pages/LoginPage';
import ForgotPasswordPage from './pages/ForgotPasswordPage';
import ResetPasswordPage from './pages/ResetPasswordPage';
import HomePage from './pages/HomePage';
import AcceptInvitationPage from './pages/AcceptInvitationPage';
import NotFoundPage from './pages/NotFoundPage';
import CatalogPage from './features/catalog/CatalogPage';
import ProductDetailPage from './features/catalog/ProductDetailPage';
import CartPage from './features/cart/CartPage';
import CheckoutPage from './features/cart/CheckoutPage';
import OrdersPage from './features/orders/OrdersPage';
import OrderDetailPage from './features/orders/OrderDetailPage';

// Login, home, catalog, cart, checkout and orders stay in the entry chunk; quotes,
// invoices and the back office load on first visit (docs/phase-7-design.md 6.4).
const QuotesPage = lazy(() => import('./features/quotes/QuotesPage'));
const QuoteDetailPage = lazy(() => import('./features/quotes/QuoteDetailPage'));
const QuoteFormPage = lazy(() => import('./features/quotes/QuoteFormPage'));
const InvoicesPage = lazy(() => import('./features/invoices/InvoicesPage'));
const InvoiceDetailPage = lazy(
  () => import('./features/invoices/InvoiceDetailPage'),
);
const AdminRoutes = lazy(() => import('./features/admin/AdminRoutes'));

export default function AppRoutes() {
  return (
    <Routes>
      <Route element={<Layout />}>
        <Route element={<GuestRoute />}>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/forgot-password" element={<ForgotPasswordPage />} />
          <Route path="/reset-password" element={<ResetPasswordPage />} />
          <Route path="/accept-invitation" element={<AcceptInvitationPage />} />
        </Route>
        <Route element={<ProtectedRoute />}>
          <Route path="/" element={<HomePage />} />
          <Route path="/catalog" element={<CatalogPage />} />
          <Route path="/catalog/:id" element={<ProductDetailPage />} />
          <Route path="/orders" element={<OrdersPage />} />
          <Route path="/orders/:number" element={<OrderDetailPage />} />
          <Route path="/quotes" element={<QuotesPage />} />
          <Route path="/quotes/:number" element={<QuoteDetailPage />} />
          <Route path="/invoices" element={<InvoicesPage />} />
          <Route path="/invoices/:id" element={<InvoiceDetailPage />} />
          <Route element={<RoleRoute roles={['customer']} />}>
            <Route path="/cart" element={<CartPage />} />
            <Route path="/checkout" element={<CheckoutPage />} />
            <Route path="/quotes/new" element={<QuoteFormPage />} />
          </Route>
          <Route path="/staff" element={<Navigate to="/admin" replace />} />
          <Route element={<RoleRoute roles={['staff', 'admin']} />}>
            <Route path="/admin/*" element={<AdminRoutes />} />
          </Route>
        </Route>
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  );
}
