<?php

declare(strict_types=1);

namespace App\Controllers;

use PDOException;
use App\Auth\Csrf;
use App\Auth\GuardResult;
use App\Catalogue\CategoryRepository;
use App\Core\Response;

/**
 * CRUD des categories du catalogue (P3). Premier CRUD admin, etablit le pattern :
 * chaque action est gardee par guard('category.manage') (RG-T03), les ecritures
 * valident le jeton CSRF (RG-T01) et les entrees cote serveur (RG-T18), puis
 * redirigent avec un message flash. Pas de suppression dure (FK RESTRICT) : on
 * bascule is_active (la permission couvre create/update/deactivate).
 *
 * Non `final` : les tests sous-classent pour injecter des doubles.
 */
class CategoryController extends AdminController
{
    private const PERMISSION = 'category.manage';

    /**
     * @param array<string, string> $params
     */
    public function index(array $params = []): Response
    {
        $guard = $this->guard(self::PERMISSION);
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->adminView('admin/categories/index', [
            'title'      => 'Categories - Wakdo Admin',
            'activeNav'  => 'categories',
            'categories' => $this->categoryRepository()->all(),
        ], $guard);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(array $params = []): Response
    {
        $guard = $this->guard(self::PERMISSION);
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->renderForm($guard, 0, [], []);
    }

    /**
     * @param array<string, string> $params
     */
    public function store(array $params = []): Response
    {
        $guard = $this->guard(self::PERMISSION);
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $repo = $this->categoryRepository();
        [$data, $errors] = $this->validate($form, $repo, 0);
        if ($errors !== []) {
            return $this->renderForm($guard, 0, $form, $errors, 422);
        }

        try {
            $repo->create($data);
        } catch (PDOException $exception) {
            return $this->onWriteConflict($exception, $guard, 0, $form);
        }

        $this->setFlash('Categorie creee.');

        return $this->redirect('/admin/categories');
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(array $params): Response
    {
        $guard = $this->guard(self::PERMISSION);
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $category = $this->categoryRepository()->find($id);
        if ($category === null) {
            return $this->notFound($guard);
        }

        return $this->renderForm($guard, $id, $category, []);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(array $params): Response
    {
        $guard = $this->guard(self::PERMISSION);
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        $repo = $this->categoryRepository();
        if ($repo->find($id) === null) {
            return $this->notFound($guard);
        }

        [$data, $errors] = $this->validate($form, $repo, $id);
        if ($errors !== []) {
            return $this->renderForm($guard, $id, $form, $errors, 422);
        }

        try {
            $repo->update($id, $data);
        } catch (PDOException $exception) {
            return $this->onWriteConflict($exception, $guard, $id, $form);
        }

        $this->setFlash('Categorie mise a jour.');

        return $this->redirect('/admin/categories');
    }

    /**
     * @param array<string, string> $params
     */
    public function toggle(array $params): Response
    {
        $guard = $this->guard(self::PERMISSION);
        if ($guard instanceof Response) {
            return $guard;
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return $this->invalidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        $repo = $this->categoryRepository();
        $category = $repo->find($id);
        if ($category === null) {
            return $this->notFound($guard);
        }

        $newActive = (int) ($category['is_active'] ?? 0) !== 1;
        $repo->setActive($id, $newActive);
        $this->setFlash($newActive ? 'Categorie affichee.' : 'Categorie masquee.');

        return $this->redirect('/admin/categories');
    }

    protected function categoryRepository(): CategoryRepository
    {
        return new CategoryRepository($this->database);
    }

    /**
     * Validation serveur (RG-T18) + unicite. Renvoie [donnees normalisees, erreurs].
     *
     * @param array<string, string> $form
     * @return array{0: array{name: string, slug: string, image_path: ?string, display_order: int, is_active: int}, 1: array<string, string>}
     */
    private function validate(array $form, CategoryRepository $repo, int $exceptId): array
    {
        $name = trim($form['name'] ?? '');
        $slug = trim($form['slug'] ?? '');
        $image = trim($form['image_path'] ?? '');
        $orderRaw = trim($form['display_order'] ?? '0');

        $errors = [];

        if ($name === '' || mb_strlen($name) > 60) {
            $errors['name'] = 'Le libelle est requis (60 caracteres max).';
        } elseif ($repo->nameExists($name, $exceptId)) {
            $errors['name'] = 'Ce libelle existe deja.';
        }

        if ($slug === '' || mb_strlen($slug) > 60 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            $errors['slug'] = 'Slug requis : minuscules, chiffres et tirets (60 max).';
        } elseif ($repo->slugExists($slug, $exceptId)) {
            $errors['slug'] = 'Ce slug existe deja.';
        }

        if ($image !== '' && mb_strlen($image) > 255) {
            $errors['image_path'] = 'Chemin image trop long (255 max).';
        }

        // Borne haute = SMALLINT UNSIGNED (0..65535) : refuse cote serveur (RG-T18)
        // plutot que de laisser un debordement remonter en 500 depuis la base.
        if (!ctype_digit($orderRaw) || (int) $orderRaw > 65535) {
            $errors['display_order'] = 'L ordre d affichage doit etre un entier entre 0 et 65535.';
        }

        $data = [
            'name'          => $name,
            'slug'          => $slug,
            'image_path'    => $image !== '' ? $image : null,
            'display_order' => (ctype_digit($orderRaw) && (int) $orderRaw <= 65535) ? (int) $orderRaw : 0,
            'is_active'     => 1,
        ];

        return [$data, $errors];
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function renderForm(GuardResult $guard, int $id, array $values, array $errors, int $status = 200): Response
    {
        return $this->adminView('admin/categories/form', [
            'title'      => ($id !== 0 ? 'Modifier' : 'Nouvelle') . ' categorie - Wakdo Admin',
            'activeNav'  => 'categories',
            'categoryId' => $id,
            'values'     => [
                'name'          => (string) ($values['name'] ?? ''),
                'slug'          => (string) ($values['slug'] ?? ''),
                'image_path'    => (string) ($values['image_path'] ?? ''),
                'display_order' => (string) ($values['display_order'] ?? '0'),
            ],
            'errors'     => $errors,
        ], $guard, $status);
    }

    /**
     * Traduit une violation de contrainte d'unicite (SQLSTATE 23000) en
     * re-affichage 409 du formulaire plutot qu'en 500. Conflit remonte par la
     * base (slug/name deja pris) = 409 Conflict, aligne sur le contrat d'API
     * (SLUG_EXISTS). La pre-verification nameExists/slugExists reste, elle, en
     * 422 (validation du formulaire) ; ce catch couvre la fenetre de concurrence
     * entre ce controle et l'ecriture. Tout autre code d'erreur est repropage.
     *
     * @param array<string, mixed> $form
     */
    private function onWriteConflict(PDOException $exception, GuardResult $guard, int $id, array $form): Response
    {
        // getCode() rend la chaine SQLSTATE pour une vraie PDOException ; le cast
        // couvre aussi un code entier (23000 = violation de contrainte d'integrite).
        if ((string) $exception->getCode() === '23000') {
            return $this->renderForm($guard, $id, $form, ['slug' => 'Ce libelle ou ce slug existe deja.'], 409);
        }

        throw $exception;
    }

    private function notFound(GuardResult $guard): Response
    {
        return $this->adminView('admin/not_found', ['title' => 'Introuvable', 'activeNav' => 'categories'], $guard, 404);
    }

    private function redirect(string $location): Response
    {
        return Response::make('', 302, ['Location' => $location]);
    }

    private function invalidCsrf(): Response
    {
        return Response::make('Requete invalide.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
