<?php

declare(strict_types=1);

namespace App\Tests\Privacy;

use JsonException;
use PHPUnit\Framework\TestCase;

final class PrivacyAsCodeTest extends TestCase
{
    private const PLACEHOLDER = '[COMPLETAR POR EL DUEÑO]';
    private const FACTORY_SHA = '68eef82e3b21939143a4cbea23b7df2615534e77';

    /** Verifica que el mapa represente únicamente tratamientos observados. */
    public function testDataMapMatchesObservedCondorTreatments(): void
    {
        $data = $this->data();
        self::assertSame('pl0n3r/Condor', $data['project']);
        self::assertSame('construccion', $data['phase']);
        self::assertSame(
            [
                'account_identity',
                'account_invitation',
                'account_password',
                'audit_events',
                'condor_password_reset',
                'customer_contact',
                'diagnostic_shares',
                'error_incidents',
                'notification_delivery',
                'notification_preferences',
            ],
            $this->treatmentIds($data),
        );
        self::assertSame([self::PLACEHOLDER], array_values(array_unique($data['controller'])));
        self::assertContains('password_hash', $this->treatment($data, 'account_password')['fields']);
        self::assertContains('phone', $this->treatment($data, 'customer_contact')['fields']);
        self::assertContains('payload', $this->treatment($data, 'notification_delivery')['fields']);
        self::assertContains('context', $this->treatment($data, 'audit_events')['fields']);
        self::assertContains('trace', $this->treatment($data, 'error_incidents')['fields']);
    }

    /** Verifica derivación byte-a-byte desde datos.yml según el contrato Factory fijado. */
    public function testGeneratedPrivacyDocumentsAreCurrent(): void
    {
        $generated = $this->renderDocuments($this->data());
        $expectedNames = [
            'aviso-privacidad.md',
            'canal-derechos.md',
            'politica-tratamiento.md',
            'registro-tratamientos.md',
            'retencion.md',
            'terminos-condiciones.md',
        ];
        $directory = $this->root().'/docs/privacidad';
        $actual = array_map('basename', glob($directory.'/*.md') ?: []);
        sort($actual);
        self::assertSame($expectedNames, $actual);

        foreach ($expectedNames as $name) {
            $actualContent = file_get_contents($directory.'/'.$name);
            self::assertSame(
                $generated[$name],
                $actualContent,
                $name.' derivó de datos.yml respecto al contrato Factory '.self::FACTORY_SHA.'.',
            );
        }
    }

    /** Verifica pins de Factory y permisos mínimos de los callers. */
    public function testPrivacyWorkflowsUseFactoryWithMinimumPermissions(): void
    {
        $privacy = file_get_contents($this->root().'/.github/workflows/privacidad.yml');
        $audit = file_get_contents($this->root().'/.github/workflows/auditoria-privacidad.yml');

        self::assertStringContainsString(
            'uses: pl0n3r/factory/.github/workflows/privacidad.yml@'.self::FACTORY_SHA,
            $privacy,
        );
        self::assertStringContainsString('kit_ref: '.self::FACTORY_SHA, $privacy);
        self::assertStringContainsString("permissions:\n  contents: read", $privacy);
        self::assertStringNotContainsString('issues: write', $privacy);
        self::assertStringNotContainsString('secrets:', $privacy);

        self::assertStringContainsString(
            'uses: pl0n3r/factory/.github/workflows/auditoria-privacidad.yml@'.self::FACTORY_SHA,
            $audit,
        );
        self::assertStringContainsString('kit_ref: '.self::FACTORY_SHA, $audit);
        self::assertStringContainsString("permissions:\n  contents: read\n  issues: write", $audit);
        self::assertStringNotContainsString('contents: write', $audit);
        self::assertStringNotContainsString('secrets:', $audit);
    }

    /** Mantiene tratamientos sensibles bajo revisión mientras el producto está en construcción. */
    public function testSensitiveTreatmentsRemainUnderReviewDuringConstruction(): void
    {
        $data = $this->data();
        self::assertSame('construccion', $data['phase']);
        foreach (['account_invitation', 'account_password', 'condor_password_reset', 'diagnostic_shares'] as $id) {
            $treatment = $this->treatment($data, $id);
            self::assertSame('authentication', $treatment['category']);
            self::assertSame('review_required', $treatment['consent']);
            self::assertSame('review_required', $treatment['basis']);
        }
    }

    /** Evita inventar un proveedor de correo no demostrado por el repositorio. */
    public function testUnknownMailProviderIsNotInvented(): void
    {
        $data = $this->data();
        foreach ($data['treatments'] as $treatment) {
            $expectedProviders = $treatment['id'] === 'error_incidents'
                ? ['sentry']
                : [];
            self::assertSame(
                $expectedProviders,
                $treatment['providers'],
                'Solo error_incidents puede declarar Sentry como proveedor.',
            );
        }

        $register = strtolower(
            file_get_contents($this->root().'/docs/privacidad/registro-tratamientos.md'),
        );
        foreach (['sendgrid', 'mailgun', 'postmark', 'aws_ses', 'smtp_provider'] as $invented) {
            self::assertStringNotContainsString(
                chr(96).$invented.chr(96),
                $register,
            );
        }
        self::assertStringContainsString('proveedores: `sentry`', $register);
        self::assertStringContainsString('proveedores: ninguno_declarado', $register);
    }

    /** Impide secretos sintéticos o PII real en los artefactos de privacidad. */
    public function testPrivacyArtifactsContainNoSyntheticSecretsOrRealPii(): void
    {
        $raw = file_get_contents($this->root().'/datos.yml');
        foreach ([
            'aviso-privacidad.md',
            'canal-derechos.md',
            'politica-tratamiento.md',
            'registro-tratamientos.md',
            'retencion.md',
            'terminos-condiciones.md',
        ] as $name) {
            $raw .= "\n".file_get_contents($this->root().'/docs/privacidad/'.$name);
        }

        self::assertDoesNotMatchRegularExpression('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $raw);
        self::assertDoesNotMatchRegularExpression('/\b\d{8,}\b/', $raw);
        self::assertStringNotContainsString('ghp_', $raw);
        self::assertStringNotContainsString('sk-', $raw);
        self::assertStringContainsString(self::PLACEHOLDER, $raw);
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        try {
            return json_decode(
                file_get_contents($this->root().'/datos.yml'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            self::fail('datos.yml no es JSON/YAML canónico válido: '.$exception->getMessage());
        }
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @param array<string, mixed> $data
     *  @return list<string>
     */
    private function treatmentIds(array $data): array
    {
        $ids = array_map(
            static fn (array $treatment): string => $treatment['id'],
            $data['treatments'],
        );
        sort($ids);

        return $ids;
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    private function treatment(array $data, string $id): array
    {
        foreach ($data['treatments'] as $treatment) {
            if ($treatment['id'] === $id) {
                return $treatment;
            }
        }

        self::fail('Tratamiento no encontrado: '.$id);
    }


    /**
     * Reproduce el contrato determinista de documentos del Factory fijado.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function renderDocuments(array $data): array
    {
        $treatments = $data['treatments'];
        usort(
            $treatments,
            static fn (array $left, array $right): int => $left['id'] <=> $right['id'],
        );

        $table = [
            '| Tratamiento | Categoría | Campos | Finalidad | '
                .'Base documentada | Consentimiento | Proveedores | Retención |',
            '| --- | --- | --- | --- | --- | --- | --- | --- |',
        ];
        $sections = [];
        $retention = [
            '| Tratamiento | Categoría | Retención declarada | Máximo común |',
            '| --- | --- | --- | --- |',
        ];

        foreach ($treatments as $treatment) {
            $providers = $treatment['providers'] === []
                ? 'ninguno_declarado'
                : implode(', ', $treatment['providers']);
            $table[] = '| '.implode(' | ', [
                $treatment['id'],
                $treatment['category'],
                implode(', ', $treatment['fields']),
                $treatment['purpose'],
                $treatment['basis'],
                $treatment['consent'],
                $providers,
                $treatment['retention'],
            ]).' |';

            $quotedFields = array_map(
                static fn (string $field): string => chr(96).$field.chr(96),
                $treatment['fields'],
            );
            $quotedProviders = $treatment['providers'] === []
                ? 'ninguno_declarado'
                : implode(
                    ', ',
                    array_map(
                        static fn (string $provider): string => chr(96).$provider.chr(96),
                        $treatment['providers'],
                    ),
                );
            $sections[] = '## '.$treatment['id'];
            $sections[] = '';
            $sections[] = '- Categoría: '.chr(96).$treatment['category'].chr(96);
            $sections[] = '- Campos de software: '.implode(', ', $quotedFields);
            $sections[] = '- Finalidad: '.chr(96).$treatment['purpose'].chr(96);
            $sections[] = '- Base documentada: '
                .chr(96).$treatment['basis'].chr(96)
                .' (revisión jurídica requerida)';
            $sections[] = '- Consentimiento: '.chr(96).$treatment['consent'].chr(96);
            $sections[] = '- Proveedores: '.$quotedProviders;
            $sections[] = '- Retención: '.chr(96).$treatment['retention'].chr(96);
            $sections[] = '';

            $retention[] = '| '.implode(' | ', [
                $treatment['id'],
                $treatment['category'],
                $treatment['retention'],
                'review_required',
            ]).' |';
        }

        $controller = $data['controller'];
        $responsible = [
            '- Nombre o razón social: '.$controller['name'],
            '- Identificación: '.$controller['identifier'],
            '- Dirección: '.$controller['address'],
            '- Canal de derechos: '.$controller['rights_email'],
        ];
        $tableText = implode("\n", $table);
        $projectCode = chr(96).$data['project'].chr(96);

        $policy = implode("\n", [
            '# Política de tratamiento de datos personales',
            '',
            '> Estado: documento técnico generado; **no constituye aprobación jurídica**.',
            '',
            '## Responsable',
            '',
            ...$responsible,
            '',
            '## Producto',
            '',
            $projectCode,
            '',
            '## Tratamientos documentados',
            '',
            $tableText,
            '',
            '## Derechos y revisión',
            '',
            'Las solicitudes de acceso, corrección, actualización, supresión o revocación '
                .'se canalizan mediante el canal de derechos indicado arriba. Las finalidades, '
                .'bases, consentimientos, proveedores y retenciones aquí documentadas requieren '
                .'la revisión jurídica aplicable antes de declararse aprobadas.',
            '',
        ]);

        $notice = implode("\n", [
            '# Aviso de privacidad y autorización',
            '',
            '> Estado: borrador técnico generado; **revisión jurídica requerida**.',
            '',
            '## Responsable',
            '',
            ...$responsible,
            '',
            '## Producto',
            '',
            $projectCode,
            '',
            '## Tratamientos documentados',
            '',
            $tableText,
            '',
            '## Autorización técnica pendiente',
            '',
            'La integración que recoja autorización debe presentar una **casilla no premarcada** '
                .'y un enlace visible a la política de tratamiento antes de registrar la decisión '
                .'de la persona. Para tratamientos que requieran consentimiento explícito, la '
                .'implementación debe conservar evidencia verificable de esa decisión.',
            '',
            'Este borrador **no acredita que exista consentimiento**, no sustituye la revisión '
                .'jurídica y no autoriza por sí mismo ningún tratamiento.',
            '',
        ]);

        $terms = implode("\n", [
            '# Términos y condiciones',
            '',
            '> Estado: borrador técnico generado; **revisión jurídica requerida antes de publicación**.',
            '',
            'Producto: '.$projectCode,
            '',
            '## Responsable',
            '',
            ...$responsible,
            '',
            '## Condiciones pendientes de definición',
            '',
            '- Condiciones comerciales: '.self::PLACEHOLDER.' — revisión jurídica requerida.',
            '- Niveles de servicio (SLA), si aplican: '
                .self::PLACEHOLDER.' — revisión jurídica requerida.',
            '- Garantías y limitaciones aplicables: '
                .self::PLACEHOLDER.' — revisión jurídica requerida.',
            '- Jurisdicción y mecanismo de solución de controversias: '
                .self::PLACEHOLDER.' — revisión jurídica requerida.',
            '',
            'Este documento no inventa ni presume condiciones del producto. Los hechos comerciales '
                .'y jurídicos anteriores deben completarse y aprobarse antes de su uso público.',
            '',
        ]);

        $register = implode("\n", [
            '# Registro de tratamientos',
            '',
            '> Estado: inventario técnico generado; **revisión jurídica requerida**.',
            '',
            'Producto: '.$projectCode,
            '',
            rtrim(implode("\n", $sections)),
            '',
        ]);

        $rights = implode("\n", [
            '# Canal para ejercer derechos sobre datos personales',
            '',
            '> Estado: borrador técnico generado; **revisión jurídica requerida**.',
            '',
            'Producto: '.$projectCode,
            '',
            '## Responsable y canal',
            '',
            ...$responsible,
            '',
            '## Procedimiento pendiente',
            '',
            '- Requisitos de la solicitud: '.self::PLACEHOLDER.' — revisión jurídica requerida.',
            '- Flujo interno de atención: '.self::PLACEHOLDER.' — revisión jurídica requerida.',
            '- Plazos aplicables: '.self::PLACEHOLDER
                .' — deben ser definidos con revisión jurídica; '
                .'este kit no inventa un plazo legal.',
            '',
            'El canal debe permitir solicitudes de acceso, corrección, actualización, supresión o '
                .'revocación según corresponda. Este documento describe una frontera técnica y no '
                .'constituye aprobación jurídica.',
            '',
        ]);

        $retentionDocument = implode("\n", [
            '# Tabla de retención',
            '',
            '> Estado: calendario técnico documentado; **revisión jurídica requerida**.',
            '',
            'Producto: '.$projectCode,
            '',
            implode("\n", $retention),
            '',
        ]);

        return [
            'aviso-privacidad.md' => $notice,
            'canal-derechos.md' => $rights,
            'politica-tratamiento.md' => $policy,
            'registro-tratamientos.md' => $register,
            'retencion.md' => $retentionDocument,
            'terminos-condiciones.md' => $terms,
        ];
    }

}
