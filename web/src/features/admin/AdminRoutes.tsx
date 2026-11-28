import { Route, Routes } from 'react-router-dom';
import { RoleRoute } from '../../auth/guards';
import NotFoundPage from '../../pages/NotFoundPage';
import InvoiceDetailPage from '../invoices/InvoiceDetailPage';
import InvoicesPage from '../invoices/InvoicesPage';
import OrderDetailPage from '../orders/OrderDetailPage';
import OrdersPage from '../orders/OrdersPage';
import QuoteDetailPage from '../quotes/QuoteDetailPage';
import QuotesPage from '../quotes/QuotesPage';
import AdminLayout from './AdminLayout';
import AuditPage from './AuditPage';
import CompaniesPage from './CompaniesPage';
import CompanyDetailPage, { CompanyNewPage } from './CompanyDetailPage';
import DashboardPage from './DashboardPage';
import ImportPage from './ImportPage';
import ProductEditPage, { ProductCreatePage } from './ProductFormPage';
import ProductsPage from './ProductsPage';
import UserDetailPage from './UserDetailPage';
import UsersPage from './UsersPage';

/**
 * The whole back office, mounted at /admin/* and loaded as one lazy chunk (customers never
 * download it). Role checks live in AppRoutes (staff/admin) and here (admin-only pages);
 * both are UX only: the API enforces every role.
 */
export default function AdminRoutes() {
  return (
    <Routes>
      <Route element={<AdminLayout />}>
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
        <Route path="invoices" element={<InvoicesPage basePath="/admin" />} />
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
      <Route path="*" element={<NotFoundPage />} />
    </Routes>
  );
}
