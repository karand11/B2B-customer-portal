import { ApiError, describeError, fetchBlob } from '../../lib/api';

const FILENAME_RE = /^INV-\d{4}-\d{6,9}\.pdf$/;
const NUMBER_RE = /^INV-\d{4}-\d{6,9}$/;

/** The server sent something that is not a PDF (proxy page, JSON, ...). */
export class NotPdfError extends Error {}

/**
 * The saved name comes from Content-Disposition only if it has exactly the
 * shape the API promises; anything else (path tricks, odd characters, missing
 * header) falls back to "<number>.pdf", and a number that does not look right
 * falls back to a fixed name. Nothing from the response is trusted blindly.
 */
export function safeFilename(
  disposition: string | null,
  number: string,
): string {
  const m = /filename="([^"]*)"/i.exec(disposition ?? '');
  if (m && FILENAME_RE.test(m[1])) return m[1];
  return NUMBER_RE.test(number) ? `${number}.pdf` : 'invoice.pdf';
}

/** Fetches the PDF and hands it to the browser as a download. Returns the saved name. */
export async function downloadInvoicePdf(
  id: string,
  number: string,
): Promise<string> {
  const { blob, headers } = await fetchBlob(
    `/api/v1/invoices/${id}/pdf`,
    'application/pdf',
  );
  if (!(headers.get('Content-Type') ?? '').startsWith('application/pdf'))
    throw new NotPdfError();
  const name = safeFilename(headers.get('Content-Disposition'), number);
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  a.rel = 'noopener';
  a.hidden = true;
  document.body.append(a); // Firefox ignores clicks on detached anchors
  a.click();
  a.remove();
  // Revoke after the browser has started the download.
  setTimeout(() => URL.revokeObjectURL(url), 250);
  return name;
}

export function downloadErrorText(err: unknown): string {
  if (err instanceof NotPdfError)
    return 'The server did not send a PDF. Please try again.';
  if (err instanceof ApiError) {
    if (err.status === 404) return 'Invoice not found.';
    if (err.status === 429)
      return err.retryAfter
        ? `Too many downloads. Try again in ${err.retryAfter} seconds.`
        : 'Too many downloads. Try again later.';
  }
  return describeError(err);
}
