<?php

declare(strict_types=1);

namespace App\Tests\Application\Security;

use App\Application\Security\ExternalInputPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ExternalInputPolicyTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7XUvUAAAAASUVORK5CYII=';

    public function testUploadSniffsContentAndCreatesIndependentOpaqueNames(): void
    {
        $png = base64_decode(self::PNG, true);
        self::assertIsString($png);
        $first = ExternalInputPolicy::inspectUpload($png, 'nombre-original.php', 'image/png');
        $second = ExternalInputPolicy::inspectUpload($png, 'nombre-original.php', 'image/png');
        self::assertSame('image/png', $first['mime']);
        self::assertSame(strlen($png), $first['bytes']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\.png\z/D', $first['stored_name']);
        self::assertNotSame($first['stored_name'], $second['stored_name']);
        self::assertStringNotContainsString('nombre-original', $first['stored_name']);

        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
        $document = ExternalInputPolicy::inspectUpload($pdf, 'factura.pdf', 'application/pdf');
        self::assertSame('application/pdf', $document['mime']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\.pdf\z/D', $document['stored_name']);
    }

    public function testUploadsRejectSpoofedMimeMalwareAndOversize(): void
    {
        $png = base64_decode(self::PNG, true);
        self::assertIsString($png);
        foreach ([
            [$png, 'foto.png', 'application/pdf'],  // claim != real type
            ['<?php system("id");', 'foto.png', 'image/png'],
            ["GIF89a\x01\x00\x01\x00", 'foto.gif', 'image/gif'],
            ['', 'empty.png', 'image/png'],
            [str_repeat('X', ExternalInputPolicy::MAX_UPLOAD_BYTES + 1), 'huge.pdf', 'application/pdf'],
            [$png, '../escapada.png', 'image/png'],
            [$png, 'folder/file.png', 'image/png'],
            [$png, "a\0.png", 'image/png'],
            [$png, '..', 'image/png'],
        ] as [$bytes, $filename, $claimedMime]) {
            self::assertDenied(static fn () => ExternalInputPolicy::inspectUpload($bytes, $filename, $claimedMime));
        }
    }

    public function testUrlAllowsOnlyValidatedPublicPinnedTargetsWithoutNetwork(): void
    {
        $lookups = [];
        $resolve = static function (string $host) use (&$lookups): array {
            $lookups[] = $host;
            return ['8.8.8.8', '1.1.1.1'];
        };
        $target = ExternalInputPolicy::inspectExternalUrl('https://media.example.com/photo?a=1', $resolve);
        self::assertSame(['8.8.8.8', '1.1.1.1'], $target['approved_ips']);
        self::assertSame('media.example.com', $target['host']);
        self::assertSame('https', $target['scheme']);
        self::assertSame(443, $target['port']);
        self::assertSame(['media.example.com'], $lookups);

        $literal = ExternalInputPolicy::inspectExternalUrl('http://8.8.8.8/ping', $resolve);
        self::assertSame(['8.8.8.8'], $literal['approved_ips']);
        self::assertCount(1, $lookups); // literals never invoke the resolver
        self::assertSame(['2001:4860:4860::8888'], ExternalInputPolicy::inspectExternalUrl('https://[2001:4860:4860::8888]/', $resolve)['approved_ips']);
    }

    public function testUrlRejectsSchemesCredentialHostsAndSensitiveAddresses(): void
    {
        $resolve = static fn (string $host): array => ['8.8.8.8'];
        foreach ([
            'file:///etc/passwd', 'ftp://media.example.com/x',
            'http://127.0.0.1/', 'https://10.0.0.2/',
            'http://169.254.169.254/latest/meta-data/',
            'http://100.64.0.1/', 'http://192.0.2.1/', 'http://198.18.0.1/',
            'http://[::1]/', 'http://[::ffff:127.0.0.1]/', 'http://[2001:db8::1]/', 'http://[2001:10::1]/',
            'http://localhost/', 'https://metadata.google.internal/',
            'https://file.local/', 'https://media.example.com:8443/',
            'https://user:password@media.example.com/',
            'https://media.example.com/#secret',
            'http://2130706433/', 'http://0x7f000001/',
            'https://media.example.com\@127.0.0.1/',
            'https://media.example.com/'."\n".'x',
        ] as $url) {
            self::assertDenied(static fn () => ExternalInputPolicy::inspectExternalUrl($url, $resolve));
        }
    }

    public function testDnsFailsClosedForMixedPrivateEmptyOrMalformedAnswers(): void
    {
        foreach ([
            ['8.8.8.8', '10.10.10.10'],
            ['169.254.169.254'],
            ['100.64.0.1'],
            ['198.51.100.9'], ['2001:db8::1'],
            ['not-an-ip'],
            [],
        ] as $answers) {
            self::assertDenied(static fn () => ExternalInputPolicy::inspectExternalUrl(
                'https://media.example.com/file',
                static fn (string $host): array => $answers,
            ));
        }
    }

    public function testRedirectsResolveEveryHopAndDenyRebindingAndDowngrade(): void
    {
        $seen = [];
        $lookup = static function (string $host) use (&$seen): array {
            $seen[] = $host;
            return ['8.8.8.8'];
        };
        $targets = ExternalInputPolicy::inspectRedirectChain(
            'https://media.example.com/a', ['https://cdn.example.com/b'], $lookup,
        );
        self::assertCount(2, $targets);
        self::assertSame(['media.example.com', 'cdn.example.com'], $seen);

        self::assertDenied(static fn () => ExternalInputPolicy::inspectRedirectChain(
            'https://media.example.com/a', ['http://cdn.example.com/b'], $lookup,
        ));
        self::assertDenied(static fn () => ExternalInputPolicy::inspectRedirectChain(
            'https://media.example.com/a', ['https://127.0.0.1/latest'], $lookup,
        ));
        self::assertDenied(static fn () => ExternalInputPolicy::inspectRedirectChain(
            'https://media.example.com/a',
            ['https://a.example.com/', 'https://b.example.com/', 'https://c.example.com/', 'https://d.example.com/'],
            $lookup,
        ));

        $turn = 0;
        self::assertDenied(static fn () => ExternalInputPolicy::inspectRedirectChain(
            'https://media.example.com/a',
            ['https://media.example.com/b'],
            static function (string $host) use (&$turn): array {
                ++$turn;
                return $turn === 1 ? ['8.8.8.8'] : ['127.0.0.1'];
            },
        ));
    }

    private static function assertDenied(callable $action): void
    {
        try {
            $action();
            self::fail('Una entrada hostil pasó la política fail-closed.');
        } catch (DomainException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }
}
