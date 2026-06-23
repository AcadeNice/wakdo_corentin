<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use App\Core\Config;

/**
 * La config lit getenv (pas de .env en conteneur). Les tests pilotent
 * l'environnement via putenv et nettoient apres eux pour ne pas polluer les
 * autres cas.
 */
final class ConfigTest extends TestCase
{
    private Config $config;

    /** @var list<string> */
    private array $touchedKeys = [];

    protected function setUp(): void
    {
        $this->config = new Config();
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedKeys as $key) {
            putenv($key);
        }

        $this->touchedKeys = [];
    }

    private function setEnv(string $key, string $value): void
    {
        $this->touchedKeys[] = $key;
        putenv($key . '=' . $value);
    }

    public function testGetReturnsValueWhenPresent(): void
    {
        $this->setEnv('WAKDO_TEST_NAME', 'borne');

        self::assertSame('borne', $this->config->get('WAKDO_TEST_NAME'));
    }

    public function testGetReturnsDefaultWhenAbsent(): void
    {
        self::assertSame('fallback', $this->config->get('WAKDO_TEST_MISSING', 'fallback'));
        self::assertNull($this->config->get('WAKDO_TEST_MISSING'));
    }

    public function testGetTreatsEmptyStringAsAbsent(): void
    {
        // Une variable d'env vide n'apporte pas d'information : Config la traite
        // comme absente et renvoie le defaut (contrat documente dans Config::get).
        $this->setEnv('WAKDO_TEST_EMPTY', '');

        self::assertSame('def', $this->config->get('WAKDO_TEST_EMPTY', 'def'));
    }

    public function testIntCastsValue(): void
    {
        $this->setEnv('WAKDO_TEST_PORT', '3307');

        self::assertSame(3307, $this->config->int('WAKDO_TEST_PORT'));
    }

    public function testIntReturnsDefaultWhenAbsent(): void
    {
        self::assertSame(3306, $this->config->int('WAKDO_TEST_PORT_MISSING', 3306));
    }

    /**
     * @return list<array{0: string, 1: bool}>
     */
    public static function truthyValuesProvider(): array
    {
        return [
            ['1', true],
            ['true', true],
            ['TRUE', true],
            ['yes', true],
            ['on', true],
            ['0', false],
            ['false', false],
            ['no', false],
            ['off', false],
            ['anything-else', false],
        ];
    }

    #[DataProvider('truthyValuesProvider')]
    public function testBoolInterpretsCommonConventions(string $raw, bool $expected): void
    {
        $this->setEnv('WAKDO_TEST_FLAG', $raw);

        self::assertSame($expected, $this->config->bool('WAKDO_TEST_FLAG'));
    }

    public function testBoolReturnsDefaultWhenAbsent(): void
    {
        self::assertTrue($this->config->bool('WAKDO_TEST_FLAG_MISSING', true));
        self::assertFalse($this->config->bool('WAKDO_TEST_FLAG_MISSING', false));
    }

    public function testRequiredReturnsValueWhenPresent(): void
    {
        $this->setEnv('WAKDO_TEST_DB', 'wakdo');

        self::assertSame('wakdo', $this->config->required('WAKDO_TEST_DB'));
    }

    public function testRequiredThrowsWhenMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required configuration: WAKDO_TEST_REQUIRED_MISSING');

        $this->config->required('WAKDO_TEST_REQUIRED_MISSING');
    }
}
