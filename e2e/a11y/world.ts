import { getJson, send, sendForm } from '../helpers/api';
import { addToCart, apiAs, apiCustomer, clearCart, createProduct, placeOrder, setOrderStatus, stamp, tinyPdf, utcDate } from '../helpers/data';

/** Data the accessibility specs need: one of each thing a page can show. Created through the API, unique per run. */
export interface World {
  product: { id: number; part_number: string; brand: string };
  confirmedOrder: string; // has an invoice
  pendingOrder: string; // staff status dialog
  invoiceId: string; // open
  invoiceNumber: string;
  quoteWithFiles: string; // submitted, one PDF and one CSV attachment
  quoteInReview: string; // staff pricing form
  quoteQuoted: string; // customer accept dialog
  companyId: number;
  userId: number; // a customer user (admin user detail)
}

export async function createWorld(): Promise<World> {
  const staff = await apiAs('staff');
  const admin = await apiAs('admin');
  const alice = await apiCustomer();
  try {
    const ts = stamp();
    const product = await createProduct(staff, { prefix: 'AX', price_cents: 1200, brand: 'AxeBrand' });

    await clearCart(alice);
    await addToCart(alice, product.id, 2);
    const confirmedOrder = await placeOrder(alice, `AX-${ts}-1`);
    await setOrderStatus(staff, confirmedOrder, 'confirmed');
    const inv = await send(staff, 'POST', `/api/v1/orders/${confirmedOrder}/invoice`, { due_date: utcDate(20) });
    if (inv.status() !== 201) throw new Error(`world: invoice ${inv.status()} ${await inv.text()}`);
    const invoice = (await inv.json()).data;
    await addToCart(alice, product.id, 1);
    const pendingOrder = await placeOrder(alice, `AX-${ts}-2`);

    const quote = async (note: string) => {
      const res = await send(alice, 'POST', '/api/v1/quotes', {
        po_reference: `AX-${ts}-Q`,
        customer_note: note,
        lines: [
          { product_id: product.id, quantity: 4 },
          { description: 'Custom taper roller bearing 32208', quantity: 2 },
        ],
      });
      if (res.status() !== 201) throw new Error(`world: quote ${res.status()} ${await res.text()}`);
      return (await res.json()).data.number as string;
    };
    const quoteWithFiles = await quote('Quote with attachments');
    for (const file of [
      { name: 'drawing.pdf', mimeType: 'application/pdf', buffer: tinyPdf('drawing') },
      { name: 'parts.csv', mimeType: 'text/csv', buffer: Buffer.from('part,qty\n6205,4\n32208,2\n', 'utf8') },
    ]) {
      const up = await sendForm(alice, `/api/v1/quotes/${quoteWithFiles}/attachments`, { file });
      if (up.status() !== 201) throw new Error(`world: upload ${up.status()} ${await up.text()}`);
    }
    const quoteInReview = await quote('Quote in review');
    await send(staff, 'POST', `/api/v1/quotes/${quoteInReview}/review`);
    const quoteQuoted = await quote('Quote that was sent');
    await send(staff, 'POST', `/api/v1/quotes/${quoteQuoted}/review`);
    await send(staff, 'PUT', `/api/v1/quotes/${quoteQuoted}/pricing`, {
      lines: [
        { position: 1, unit_price_cents: 1300 },
        { position: 2, unit_price_cents: 3875 },
      ],
      valid_until: utcDate(10),
    });
    const sent = await send(staff, 'POST', `/api/v1/quotes/${quoteQuoted}/send`, { staff_note: 'Lead time 3 weeks.' });
    if (sent.status() !== 200) throw new Error(`world: send ${sent.status()} ${await sent.text()}`);

    const me = (await getJson(alice, '/api/v1/me')).data;
    return {
      product,
      confirmedOrder,
      pendingOrder,
      invoiceId: invoice.id,
      invoiceNumber: invoice.number,
      quoteWithFiles,
      quoteInReview,
      quoteQuoted,
      companyId: me.company.id,
      userId: me.id,
    };
  } finally {
    await Promise.all([staff.dispose(), admin.dispose(), alice.dispose()]);
  }
}
