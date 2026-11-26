import { vi } from 'vitest';
import type {
  Invoice,
  InvoiceDetail,
  InvoiceList,
  InvoiceStatus,
} from '../api';

export const INV_ID = '3f2b8c1e-4d5a-4b7e-9c10-2a6f5e8d7b90';
export const INV_PATH = `/invoices/${INV_ID}`;
export const INV_URL = `GET /api/v1/invoices/${INV_ID}`;
export const PDF_URL = `GET /api/v1/invoices/${INV_ID}/pdf`;
export const PAY_URL = `POST /api/v1/invoices/${INV_ID}/mark-paid`;
export const VOID_URL = `POST /api/v1/invoices/${INV_ID}/void`;
export const INVOICES = 'GET /api/v1/invoices';

export const invoice = (o: Partial<Invoice> = {}): Invoice => ({
  id: INV_ID,
  number: 'INV-2026-000123',
  status: 'open',
  currency: 'USD',
  subtotal_cents: 107079,
  vat_rate_bp: 1900,
  tax_cents: 20345,
  total_cents: 127424,
  issue_date: '2026-11-26',
  due_date: '2026-12-26',
  paid_on: null,
  days_overdue: 0,
  order_number: 'ORD-K7Q2M-X9PA3',
  po_reference: 'PO-4711/A',
  line_count: 2,
  company: { id: 1, name: 'Northwind Machinery Ltd' },
  created_at: '2026-11-26T10:15:00Z',
  ...o,
});

export const detail = (o: Partial<InvoiceDetail> = {}): InvoiceDetail => ({
  ...invoice(),
  seller: {
    name: 'Acme Parts GmbH',
    address_lines: ['Street 1', '00000 City'],
    tax_id: 'DE-123',
    email: 'billing@acme.test',
    phone: '+49 1',
    bank: {
      account_holder: 'Acme Parts GmbH',
      bank_name: 'Test Bank',
      iban: 'DE00 TEST',
      bic: 'TESTDEXX',
    },
    payment_terms_text: 'Please pay by bank transfer.',
    footer: 'Registered office: City',
    vat_label: 'VAT',
  },
  buyer: {
    name: 'Northwind Machinery Ltd',
    address_lines: ['12 Example Road', 'Sampletown'],
    tax_id: 'NW-TAX-0001',
  },
  items: [
    {
      position: 1,
      part_number: '6205-2RS',
      name: 'Deep groove ball bearing 6205-2RS 25x52x15',
      brand: 'SKF',
      quantity: 3,
      unit_price_cents: 1250,
      line_total_cents: 3750,
    },
    {
      position: 2,
      part_number: null,
      name: 'Taper roller bearing 32208, SKF or equivalent',
      brand: null,
      quantity: 10,
      unit_price_cents: 333,
      line_total_cents: 3330,
    },
  ],
  history: [
    {
      from_status: null,
      to_status: 'open',
      actor: { name: 'Sam Staff', role: 'staff' },
      note: null,
      created_at: '2026-11-26T10:15:00Z',
    },
  ],
  allowed_actions: ['download'],
  ...o,
});

export const STAFF_ACTIONS = ['download', 'mark_paid', 'void'];

export const listOfInvoices = (
  invoices: Invoice[],
  meta: Partial<InvoiceList['meta']> = {},
): InvoiceList => ({
  data: invoices,
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 20,
    total: invoices.length,
    from: invoices.length ? 1 : null,
    to: invoices.length || null,
    ...meta,
  },
});

/** One invoice per effective status, ids distinct. */
export const allStatuses = (): Invoice[] =>
  (
    [
      ['open', 'INV-2026-000001'],
      ['overdue', 'INV-2026-000002'],
      ['paid', 'INV-2026-000003'],
      ['void', 'INV-2026-000004'],
    ] as [InvoiceStatus, string][]
  ).map(([status, number], n) =>
    invoice({
      id: `3f2b8c1e-4d5a-4b7e-9c10-2a6f5e8d7b9${n}`,
      number,
      status,
      days_overdue: status === 'overdue' ? 12 : 0,
      paid_on: status === 'paid' ? '2026-12-01' : null,
    }),
  );

export const pdfSpec = (disposition: string | null = null) => ({
  headers: {
    'Content-Type': 'application/pdf',
    ...(disposition ? { 'Content-Disposition': disposition } : {}),
  },
  body: '%PDF-1.7 fake',
});

/** jsdom has no object URLs and does not download: record what the app asks for. */
export function stubDownload() {
  const createObjectURL = vi.fn<(b: Blob) => string>(() => 'blob:fake-url');
  const revokeObjectURL = vi.fn();
  Object.assign(URL, { createObjectURL, revokeObjectURL });
  const clicks: { download: string; href: string }[] = [];
  const click = vi
    .spyOn(HTMLAnchorElement.prototype, 'click')
    .mockImplementation(function (this: HTMLAnchorElement) {
      clicks.push({ download: this.download, href: this.href });
    });
  return {
    createObjectURL,
    revokeObjectURL,
    clicks,
    restore: () => {
      click.mockRestore();
      Reflect.deleteProperty(URL, 'createObjectURL');
      Reflect.deleteProperty(URL, 'revokeObjectURL');
    },
  };
}
