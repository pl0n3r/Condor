import { ReactNode } from 'react';

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

      <div className="workspace">{children}</div>
    </section>
  );
}
