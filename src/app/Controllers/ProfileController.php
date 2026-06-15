<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Csrf;
use App\Auth\GuardResult;
use App\Auth\PasswordHasher;
use App\Auth\PinVerifier;
use App\Auth\UserRepository;
use App\Core\Response;

/**
 * Profil self-service : definition / changement du PIN d'action sensible de
 * l'utilisateur connecte (prerequis au modele "identifiant equipier + PIN" des
 * actions sensibles, RG-T13). Accessible a tout utilisateur authentifie ; aucune
 * permission specifique (on n'agit que sur son propre compte = session userId).
 *
 * Non `final` : les tests sous-classent pour injecter des doubles.
 */
class ProfileController extends AdminController
{
    /**
     * @param array<string, string> $params
     */
    public function showPin(array $params = []): Response
    {
        $guard = $this->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        $userId = $guard->userId;
        if ($userId === null) {
            return Response::make('', 302, ['Location' => '/login']);
        }

        return $this->adminView('admin/profile/pin', [
            'title'     => 'Mon PIN - Wakdo Admin',
            'activeNav' => '',
            'pinIsSet'  => $this->userRepository()->pinIsSet($userId),
            'error'     => null,
        ], $guard);
    }

    /**
     * @param array<string, string> $params
     */
    public function updatePin(array $params = []): Response
    {
        $guard = $this->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        $userId = $guard->userId;
        if ($userId === null) {
            return Response::make('', 302, ['Location' => '/login']);
        }

        $form = $this->request->formBody();
        if (!Csrf::validate($this->sessionManager(), $form['_csrf'] ?? null)) {
            return Response::make('Requete invalide.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $pin = $form['pin'] ?? '';
        $confirm = $form['pin_confirm'] ?? '';
        $error = null;

        if (!$this->pinVerifier()->meetsLengthPolicy($pin)) {
            $error = 'Le PIN doit etre uniquement numerique et respecter la longueur requise.';
        } elseif ($pin !== $confirm) {
            $error = 'Les PIN ne correspondent pas.';
        }

        if ($error !== null) {
            return $this->renderPinForm($guard, $userId, $error, 422);
        }

        // Gate sur 1 ligne affectee : une cible inexistante (0 ligne) ne doit pas
        // produire un faux "PIN enregistre" (defense en profondeur).
        if ($this->userRepository()->setPinHash($userId, $this->passwordHasher()->hash($pin)) !== 1) {
            return $this->renderPinForm($guard, $userId, 'Echec de l enregistrement du PIN.', 500);
        }

        $this->setFlash('PIN enregistre.');

        return Response::make('', 302, ['Location' => '/admin/profile/pin']);
    }

    private function renderPinForm(GuardResult $guard, int $userId, ?string $error, int $status): Response
    {
        return $this->adminView('admin/profile/pin', [
            'title'     => 'Mon PIN - Wakdo Admin',
            'activeNav' => '',
            'pinIsSet'  => $this->userRepository()->pinIsSet($userId),
            'error'     => $error,
        ], $guard, $status);
    }

    protected function userRepository(): UserRepository
    {
        return new UserRepository($this->database);
    }

    protected function pinVerifier(): PinVerifier
    {
        return new PinVerifier($this->database, $this->config, $this->passwordHasher());
    }

    protected function passwordHasher(): PasswordHasher
    {
        return new PasswordHasher($this->config);
    }
}
