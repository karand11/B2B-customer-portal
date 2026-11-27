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
import QuotesPage from './features/quotes/QuotesPage';
import QuoteDetailPage from './features/quotes/QuoteDetailPage';
import QuoteFormPage from './features/quotes/QuoteFormPage';
import InvoicesPage from './features/invoices/InvoicesPage';
import InvoiceDetailPage from './features/invoices/InvoiceDetailPage';
import AdminLayout from './features/admin/AdminLayout';
import DashboardPage from './features/admin/DashboardPage';
import ProductsPage from './features/admin/ProductsPage';
import ProductEditPage, {
  ProductCreatePage,
} from './features/admin/ProductFormPage';
import ImportPage from './features/admin/ImportPage';
import CompaniesPage from './features/admin/CompaniesPage';
import CompanyDetailPage, {
  CompanyNewPage,
} from './features/admin/CompanyDetailPage';
import UsersPage from './features/admin/UsersPage';
import UserDetailPage from './features/admin/UserDetailPage';
import AuditPage from './features/admin/AuditPage';

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
            <Route path="/admin" element={<AdminLayout />}>
              <Route index element={<DashboardPage />} />
              <Route path="orders" element={<OrdersPage basePath="/admin" />} />
              <Route
                path="orders/:number"
                element={<OrderDetailPage basePath="/admin" />}
              />
              <Route path="quotes" element={<QuotesPage basePath="/admin" />} />
              <Route
                path="quotes/:number"
                element={<QuoteDetailPage basePath="/admin" />}
              />
              <Route
                path="invoices"
                element={<InvoicesPage basePath="/admin" />}
              />
              <Route
                path="invoices/:id"
                element={<InvoiceDetailPage basePath="/admin" />}
              />
              <Route path="products" element={<ProductsPage />} />
              <Route path="products/new" element={<ProductCreatePage />} />
              <Route path="products/import" element={<ImportPage />} />
              <Route path="products/:id" element={<ProductEditPage />} />
              <Route path="companies" element={<CompaniesPage />} />
              <Route path="companies/new" element={<CompanyNewPage />} />
              <Route path="companies/:id" element={<CompanyDetailPage />} />
              <Route element={<RoleRoute roles={['admin']} />}>
                <Route path="users" element={<UsersPage />} />
                <Route path="users/:id" element={<UserDetailPage />} />
                <Route path="audit-log" element={<AuditPage />} />
              </Route>
            </Route>
          </Route>
        </Route>
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  );
}
