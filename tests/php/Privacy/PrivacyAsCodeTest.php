<?php

declare(strict_types=1);

namespace App\Tests\Privacy;

use JsonException;
use PHPUnit\Framework\TestCase;

final class PrivacyAsCodeTest extends TestCase
{
    private const PLACEHOLDER = '[COMPLETAR POR EL DUEÑO]';
    private const FACTORY_SHA = '4b2be9fcf827278631caa3e3e68603b6e2a680d7';

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
        $expected = [
            'aviso-privacidad.md' => '42d8a576fa0d9914140f9947c422c5edfb1a1c55f8c8248a06f798fc0addbe80',
            'canal-derechos.md' => 'e0a95e42aca6f5f8a1f2b6a69ab746a84be35e799222905d492fdb4cb56adecb',
            'politica-tratamiento.md' => 'e989f5a916ae830493c70c09076cd100108d5d67e3afaf4dc6fd745c27f35b5a',
            'registro-tratamientos.md' => 'd43d1f07d162f91f1293bd5f523916151237d39c46378c8ee4c929a9d321cc55',
            'retencion.md' => '01305b357ff161aeac753faab456fce6280a23eaf3a2db1af84dcdc39d2b46b9',
            'terminos-condiciones.md' => 'b69785399e1d29423c380ec7f56bd2816c33f971752ca2d56269b8919e8c27bd',
        ];
        $directory = $this->root().'/docs/privacidad';
        $actual = array_map('basename', glob($directory.'/*.md') ?: []);
        sort($actual);
        self::assertSame(array_keys($expected), $actual);

        foreach ($expected as $name => $sha256) {
            self::assertSame(
                $sha256,
                hash_file('sha256', $directory.'/'.$name),
                $name.' no coincide byte a byte con Factory '.self::FACTORY_SHA.'.',
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

}
