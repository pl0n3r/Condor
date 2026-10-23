import { useEffect, useId, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';

export type AdminNavItem = Readonly<{
  href: string;
  label: string;
  current?: boolean;
}>;

type AdminShellProps = Readonly<{
  version: string;
  logoutToken: string;
  ariaLabel: string;
  navLabel: string;
  navItems: readonly AdminNavItem[];
  variant?: 'tenant' | 'platform';
  children: ReactNode;
}>;

type SearchItem = Readonly<{
  id: string;
  title: string;
  subtitle: string;
  href: string;
}>;

type SearchGroup = 'products' | 'customers' | 'orders';
type SearchResults = Record<SearchGroup, SearchItem[]>;
type SearchStatus = 'idle' | 'loading' | 'ready' | 'empty' | 'error';

const SEARCH_GROUPS: ReadonlyArray<{ key: SearchGroup; label: string }> = [
  { key: 'products', label: 'Productos' },
  { key: 'customers', label: 'Clientes' },
  { key: 'orders', label: 'Pedidos' },
];

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/** Trust neither the payload shape nor navigation URLs returned by the API. */
function decodeSearchResults(payload: unknown): SearchResults {
  if (!isRecord(payload) || !isRecord(payload.results)) {
    throw new Error('Formato de búsqueda inválido');
  }

  const groups: SearchResults = { products: [], customers: [], orders: [] };
  for (const { key } of SEARCH_GROUPS) {
    const entries = payload.results[key];
    if (!Array.isArray(entries)) {
      throw new Error('Grupo de búsqueda inválido');
    }
    for (const entry of entries.slice(0, 5) as unknown[]) {
      if (
        !isRecord(entry) ||
        typeof entry.id !== 'string' ||
        typeof entry.title !== 'string' ||
        typeof entry.subtitle !== 'string' ||
        typeof entry.href !== 'string' ||
        !/^\/admin(?:\/|#|$)/.test(entry.href) ||
        entry.href.startsWith('//')
      ) {
        throw new Error('Resultado de búsqueda inválido');
      }
      groups[key].push({
        id: entry.id,
        title: entry.title,
        subtitle: entry.subtitle,
        href: entry.href,
      });
    }
  }
  return groups;
}

function AdminGlobalSearch() {
  const fieldId = useId();
  const resultsId = useId();
  const inputRef = useRef<HTMLInputElement>(null);
  const resultsRef = useRef<HTMLDivElement>(null);
  const [query, setQuery] = useState('');
  const [visible, setVisible] = useState(false);
  const [status, setStatus] = useState<SearchStatus>('idle');
  const [error, setError] = useState('');
  const [results, setResults] = useState<SearchResults | null>(null);

  useEffect(() => {
    function onShortcut(event: globalThis.KeyboardEvent) {
      if (event.key !== '/' || event.altKey || event.ctrlKey || event.metaKey) {
        return;
      }
      const target = event.target;
      if (
        target instanceof HTMLInputElement ||
        target instanceof HTMLTextAreaElement ||
        target instanceof HTMLSelectElement ||
        (target instanceof HTMLElement && target.isContentEditable)
      ) {
        return;
      }
      event.preventDefault();
      inputRef.current?.focus();
      setVisible(true);
    }
    document.addEventListener('keydown', onShortcut);
    return () => document.removeEventListener('keydown', onShortcut);
  }, []);

  useEffect(() => {
    const term = query.trim();
    setResults(null);
    setError('');
    if (term.length < 2) {
      setStatus('idle');
      return;
    }
    if (term.length > 64 || !/^[\p{L}\p{N} .-]+$/u.test(term)) {
      setStatus('error');
      setError('Usa entre 2 y 64 caracteres: letras, números, espacios, puntos o guiones.');
      return;
    }

    const controller = new AbortController();
    setStatus('loading');
    const timer = window.setTimeout(() => {
      void (async () => {
        try {
          const response = await fetch('/api/admin/search?q=' + encodeURIComponent(term), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
          });
          if (!response.ok) {
            throw new Error('Solicitud de búsqueda fallida');
          }
          const found = decodeSearchResults(await response.json());
          if (controller.signal.aborted) {
            return;
          }
          setResults(found);
          setStatus(SEARCH_GROUPS.some(({ key }) => found[key].length > 0) ? 'ready' : 'empty');
        } catch {
          if (!controller.signal.aborted) {
            setResults(null);
            setError('No se pudo completar la búsqueda. Intenta nuevamente.');
            setStatus('error');
          }
        }
      })();
    }, 350);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [query]);

  function onKeyboard(event: KeyboardEvent<HTMLDivElement>) {
    if (event.key === 'Escape') {
      setVisible(false);
      inputRef.current?.focus();
      return;
    }
    const links = Array.from(
      resultsRef.current?.querySelectorAll<HTMLAnchorElement>('a[data-search-result]') ?? [],
    );
    if (links.length === 0) {
      return;
    }
    if (event.key === 'Enter' && document.activeElement === inputRef.current) {
      event.preventDefault();
      links[0].click();
    }
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
      return;
    }
    event.preventDefault();
    const index = links.findIndex((link) => link === document.activeElement);
    const next = event.key === 'ArrowDown'
      ? (index + 1) % links.length
      : (index < 0 ? links.length - 1 : (index - 1 + links.length) % links.length);
    links[next].focus();
  }

  return (
    <div className="workspace-heading" role="search" onKeyDown={onKeyboard}>
      <div className="field">
        <label htmlFor={fieldId}>Búsqueda global <small>(/)</small></label>
        <input
          ref={inputRef}
          id={fieldId}
          type="search"
          aria-label="Búsqueda global"
          aria-keyshortcuts="/"
          aria-controls={resultsId}
          autoComplete="off"
          maxLength={64}
          placeholder="Productos, clientes o pedidos"
          value={query}
          onChange={(event) => {
            setQuery(event.target.value);
            setVisible(true);
          }}
          onFocus={() => setVisible(true)}
        />
      </div>
      {visible && query.trim().length >= 2 && (
        <div id={resultsId} ref={resultsRef} role="region" aria-label="Resultados de búsqueda" aria-live="polite">
          {status === 'loading' && <p role="status">Buscando…</p>}
          {status === 'empty' && <p role="status">Sin resultados.</p>}
          {status === 'error' && <p role="alert">{error}</p>}
          {status === 'ready' && results && SEARCH_GROUPS.map(({ key, label }) => (
            results[key].length > 0 && (
              <section key={key} aria-label={label}>
                <h3>{label}</h3>
                <ul>
                  {results[key].map((item) => (
                    <li key={key + ':' + item.id}>
                      <a data-search-result href={item.href}>
                        <strong>{item.title}</strong> <small>{item.subtitle}</small>
                      </a>
                    </li>
                  ))}
                </ul>
              </section>
            )
          ))}
        </div>
      )}
    </div>
  );
}

export function AdminShell({
  version,
  logoutToken,
  ariaLabel,
  navLabel,
  navItems,
  variant = 'tenant',
  children,
}: AdminShellProps) {
  return (
    <section
      className={'admin-shell admin-shell-' + variant}
      aria-label={ariaLabel}
    >
      <aside className="sidebar">
        <div className="brand-block">
          <strong>Condor App</strong>
          <span>V {version}</span>
        </div>

        <nav aria-label={navLabel}>
          {navItems.map((item) => (
            <a
              href={item.href}
              aria-current={item.current ? 'page' : undefined}
              key={item.href}
            >
              {item.label}
            </a>
          ))}
        </nav>

        <form method="post" action="/admin/logout">
          <input
            type="hidden"
            name="_csrf_token"
            value={logoutToken}
          />
          <button className="button button-secondary" type="submit">
            Cerrar sesión
          </button>
        </form>
      </aside>

      <div className="workspace">
        {variant === 'tenant' && <AdminGlobalSearch />}
        {children}
      </div>
    </section>
  );
}
