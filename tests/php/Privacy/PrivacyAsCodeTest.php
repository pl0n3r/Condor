<?php

declare(strict_types=1);

namespace App\Tests\Privacy;

use JsonException;
use PHPUnit\Framework\TestCase;

final class PrivacyAsCodeTest extends TestCase
{
    private const PLACEHOLDER = '[COMPLETAR POR EL DUEÑO]';
    private const FACTORY_SHA = 'a14f38d5b4b1bb0db21101021375c76f1e36699c';

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

    public function testGeneratedPrivacyDocumentsAreCurrent(): void
    {
        $data = $this->data();
        $expected = $this->renderDocuments($data);
        foreach ($expected as $name => $content) {
            self::assertSame(
                $content,
                file_get_contents($this->root().'/docs/privacidad/'.$name),
                $name.' no coincide con la salida determinista esperada.',
            );
        }
    }

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

    public function testSensitiveTreatmentsRemainUnderReviewDuringConstruction(): void
    {
        $data = $this->data();
        self::assertSame('construccion', $data['phase']);
        foreach (['account_invitation', 'account_password', 'diagnostic_shares'] as $id) {
            $treatment = $this->treatment($data, $id);
            self::assertSame('authentication', $treatment['category']);
            self::assertSame('review_required', $treatment['consent']);
            self::assertSame('review_required', $treatment['basis']);
        }
    }

    public function testUnknownMailProviderIsNotInvented(): void
    {
        $data = $this->data();
        foreach ($data['treatments'] as $treatment) {
            self::assertSame([], $treatment['providers']);
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
        self::assertStringContainsString('proveedores: ninguno_declarado', $register);
    }

    public function testPrivacyArtifactsContainNoSyntheticSecretsOrRealPii(): void
    {
        $raw = file_get_contents($this->root().'/datos.yml');
        $raw .= "\n".file_get_contents($this->root().'/docs/privacidad/politica-tratamiento.md');
        $raw .= "\n".file_get_contents($this->root().'/docs/privacidad/registro-tratamientos.md');
        $raw .= "\n".file_get_contents($this->root().'/docs/privacidad/retencion.md');

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

    /** @param array<string, mixed> $data
     *  @return array<string, string>
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
            $sections[] = '## '.$treatment['id'];
            $sections[] = '';
            $sections[] = '- Categoría: '.chr(96).$treatment['category'].chr(96);
            $sections[] = '- Campos de software: '.implode(', ', $quotedFields);
            $sections[] = '- Finalidad: '.chr(96).$treatment['purpose'].chr(96);
            $sections[] = '- Base documentada: '.chr(96).$treatment['basis'].chr(96).' (revisión jurídica requerida)';
            $sections[] = '- Consentimiento: '.chr(96).$treatment['consent'].chr(96);
            $sections[] = '- Proveedores: '.$providers;
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
        $policy = implode("\n", [
            '# Política de tratamiento de datos personales',
            '',
            '> Estado: documento técnico generado; **no constituye aprobación jurídica**.',
            '',
            '## Responsable',
            '',
            '- Nombre o razón social: '.$controller['name'],
            '- Identificación: '.$controller['identifier'],
            '- Dirección: '.$controller['address'],
            '- Canal de derechos: '.$controller['rights_email'],
            '',
            '## Producto',
            '',
            chr(96).$data['project'].chr(96),
            '',
            '## Tratamientos documentados',
            '',
            implode("\n", $table),
            '',
            '## Derechos y revisión',
            '',
            'Las solicitudes de acceso, corrección, actualización, supresión o revocación '
                .'se canalizan mediante el canal de derechos indicado arriba. Las finalidades, '
                .'bases, consentimientos, proveedores y retenciones aquí documentadas requieren '
                .'la revisión jurídica aplicable antes de declararse aprobadas.',
            '',
        ]);

        $register = implode("\n", [
            '# Registro de tratamientos',
            '',
            '> Estado: inventario técnico generado; **revisión jurídica requerida**.',
            '',
            'Producto: '.chr(96).$data['project'].chr(96),
            '',
            rtrim(implode("\n", $sections)),
            '',
        ]);

        $retentionDocument = implode("\n", [
            '# Tabla de retención',
            '',
            '> Estado: calendario técnico documentado; **revisión jurídica requerida**.',
            '',
            'Producto: '.chr(96).$data['project'].chr(96),
            '',
            implode("\n", $retention),
            '',
        ]);

        return [
            'politica-tratamiento.md' => $policy,
            'registro-tratamientos.md' => $register,
            'retencion.md' => $retentionDocument,
        ];
    }
}
