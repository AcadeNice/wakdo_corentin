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

    /**
     * D-5.a (revue adverse, contre-audit 30/09) : le verrou de session PHP
     * (fichiers, gestionnaire par defaut) reste tenu jusqu'a session_write_close()
     * -- avant ce correctif, `src/public/admin/index.php` appelait
     * `fastcgi_finish_request()` PUIS `DeferredActions::flush()` avec la
     * session encore ouverte : une 2e requete avec le MEME cookie restait
     * bloquee sur le verrou de fichier jusqu'a la fin de l'envoi SMTP differe,
     * rouvrant exactement le canal par le temps que D-5 devait fermer (mesure
     * du relecteur : 2,31 s contre 0,00 s apres session_write_close()).
     *
     * `finishRequest()` doit fermer la session AVANT `fastcgi_finish_request()`,
     * et vider la file (flush) apres les deux -- dans cet ordre exact.
     */
    public function testFinishRequestClosesSessionBeforeFinishingRequestBeforeFlushing(): void
    {
        $order = [];
        DeferredActions::push(function () use (&$order): void {
            $order[] = 'flush';
        });

        DeferredActions::finishRequest(
            static function () use (&$order): bool {
                $order[] = 'session-active-checked';

                return true;
            },
            static function () use (&$order): void {
                $order[] = 'session-closed';
            },
            static function () use (&$order): void {
                $order[] = 'fcgi-finished';
            },
        );

        self::assertSame(['session-active-checked', 'session-closed', 'fcgi-finished', 'flush'], $order);
    }

    /**
     * Aucune session active (ex. route publique, ou deja fermee) : ne PAS
     * appeler session_write_close() -- l'appeler sans session active emettrait
     * un warning PHP pour rien.
     */
    public function testFinishRequestDoesNotCloseAnInactiveSession(): void
    {
        $closed = false;

        DeferredActions::finishRequest(
            static fn (): bool => false,
            static function () use (&$closed): void {
                $closed = true;
            },
            static function (): void {
            },
        );

        self::assertFalse($closed);
    }

    /**
     * Sans seams (arguments par defaut) : les vraies fonctions PHP sont
     * appelees. Sous PHPUnit (CLI), aucune session n'est active et
     * fastcgi_finish_request() n'existe pas -- finishRequest() ne doit ni
     * avertir ni echouer, et doit tout de meme vider la file.
     */
    public function testFinishRequestWithRealFunctionsStillFlushesUnderCli(): void
    {
        $ran = false;
        DeferredActions::push(function () use (&$ran): void {
            $ran = true;
        });

        DeferredActions::finishRequest();

        self::assertTrue($ran);
    }
}
