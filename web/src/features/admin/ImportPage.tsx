import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import ConfirmDialog from '../../components/ConfirmDialog';
import { ApiError, describeError } from '../../lib/api';
import {
  useApplyImport,
  usePreviewImport,
  type ImportPreview,
  type ImportResult,
} from './api';
import { fmtUtc } from './format';
import { linkCls } from './forms';

const MAX_BYTES = 5 * 1024 * 1024;
const btn = 'rounded border border-slate-600 px-3 py-1 disabled:opacity-60';
const primary =
  'rounded bg-blue-700 px-4 py-2 text-white hover:bg-blue-800 disabled:opacity-60';

/** Upload failures with a status the shared describeError does not know about. */
function uploadMessage(e: unknown): string {
  if (e instanceof ApiError) {
    if (e.status === 413) return 'The file is too large for the server.';
    if (e.status === 422) return e.errors.file?.[0] ?? describeError(e);
  }
  return describeError(e);
}

type ApplyFailure = { message: string; restart: boolean };
function applyFailure(e: unknown): ApplyFailure {
  if (e instanceof ApiError && e.status === 422) {
    const m = e.errors.import?.[0];
    if (m) return { message: m, restart: true };
    return {
      message: e.errors.skip_invalid?.[0] ?? describeError(e),
      restart: false,
    };
  }
  return { message: describeError(e), restart: false };
}

function Preview({
  preview,
  onRestart,
  onDone,
}: {
  preview: ImportPreview;
  onRestart: () => void;
  onDone: (r: ImportResult) => void;
}) {
  const apply = useApplyImport(preview.id);
  const [skip, setSkip] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [failure, setFailure] = useState<ApplyFailure | null>(null);
  const guard = useRef(false);
  const bad = preview.bad_rows;
  const hidden = bad - preview.errors.length;
  const ready = bad === 0 || skip;

  const run = () => {
    if (guard.current) return;
    guard.current = true;
    apply.mutate(skip, {
      onSuccess: (res) => onDone(res.data),
      onError: (e) => {
        setFailure(applyFailure(e));
        setConfirming(false);
      },
      onSettled: () => {
        guard.current = false;
      },
    });
  };

  return (
    <>
      <p className="mb-2">
        File: {preview.file_name} ({Math.ceil(preview.size_bytes / 1024)} KiB)
      </p>
      <dl className="mb-4 grid max-w-sm grid-cols-2 gap-1">
        <dt>Rows read</dt>
        <dd>{preview.rows_read}</dd>
        <dt>Would create</dt>
        <dd>{preview.would_create}</dd>
        <dt>Would update</dt>
        <dd>{preview.would_update}</dd>
        <dt>Invalid rows</dt>
        <dd>{bad}</dd>
      </dl>
      <p className="mb-4 text-sm">
        This preview expires at {fmtUtc(preview.expires_at)}.
      </p>

      {preview.warnings.length > 0 && (
        <section aria-labelledby="imp-warn" className="mb-4">
          <h2 id="imp-warn" className="mb-1 font-semibold">
            Warnings
          </h2>
          <ul className="list-disc pl-5 text-sm">
            {preview.warnings.map((w, i) => (
              <li key={i}>{w}</li>
            ))}
          </ul>
        </section>
      )}

      {preview.errors.length > 0 && (
        <div className="mb-4 overflow-x-auto">
          <table className="w-full border-collapse">
            <caption className="mb-1 text-left font-semibold">
              Invalid rows
            </caption>
            <thead>
              <tr className="border-b border-slate-400 bg-slate-100">
                <th scope="col" className="px-3 py-2 text-left text-sm">
                  Line
                </th>
                <th scope="col" className="px-3 py-2 text-left text-sm">
                  Problems
                </th>
              </tr>
            </thead>
            <tbody>
              {preview.errors.map((e) => (
                <tr key={e.line} className="border-b border-slate-300">
                  <td className="px-3 py-2 align-top text-sm">{e.line}</td>
                  <td className="px-3 py-2 text-sm">
                    <ul className="list-disc pl-4">
                      {e.messages.map((m, i) => (
                        <li key={i}>{m}</li>
                      ))}
                    </ul>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {hidden > 0 && <p className="mt-1 text-sm">and {hidden} more</p>}
        </div>
      )}

      {bad > 0 && (
        <label className="mb-4 flex items-center gap-2">
          <input
            type="checkbox"
            checked={skip}
            onChange={(e) => setSkip(e.target.checked)}
          />
          Skip the {bad} invalid {bad === 1 ? 'row' : 'rows'}
        </label>
      )}

      {failure && (
        <div
          role="alert"
          className="mb-4 rounded border border-red-700 bg-red-50 p-3 text-red-800"
        >
          <p>{failure.message}</p>
          {failure.restart && (
            <button type="button" onClick={onRestart} className={`mt-2 ${btn}`}>
              Upload again
            </button>
          )}
        </div>
      )}

      <div className="flex gap-2">
        <button
          type="button"
          className={primary}
          disabled={!ready || apply.isPending}
          onClick={() => {
            setFailure(null);
            setConfirming(true);
          }}
        >
          Apply import
        </button>
        <button type="button" className={btn} onClick={onRestart}>
          Upload a different file
        </button>
      </div>

      {confirming && (
        <ConfirmDialog
          title="Apply this import?"
          confirmLabel="Confirm"
          cancelLabel="Cancel"
          tone="primary"
          pending={apply.isPending}
          onCancel={() => setConfirming(false)}
          onConfirm={run}
        >
          <p>
            {preview.would_create} products will be created and{' '}
            {preview.would_update} updated.
            {bad > 0 && ` ${bad} invalid rows will be skipped.`} Nothing is
            deleted.
          </p>
        </ConfirmDialog>
      )}
    </>
  );
}

export default function ImportPage() {
  const preview = usePreviewImport();
  const [file, setFile] = useState<File | null>(null);
  const [fileError, setFileError] = useState('');
  const [data, setData] = useState<ImportPreview | null>(null);
  const [result, setResult] = useState<ImportResult | null>(null);
  const headingRef = useRef<HTMLHeadingElement>(null);
  const step = result ? 3 : data ? 2 : 1;

  // Focus follows the wizard: the heading announces each new step.
  useEffect(() => {
    headingRef.current?.focus();
  }, [step]);

  const upload = (e: React.FormEvent) => {
    e.preventDefault();
    if (!file) return setFileError('Choose a CSV file.');
    if (!/\.csv$/i.test(file.name))
      return setFileError('The file must be a .csv file.');
    if (file.size > MAX_BYTES)
      return setFileError('The file is larger than 5 MiB.');
    if (file.size === 0) return setFileError('The file is empty.');
    setFileError('');
    preview.mutate(file, { onSuccess: (res) => setData(res.data) });
  };

  const restart = () => {
    setData(null);
    setResult(null);
    setFile(null);
    setFileError('');
    preview.reset();
  };

  const titles = ['Upload a CSV file', 'Check the import', 'Import complete'];
  return (
    <section>
      <h1
        ref={headingRef}
        tabIndex={-1}
        className="mb-4 text-2xl font-semibold outline-none"
      >
        Import products: step {step} of 3, {titles[step - 1].toLowerCase()}
      </h1>

      {step === 1 && (
        <form onSubmit={upload} noValidate>
          <div className="mb-4">
            <label
              htmlFor="csv-file"
              className="mb-1 block text-sm font-medium"
            >
              CSV file
            </label>
            <input
              id="csv-file"
              type="file"
              accept=".csv,text/csv"
              aria-describedby="csv-hint"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            />
            <p id="csv-hint" className="mt-1 text-xs text-slate-700">
              Up to 5 MiB and 5,000 rows. Nothing changes until you confirm on
              the next step. Larger files: use the command line import.
            </p>
          </div>
          {(fileError || preview.error) && (
            <p role="alert" className="mb-4 text-red-700">
              {fileError || uploadMessage(preview.error)}
            </p>
          )}
          <button
            type="submit"
            className={primary}
            disabled={preview.isPending}
          >
            {preview.isPending ? 'Checking...' : 'Upload and check'}
          </button>
        </form>
      )}

      {step === 2 && data && (
        <Preview preview={data} onRestart={restart} onDone={setResult} />
      )}

      {step === 3 && result && (
        <div role="status">
          <dl className="mb-4 grid max-w-sm grid-cols-2 gap-1">
            <dt>Created</dt>
            <dd>{result.created}</dd>
            <dt>Updated</dt>
            <dd>{result.updated}</dd>
            <dt>Invalid rows skipped</dt>
            <dd>{result.bad_rows}</dd>
          </dl>
          <Link to="/admin/products" className={linkCls}>
            Back to products
          </Link>{' '}
          <button type="button" onClick={restart} className={`ml-4 ${btn}`}>
            Import another file
          </button>
        </div>
      )}
    </section>
  );
}
