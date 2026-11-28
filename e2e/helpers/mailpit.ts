import { expect, request, type APIRequestContext } from '@playwright/test';
import { MAILPIT_URL } from './env';

/** True when Mailpit answers and the stack sends real SMTP (not E2E_MAIL_MAILER=log). */
export async function mailpitUp(): Promise<boolean> {
  if (process.env.E2E_MAIL_MAILER === 'log') return false;
  const ctx = await request.newContext();
  try {
    const res = await ctx.get(`${MAILPIT_URL}/api/v1/info`, { timeout: 3_000 });
    return res.ok();
  } catch {
    return false;
  } finally {
    await ctx.dispose();
  }
}

export interface Mail {
  ID: string;
  Subject: string;
  To: { Address: string }[];
  Text: string;
  HTML: string;
}

/**
 * Waits (polling on state, bounded by the timeout) for one message and returns it with its text and HTML.
 * `subject` is matched as a phrase by Mailpit's search, so use a unique string such as the quote number.
 */
export async function waitForMail(
  api: APIRequestContext,
  { to, subject }: { to?: string; subject: string },
): Promise<Mail> {
  const query = [to ? `to:${to}` : '', `subject:"${subject}"`].filter(Boolean).join(' ');
  let id = '';
  await expect
    .poll(
      async () => {
        const res = await api.get(`${MAILPIT_URL}/api/v1/search`, { params: { query } });
        const body = await res.json();
        id = body.messages?.[0]?.ID ?? '';
        return id;
      },
      { message: `mail for ${query}`, timeout: 15_000 },
    )
    .not.toBe('');
  const message = await api.get(`${MAILPIT_URL}/api/v1/message/${id}`);
  return message.json();
}

/** Deletes every message (end of a run). */
export async function emptyMailpit(api: APIRequestContext) {
  await api.delete(`${MAILPIT_URL}/api/v1/messages`);
}
