import { useRef, useState } from 'react';
import { downloadErrorText, downloadInvoicePdf } from './downloadPdf';

/** The success text sits in an always-present live region; errors are alerts. */
export default function DownloadPdfButton({
  id,
  number,
}: {
  id: string;
  number: string;
}) {
  const [pending, setPending] = useState(false);
  const [done, setDone] = useState('');
  const [error, setError] = useState('');
  const busy = useRef(false); // a double click sends one request

  const run = async () => {
    if (busy.current) return;
    busy.current = true;
    setPending(true);
    setDone('');
    setError('');
    try {
      const name = await downloadInvoicePdf(id, number);
      setDone(`Download started: ${name}`);
    } catch (e) {
      setError(downloadErrorText(e));
    } finally {
      busy.current = false;
      setPending(false);
    }
  };

  return (
    <div>
      <button
        type="button"
        disabled={pending}
        aria-busy={pending}
        onClick={() => void run()}
        className="rounded bg-blue-700 px-4 py-2 text-white hover:bg-blue-800 disabled:opacity-60"
      >
        Download PDF ({number})
      </button>
      <p role="status" aria-live="polite" className="mt-2 min-h-6 text-sm">
        {done}
      </p>
      {error && (
        <p role="alert" className="text-red-700">
          {error}
        </p>
      )}
    </div>
  );
}
