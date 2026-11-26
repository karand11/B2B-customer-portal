import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import {
  attachmentUrl,
  detailKey,
  useDeleteAttachment,
  type QuoteAttachment,
  type QuoteDetail,
} from './api';
import { ActionDialog } from './ActionDialogs';
import { ACCEPT_ATTR, checkFiles, formatBytes } from './schemas';
import { UPLOAD_LABELS, useUploads } from './useUploads';

const btn = 'rounded border border-slate-600 px-2 py-0.5 text-sm';

/** Downloads go through the API only (the session cookie authorises the GET). */
export default function AttachmentsPanel({ quote }: { quote: QuoteDetail }) {
  const qc = useQueryClient();
  const uploads = useUploads();
  const del = useDeleteAttachment(quote.number);
  const [rejected, setRejected] = useState<{ name: string; reason: string }[]>(
    [],
  );
  const [deleting, setDeleting] = useState<QuoteAttachment | null>(null);
  const canAttach = quote.allowed_actions.includes('attach');

  const onFiles = async (list: FileList | null) => {
    const check = checkFiles([...(list ?? [])], {
      count: quote.attachments.length,
      bytes: quote.attachments.reduce((n, a) => n + a.size_bytes, 0),
    });
    setRejected(check.rejected);
    if (check.accepted.length === 0) return;
    await uploads.run(quote.number, check.accepted);
    void qc.invalidateQueries({ queryKey: detailKey(quote.number) });
  };

  const failed = uploads.items.filter((u) => u.state === 'failed');
  return (
    <section aria-labelledby="attachments-h" className="mt-6">
      <h2 id="attachments-h" className="mb-2 text-lg font-semibold">
        Attachments
      </h2>
      {quote.attachments.length === 0 ? (
        <p className="text-sm">No attachments.</p>
      ) : (
        <ul className="mb-2 space-y-1 text-sm">
          {quote.attachments.map((a) => (
            <li key={a.id} className="flex flex-wrap items-center gap-2">
              <a
                href={attachmentUrl(quote.number, a.id)}
                download
                className="text-blue-800 underline"
              >
                {a.name}
              </a>
              <span>
                ({formatBytes(a.size_bytes)},{' '}
                {a.uploaded_by.name ?? 'Portal staff'})
              </span>
              {canAttach && (
                <button
                  type="button"
                  aria-label={`Delete ${a.name}`}
                  onClick={() => setDeleting(a)}
                  className={btn}
                >
                  Delete
                </button>
              )}
            </li>
          ))}
        </ul>
      )}
      {canAttach && (
        <div>
          <label
            htmlFor="attachment-files"
            className="mb-1 block text-sm font-medium"
          >
            Add attachments
          </label>
          <input
            id="attachment-files"
            type="file"
            multiple
            accept={ACCEPT_ATTR}
            disabled={uploads.busy}
            aria-describedby="attachment-hint"
            onChange={(e) => {
              void onFiles(e.target.files);
              e.target.value = '';
            }}
          />
          <p id="attachment-hint" className="mt-1 text-xs text-slate-700">
            PDF, PNG, JPG, TXT or CSV. Up to 10 files, 10 MB each, 25 MB in
            total.
          </p>
          {(rejected.length > 0 || failed.length > 0) && (
            <ul
              role="alert"
              className="mt-1 list-disc pl-5 text-sm text-red-700"
            >
              {rejected.map((r, i) => (
                <li key={`r-${r.name}-${i}`}>
                  {r.name}: {r.reason}
                </li>
              ))}
              {failed.map((u, i) => (
                <li key={`f-${u.name}-${i}`}>
                  {u.name}: {u.message}
                </li>
              ))}
            </ul>
          )}
          <div aria-live="polite" className="mt-1 text-sm">
            {uploads.items
              .filter((u) => u.state !== 'failed')
              .map((u, i) => (
                <p key={`${u.name}-${i}`}>
                  {u.name}: {UPLOAD_LABELS[u.state]}
                </p>
              ))}
          </div>
        </div>
      )}
      {deleting && (
        <ActionDialog
          title="Delete this attachment?"
          confirmLabel="Yes, delete"
          cancelLabel="Keep file"
          tone="danger"
          onClose={() => setDeleting(null)}
          submit={() => del.mutateAsync(deleting.id)}
        >
          <p>{deleting.name} will be removed from the quote.</p>
        </ActionDialog>
      )}
    </section>
  );
}
