import { useCallback, useState } from 'react';
import { quoteErrorText, uploadAttachment } from './api';

export interface UploadItem {
  name: string;
  state: 'queued' | 'uploading' | 'done' | 'failed';
  message?: string;
}
export interface UploadFailure {
  name: string;
  message: string;
}

/** Uploads files ONE AT A TIME (the API takes one file per request) and tracks each. */
export function useUploads() {
  const [items, setItems] = useState<UploadItem[]>([]);
  const [busy, setBusy] = useState(false);

  const run = useCallback(
    async (number: string, files: File[]): Promise<UploadFailure[]> => {
      const patch = (i: number, p: Partial<UploadItem>) =>
        setItems((cur) => cur.map((x, k) => (k === i ? { ...x, ...p } : x)));
      const failures: UploadFailure[] = [];
      setBusy(true);
      setItems(files.map((f) => ({ name: f.name, state: 'queued' })));
      for (const [i, file] of files.entries()) {
        patch(i, { state: 'uploading' });
        try {
          await uploadAttachment(number, file);
          patch(i, { state: 'done' });
        } catch (e) {
          const message = quoteErrorText(e);
          failures.push({ name: file.name, message });
          patch(i, { state: 'failed', message });
        }
      }
      setBusy(false);
      return failures;
    },
    [],
  );

  return { items, busy, run };
}

export const UPLOAD_LABELS: Record<UploadItem['state'], string> = {
  queued: 'waiting',
  uploading: 'uploading...',
  done: 'uploaded',
  failed: 'failed',
};
