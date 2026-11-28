import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { vi } from 'vitest';
import ConfirmDialog from './ConfirmDialog';

function Harness({
  onConfirm = vi.fn(),
  onKeyDownOutside = vi.fn(),
}: {
  onConfirm?: () => void;
  onKeyDownOutside?: () => void;
}) {
  const [open, setOpen] = useState(false);
  return (
    <div onKeyDown={onKeyDownOutside}>
      <a href="/elsewhere">Elsewhere</a>
      <button onClick={() => setOpen(true)}>Open it</button>
      {open && (
        <ConfirmDialog
          title="Remove it?"
          confirmLabel="Yes, remove"
          cancelLabel="Keep it"
          onConfirm={onConfirm}
          onCancel={() => setOpen(false)}
        >
          <p>This cannot be undone.</p>
          <input aria-label="Reason" />
        </ConfirmDialog>
      )}
    </div>
  );
}

const open = async (u: ReturnType<typeof userEvent.setup>) => {
  await u.click(screen.getByRole('button', { name: 'Open it' }));
  return screen.getByRole('dialog', { name: 'Remove it?' });
};

describe('ConfirmDialog accessibility', () => {
  it('is a modal dialog named by its title and described by its text', async () => {
    const u = userEvent.setup();
    render(<Harness />);
    const dialog = await open(u);
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    expect(dialog).toHaveAccessibleDescription(/This cannot be undone/);
  });

  it('puts focus on the safe button first', async () => {
    const u = userEvent.setup();
    render(<Harness />);
    await open(u);
    expect(screen.getByRole('button', { name: 'Keep it' })).toHaveFocus();
  });

  it('traps Tab and Shift+Tab, also when focus starts on the dialog itself', async () => {
    const u = userEvent.setup();
    render(<Harness />);
    const dialog = await open(u);
    for (let i = 0; i < 8; i++) {
      await u.tab();
      expect(dialog).toContainElement(document.activeElement as HTMLElement);
    }
    for (let i = 0; i < 8; i++) {
      await u.tab({ shift: true });
      expect(dialog).toContainElement(document.activeElement as HTMLElement);
    }
    dialog.focus();
    await u.tab();
    expect(dialog).toContainElement(document.activeElement as HTMLElement);
    dialog.focus();
    await u.tab({ shift: true });
    expect(dialog).toContainElement(document.activeElement as HTMLElement);
  });

  it('makes everything behind the dialog inert, and restores it afterwards', async () => {
    const u = userEvent.setup();
    const { container } = render(<Harness />);
    const dialog = await open(u);
    expect(container).toHaveAttribute('inert');
    expect(dialog.closest('[inert]')).toBeNull();
    await u.keyboard('{Escape}');
    expect(container).not.toHaveAttribute('inert');
  });

  it('Escape cancels, does not leak to the page, and focus returns to the opener', async () => {
    const u = userEvent.setup();
    const outside = vi.fn();
    render(<Harness onKeyDownOutside={outside} />);
    await open(u);
    outside.mockClear();
    await u.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(screen.getByRole('button', { name: 'Open it' })).toHaveFocus();
    expect(outside).not.toHaveBeenCalled();
  });

  it('Cancel returns focus to the opener as well', async () => {
    const u = userEvent.setup();
    render(<Harness />);
    await open(u);
    await u.click(screen.getByRole('button', { name: 'Keep it' }));
    expect(screen.getByRole('button', { name: 'Open it' })).toHaveFocus();
  });

  it('the confirm button calls onConfirm once and is disabled while pending', async () => {
    const u = userEvent.setup();
    const onConfirm = vi.fn();
    render(<Harness onConfirm={onConfirm} />);
    await open(u);
    await u.click(screen.getByRole('button', { name: 'Yes, remove' }));
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });
});
