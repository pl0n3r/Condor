import { useEffect, useRef, useState } from 'react';

type HealthPayload = Readonly<{
  status: 'ok';
  version: string;
  release_sha: string;
  schema_up_to_date: boolean;
}>;

type HealthReading =
  | { state: 'UNKNOWN' | 'STALE'; checkedAt: string | null }
  | { state: 'HEALTHY' | 'ALERT'; checkedAt: string; data: HealthPayload };

const HEALTH_TIMEOUT_MS = 5000;

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/** A malformed, partial, or unexpected response is never evidence of health. */
export function parseHealthPayload(value: unknown): HealthPayload | null {
  if (!isRecord(value)
      || value.status !== 'ok'
      || typeof value.version !== 'string'
      || !/^[a-zA-Z0-9][a-zA-Z0-9.+_-]{0,63}$/.test(value.version)
      || typeof value.release_sha !== 'string'
      || !/^[a-fA-F0-9]{7,40}$/.test(value.release_sha)
      || typeof value.schema_up_to_date !== 'boolean') {
    return null;
  }

  return {
    status: 'ok',
    version: value.version,
    release_sha: value.release_sha,
    schema_up_to_date: value.schema_up_to_date,
  };
}

export function healthStateOf(payload: HealthPayload | null): HealthReading['state'] {
  if (payload === null) return 'UNKNOWN';
  return payload.schema_up_to_date ? 'HEALTHY' : 'ALERT';
}

export function failedHealthState(hadVerifiedReading: boolean): 'UNKNOWN' | 'STALE' {
  return hadVerifiedReading ? 'STALE' : 'UNKNOWN';
}

/**
 * Read-only system health snapshot for the platform owner.
 * Never converts an older result, HTTP error, or timeout into a healthy state.
 */
export function SystemHealthPanel() {
  const [reading, setReading] = useState<HealthReading>({
    state: 'UNKNOWN',
    checkedAt: null,
  });
  const [revision, setRevision] = useState(0);
  const hadVerifiedReading = useRef(false);

  useEffect(() => {
    const controller = new AbortController();
    let active = true;
    const timeout = globalThis.setTimeout(() => controller.abort(), HEALTH_TIMEOUT_MS);

    // In-flight results are UNKNOWN, not the last known green state.
    setReading({ state: 'UNKNOWN', checkedAt: null });

    async function readHealth() {
      try {
        const response = await fetch('/health', {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/json' },
          signal: controller.signal,
        });
        if (!active || !response.ok) {
          if (!active) return;
          throw new Error('Health response unavailable');
        }

        const payload: unknown = await response.json();
        if (!active) return;

        const verified = parseHealthPayload(payload);
        if (verified === null) {
          throw new Error('Health response invalid');
        }

        hadVerifiedReading.current = true;
        setReading({
          state: verified.schema_up_to_date ? 'HEALTHY' : 'ALERT',
          checkedAt: new Date().toLocaleString('es-CO'),
          data: verified,
        });
      } catch {
        if (!active) return;
        setReading({
          state: failedHealthState(hadVerifiedReading.current),
          checkedAt: new Date().toLocaleString('es-CO'),
        });
      } finally {
        globalThis.clearTimeout(timeout);
      }
    }

    void readHealth();
    return () => {
      active = false;
      globalThis.clearTimeout(timeout);
      controller.abort();
    };
  }, [revision]);

  const stateCopy: Record<HealthReading['state'], string> = {
    HEALTHY: 'SANO',
    ALERT: 'ALERTA · esquema desactualizado',
    UNKNOWN: 'UNKNOWN · sin evidencia actual',
    STALE: 'STALE · última lectura no verificable',
  };

  return (
    <section className="platform-section" aria-labelledby="system-health-title">
      <div className="section-heading compact">
        <div>
          <span className="eyebrow">Observabilidad · solo lectura</span>
          <h2 id="system-health-title">Estado del sistema</h2>
        </div>
        <button
          type="button"
          className="button"
          onClick={() => setRevision((previous) => previous + 1)}
          aria-label="Refrescar estado del sistema"
        >
          Refrescar
        </button>
      </div>
      <p role="status" aria-live="polite">
        <strong>Estado: {stateCopy[reading.state]}</strong>
      </p>
      {reading.state === 'ALERT' && (
        <p role="alert">El esquema de la base de datos no está al día. Estado no sano.</p>
      )}
      {(reading.state === 'UNKNOWN' || reading.state === 'STALE') && (
        <p role="alert">La salud actual no pudo verificarse. No asumir disponibilidad.</p>
      )}
      <dl className="tenant-card">
        <div>
          <dt>Versión</dt>
          <dd>{'data' in reading ? reading.data.version : 'UNKNOWN'}</dd>
        </div>
        <div>
          <dt>Commit</dt>
          <dd>{'data' in reading ? reading.data.release_sha.slice(0, 8) : 'UNKNOWN'}</dd>
        </div>
        <div>
          <dt>Esquema</dt>
          <dd>
            {'data' in reading
              ? reading.data.schema_up_to_date ? 'Al día' : 'Desactualizado'
              : 'UNKNOWN'}
          </dd>
        </div>
        <div>
          <dt>Última lectura</dt>
          <dd>{reading.checkedAt ?? 'Sin lectura verificada'}</dd>
        </div>
      </dl>
    </section>
  );
}
