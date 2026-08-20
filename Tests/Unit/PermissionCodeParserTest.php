<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Unit;

use Jul6Art\AclBundle\Security\PermissionCodeParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PermissionCodeParser::class)]
final class PermissionCodeParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function validCodes(): iterable
    {
        yield 'deux segments' => ['user:read', 'user', 'read'];
        yield 'trois segments' => ['cms:page:read', 'cms:page', 'read'];
        yield 'quatre segments' => ['erp:invoice:line:update', 'erp:invoice:line', 'update'];
        yield 'point dans un segment' => ['crm.manage:deal:read', 'crm.manage:deal', 'read'];
        yield 'tiret et souligné' => ['time_entry:soft-delete', 'time_entry', 'soft-delete'];
        yield 'espaces autour' => ['  user:read  ', 'user', 'read'];
    }

    #[DataProvider('validCodes')]
    public function testItSplitsOnTheLastSegment(string $code, string $resource, string $action): void
    {
        self::assertSame(['resource' => $resource, 'action' => $action], new PermissionCodeParser()->parse($code));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'vide' => [''];
        yield 'espaces seulement' => ['   '];
        yield 'un seul segment' => ['read'];
        yield 'segment vide au début' => [':read'];
        yield 'segment vide au milieu' => ['cms::read'];
        yield 'segment vide à la fin' => ['user:'];
        yield 'majuscules' => ['User:Read'];
        yield 'espace intérieur' => ['user profile:read'];
        yield 'barre oblique' => ['user/profile:read'];
        yield 'étoile' => ['user:*'];
    }

    /**
     * Le refus est une exception et non un `null`, parce que le voter s'en sert pour décider qu'il
     * ne *supporte pas* l'attribut : c'est ainsi que `ROLE_ADMIN` et `cms:page:read` traversent le
     * même `isGranted()` sans que ce voter revendique ce qui n'est pas un code de permission.
     */
    #[DataProvider('invalidCodes')]
    public function testItRefusesAnythingItCannotSplit(string $code): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PermissionCodeParser()->parse($code);
    }

    /**
     * ⚠️ `user:*` est refusé, et c'est un choix : accepter une étoile ici laisserait croire à un
     * joker que rien dans le moteur n'implémente. Un « tout sur cette ressource » se déclare comme
     * un rôle, pas comme un code.
     */
    public function testAWildcardIsNotAPermissionCode(): void
    {
        $this->expectExceptionMessageIsOrContains('invalid characters');

        new PermissionCodeParser()->parse('cms:page:*');
    }
}
