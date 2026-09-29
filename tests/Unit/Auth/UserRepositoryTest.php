<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use App\Auth\UserRepository;
use App\Tests\Support\FakeDatabase;

/**
 * Couvre UserRepository::setPasswordHash sur le double FakeDatabase, sans base
 * reelle. Constat d'audit corrige le 2026-09-29 : un changement de mot de passe
 * FAIT PAR UN ADMIN (UserController::update() / Admin\Api\UserApiController::
 * apiUpdate(), tous deux via ce repository) ne fermait aucune session deja
 * ouverte du compte -- seule PasswordResetService::confirmReset() (self-service)
 * incrementait `session_epoch` (RG-T02, migration 0020). Le reste de
 * UserRepository (create/update/deactivate/anonymise/...) n'est pas duplique
 * ici : il est deja couvert contre une vraie base par
 * tests/Integration/UserRepositoryDbTest.php.
 */
final class UserRepositoryTest extends TestCase
{
    public function testSetPasswordHashIncrementsSessionEpochInTheSameUpdate(): void
    {
        $db = new FakeDatabase();
        $repo = new UserRepository($db);

        $repo->setPasswordHash(42, '$argon2id$new-hash');

        self::assertCount(1, $db->writes, 'un SEUL UPDATE : le hash et session_epoch doivent partir ensemble (meme instruction, RG-T08)');
        $write = $db->writes[0];
        self::assertStringContainsString('UPDATE user SET', $write['sql']);
        self::assertStringContainsString('password_hash = :hash', $write['sql']);
        self::assertStringContainsString('session_epoch = session_epoch + 1', $write['sql']);
        self::assertSame('$argon2id$new-hash', $write['params']['hash'] ?? null);
        self::assertSame(42, $write['params']['id'] ?? null);
    }
}
