import { OverviewGrid } from './OverviewGrid';

export type FunctionalSignalCounts = {
  tenant_created: number;
  login_success: number;
  login_failure: number;
  authorization_denied: number;
  role_modified: number;
};

type FunctionalSignalsPanelProps = Readonly<{
  signals: FunctionalSignalCounts;
}>;

export function FunctionalSignalsPanel({
  signals,
}: FunctionalSignalsPanelProps) {
  const hasActivity = Object.values(signals).some((count) => count > 0);

  return (
    <section
      className="platform-section"
      aria-labelledby="functional-signals-title"
    >
      <div className="section-heading compact">
        <div>
          <span className="eyebrow">Observabilidad</span>
          <h2 id="functional-signals-title">
            Actividad funcional (últimos 30 días)
          </h2>
        </div>
      </div>

      {!hasActivity ? (
        <div className="platform-empty">
          Aún no hay actividad funcional registrada en los últimos 30 días.
        </div>
      ) : (
        <OverviewGrid
          ariaLabel="Señales funcionales agregadas"
          variant="control"
          items={[
            { label: 'Empresas creadas', value: signals.tenant_created, detail: 'onboarding completado' },
            { label: 'Logins exitosos', value: signals.login_success, detail: 'sesiones iniciadas' },
            { label: 'Logins fallidos', value: signals.login_failure, detail: 'intentos rechazados' },
            { label: 'Autorización denegada', value: signals.authorization_denied, detail: 'accesos sin permiso' },
            { label: 'Roles modificados', value: signals.role_modified, detail: 'creación, edición o baja' },
          ]}
        />
      )}
    </section>
  );
}
