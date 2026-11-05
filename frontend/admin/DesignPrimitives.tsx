import {
  useEffect, useId, useRef,
  type ComponentPropsWithRef, type InputHTMLAttributes, type ReactNode,
} from 'react';

/** Native button keeps Enter/Space, disabled and form semantics. */
export type ActionButtonProps = ComponentPropsWithRef<'button'> & {
  variant?: 'primary' | 'secondary';
};

export function Button({ type = 'button', variant = 'primary', className = '', style, ...rest }: ActionButtonProps) {
  return (
    <button
      {...rest}
      type={type}
      className={['button', variant === 'secondary' ? 'button-secondary' : '', className]
        .filter(Boolean).join(' ')}
      style={{ minHeight: 44, minWidth: 44, maxWidth: '100%', boxSizing: 'border-box', ...style }}
    />
  );
}

export type FieldProps = Readonly<{
  label: string;
  help?: string;
  error?: string;
  inputProps?: Omit<InputHTMLAttributes<HTMLInputElement>, 'id' | 'aria-describedby' | 'aria-invalid'>;
}>;

/** Label, help and error are connected by generated stable IDs. */
export function Field({ label, help, error, inputProps }: FieldProps) {
  const id = useId();
  const helpId = id + '-help';
  const errorId = id + '-error';
  const describedBy = [help ? helpId : '', error ? errorId : ''].filter(Boolean).join(' ') || undefined;

  return (
    <div className="field" style={{ minWidth: 0, width: '100%', boxSizing: 'border-box' }}>
      <label htmlFor={id}>{label}</label>
      <input
        {...inputProps}
        id={id}
        aria-describedby={describedBy}
        aria-invalid={error ? true : undefined}
        style={{ boxSizing: 'border-box', minWidth: 0, width: '100%', minHeight: 44, ...inputProps?.style }}
      />
      {help && <small id={helpId}>{help}</small>}
      {error && <small id={errorId} role="alert">{error}</small>}
    </div>
  );
}

export type DialogProps = Readonly<{
  open: boolean;
  title: string;
  onClose: () => void;
  children: ReactNode;
}>;

/** Native modal traps focus, handles Escape and returns focus to the opener. */
export function Dialog({ open, title, onClose, children }: DialogProps) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);
  const titleId = useId();
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;

  useEffect(() => {
    const dialog = dialogRef.current;
    if (!dialog || !open) return;
    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    if (!dialog.open) dialog.showModal();
    closeRef.current?.focus();

    return () => {
      if (dialog.open) dialog.close();
      if (opener?.isConnected) opener.focus();
    };
  }, [open]);

  return (
    <dialog
      ref={dialogRef}
      role="dialog"
      aria-modal="true"
      aria-labelledby={titleId}
      onCancel={(event) => {
        event.preventDefault();
        onCloseRef.current();
      }}
      style={{
        boxSizing: 'border-box',
        width: 'min(32rem, calc(100vw - 2rem))',
        maxWidth: 'calc(100vw - 2rem)',
        maxHeight: 'calc(100dvh - 2rem)',
        overflow: 'auto',
        overflowWrap: 'anywhere',
      }}
    >
      <h2 id={titleId}>{title}</h2>
      <div>{children}</div>
      <Button ref={closeRef} variant="secondary" onClick={() => onCloseRef.current()}>
        Cerrar
      </Button>
    </dialog>
  );
}
