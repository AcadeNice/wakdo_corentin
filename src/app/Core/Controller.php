<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Controleur de base. Toute la hierarchie de controleurs en herite (demonstration
 * heritage Cr 4.c.1) : Controller -> AuthenticatedController -> AdminController ->
 * DashboardController (et les futurs CRUD), ou directement HomeController / HealthController.
 *
 * Recoit ses dependances par constructeur : la requete courante, la config et
 * l'acces BDD, injectes par le Router.
 */
abstract class Controller
{
    public function __construct(
        protected readonly Request $request,
        protected readonly Config $config,
        protected readonly Database $database,
    ) {
    }

    /**
     * @param array<string|int, mixed> $data
     */
    protected function json(array $data, int $status = 200): Response
    {
        return (new Response())->json($data, $status);
    }

    /**
     * Rend une vue PHP sous src/app/Views/<name>.php avec ses donnees extraites.
     *
     * Le rendu est bufferise puis injecte dans le layout via la variable
     * $content, ce qui permet aux vues de rester de simples fragments.
     *
     * @param array<string, mixed> $data
     */
    protected function view(string $name, array $data = [], int $status = 200): Response
    {
        $content = $this->render($name, $data);
        $html = $this->render($this->layoutName(), $data + ['content' => $content]);

        return (new Response())->html($html, $status);
    }

    /**
     * Gabarit enveloppant les vues. Defaut : le layout minimal. Les controleurs
     * back-office surchargent ce hook pour rendre dans le shell admin.
     */
    protected function layoutName(): string
    {
        return 'layout';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(string $name, array $data): string
    {
        $file = dirname(__DIR__) . '/Views/' . $name . '.php';

        if (!is_file($file)) {
            throw new RuntimeException(sprintf('View not found: %s', $name));
        }

        // Les cles deviennent des variables locales a la vue ; le buffering
        // capture le HTML produit sans l'emettre directement.
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;

        return (string) ob_get_clean();
    }
}
