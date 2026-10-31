<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Shared\Version\AppVersion;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Página informativa sin formularios ni tratamiento de solicitudes reales.
 * La identidad y el canal provienen de datos.yml, nunca del request.
 */
final class PrivacyRightsController extends AbstractController
{
    public const PLACEHOLDER = '[COMPLETAR POR EL DUEÑO]';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/privacidad-y-mis-derechos', name: 'app_privacy_rights', methods: ['GET'])]
    public function __invoke(AppVersion $version): Response
    {
        return $this->render('privacidad/derechos.html.twig', [
            'app_version' => $version->human(),
            'responsable' => self::publicControllerInfo($this->projectDir.'/datos.yml'),
            'pendiente' => self::PLACEHOLDER,
        ]);
    }

    /**
     * Lee exclusivamente la configuración local; si está ausente o malformada
     * mantiene marcadores, en vez de publicar una identidad o correo inventado.
     *
     * @return array{name:string,identifier:string,address:string,rights_email:string}
     */
    public static function publicControllerInfo(string $file): array
    {
        $controller = [];
        if (is_file($file) && is_readable($file)) {
            $raw = @file_get_contents($file);
            if (is_string($raw)) {
                try {
                    $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($document) && is_array($document['controller'] ?? null)) {
                        $controller = $document['controller'];
                    }
                } catch (JsonException) {
                    // Un documento inválido jamás habilita un contacto ficticio.
                }
            }
        }

        return [
            'name' => self::safeField($controller['name'] ?? null),
            'identifier' => self::safeField($controller['identifier'] ?? null),
            'address' => self::safeField($controller['address'] ?? null),
            'rights_email' => self::safeField($controller['rights_email'] ?? null, true),
        ];
    }

    private static function safeField(mixed $value, bool $email = false): string
    {
        if (!is_string($value)) {
            return self::PLACEHOLDER;
        }

        $clean = trim($value);
        if ($clean === '' || strlen($clean) > 200
            || preg_match('/[\x00-\x1f\x7f]/', $clean) === 1) {
            return self::PLACEHOLDER;
        }
        if ($email && $clean !== self::PLACEHOLDER
            && filter_var($clean, FILTER_VALIDATE_EMAIL) === false) {
            return self::PLACEHOLDER;
        }

        return $clean;
    }
}
