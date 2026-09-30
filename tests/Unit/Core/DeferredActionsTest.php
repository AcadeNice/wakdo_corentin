<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use App\Core\DeferredActions;

/**
 * D-5 (contre-audit 30/09) : contrat de la file d'actions post-reponse.
 * `push()`/`flush()` sont exerces indirectement par PasswordResetServiceTest
 * et PasswordResetControllerTest (le cas d'usage reel) ; ce fichier couvre le
 * contrat de la file elle-meme -- ordre, isolation entre actions, `reset()`.
 */
final class DeferredActionsTest extends TestCase
{
    protected function setUp(): void
    {
        DeferredActions::reset();
    }

    protected function tearDown(): void
    {
        DeferredActions::reset();
    }

    public function testFlushRunsPushedActionsInOrder(): void
    {
        $order = [];
        DeferredActions::push(function () use (&$order): void {
            $order[] = 'a';
        });
        DeferredActions::push(function () use (&$order): void {
            $order[] = 'b';
        });

        DeferredActions::flush();

        self::assertSame(['a', 'b'], $order);
    }

    public function testFlushEmptiesTheQueueSoARepeatedFlushIsANoop(): void
    {
        $calls = 0;
        DeferredActions::push(function () use (&$calls): void {
            $calls++;
        });

        DeferredActions::flush();
        DeferredActions::flush();

        self::assertSame(1, $calls);
    }

    /**
     * Une action en echec (ex. SMTP injoignable) ne doit JAMAIS empecher les
     * suivantes de s'executer -- la reponse HTTP est de toute facon deja
     * partie, il n'y a plus personne a qui remonter une exception.
     */
    public function testAFailingActionDoesNotBlockTheFollowingOnes(): void
    {
        $ran = [];
        DeferredActions::push(function () use (&$ran): void {
            $ran[] = 'first';

            throw new RuntimeException('SMTP injoignable');
        });
        DeferredActions::push(function () use (&$ran): void {
            $ran[] = 'second';
        });

        DeferredActions::flush();

        self::assertSame(['first', 'second'], $ran);
    }

    public function testResetDiscardsPendingActionsWithoutRunningThem(): void
    {
        $ran = false;
        DeferredActions::push(function () use (&$ran): void {
            $ran = true;
        });

        DeferredActions::reset();
        DeferredActions::flush();

        self::assertFalse($ran);
    }
}
