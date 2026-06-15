<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;

/**
 * Page d'accueil du back-office. GET /.
 *
 * Volontairement minimale en P2 : prouve que le rendu de vue MVC traverse
 * controleur -> vue -> layout sans dependre de la BDD.
 */
final class HomeController extends Controller
{
    /**
     * @param array<string, string> $params
     */
    public function index(array $params = []): Response
    {
        return $this->view('home', [
            'title'   => 'Wakdo back-office',
            'appEnv'  => $this->config->appEnv(),
        ]);
    }
}
