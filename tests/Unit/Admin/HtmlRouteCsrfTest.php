<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\Auth\Csrf;
use App\Core\Response;
use App\Tests\Support\FakeDatabase;
use App\Tests\Support\HtmlRouteHarness;

/**
 * Badge « jeton anti-rejeu » (csrf = form) de la page « Santé de l'API »,
 * back-office HTML. Pour CHAQUE route d'ecriture, deux appels qui ne different
 * QUE par le champ cache `_csrf` : meme session (bonne permission), meme
 * formulaire valide, meme code personnel valide si la route en exige un.
 *
 *  - sans `_csrf`, route annoncee `form` : 403, et AUCUNE requete d'ecriture
 *    enregistree par la base factice (ni INSERT, ni UPDATE, ni DELETE, ni
 *    REPLACE) ; route SANS jeton annonce : pas de 403 (sinon la page tairait
 *    une exigence reelle) ;
 *  - avec `_csrf` : pas 403 -- le refus sans jeton venait donc bien du jeton,
 *    pas d'une permission ou d'une garde de visibilite.
 */
final class HtmlRouteCsrfTest extends TestCase
{
    protected function setUp(): void
    {
        HtmlRouteHarness::applyEnv();
    }

    protected function tearDown(): void
    {
        HtmlRouteHarness::clearEnv();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: ?string, 4: ?string}>
     */
    public static function writeRoutes(): array
    {
        $cases = [];
        foreach (HtmlRouteHarness::boEntries() as $entry) {
            if (HtmlRouteHarness::isWrite($entry)) {
                $cases[HtmlRouteHarness::key($entry)] = [$entry[0], $entry[1], $entry[2], $entry[3], $entry[4]];
            }
        }

        return $cases;
    }

    #[DataProvider('writeRoutes')]
    public function testWriteWithoutTokenBehavesAsTheTableAnnounces(string $method, string $path, bool $anonymous, ?string $permission, ?string $csrf): void
    {
        [$response, $db] = $this->call($method, $path, $anonymous, $permission, false);

        if ($csrf === 'form') {
            self::assertSame(403, $response->status(), "$method $path sans champ _csrf devrait renvoyer 403 (obtenu {$response->status()}).");
            self::assertSame([], HtmlRouteHarness::writes($db), "$method $path sans champ _csrf ne devrait rien ecrire.");
        } else {
            self::assertNotSame(403, $response->status(), "$method $path n'annonce aucun jeton anti-rejeu mais refuse (403) un formulaire sans _csrf.");
        }
    }

    #[DataProvider('writeRoutes')]
    public function testSameWriteWithTheTokenIsNotRefused(string $method, string $path, bool $anonymous, ?string $permission, ?string $csrf): void
    {
        [$response] = $this->call($method, $path, $anonymous, $permission, true);

        self::assertNotSame(403, $response->status(), "$method $path avec le champ _csrf valide ne devrait pas renvoyer 403 : sinon le 403 sans jeton ne prouve rien.");
    }

    /**
     * @return array{0: Response, 1: FakeDatabase}
     */
    private function call(string $method, string $path, bool $anonymous, ?string $permission, bool $withToken): array
    {
        $session = $anonymous ? HtmlRouteHarness::anonymousSession() : HtmlRouteHarness::authenticatedSession();
        $db = HtmlRouteHarness::grantedDb($permission === null ? [] : [$permission]);
        HtmlRouteHarness::primeWorld($db, $method, $path);
        $scenario = HtmlRouteHarness::scenario($method . ' ' . $path) ?? [];

        $form = HtmlRouteHarness::form($scenario) + HtmlRouteHarness::pinFields();
        if ($withToken) {
            $form['_csrf'] = Csrf::token($session);
        }

        return [HtmlRouteHarness::exercise($method, $path, $session, $db, $form, $scenario), $db];
    }
}
