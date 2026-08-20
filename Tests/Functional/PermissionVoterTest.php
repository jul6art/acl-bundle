<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Functional;

use Jul6Art\AclBundle\Contract\PermissionSetProviderInterface;
use Jul6Art\AclBundle\Security\PermissionVoter;
use Jul6Art\AclBundle\Tests\Fixtures\InMemoryPermissionSetProvider;
use Jul6Art\AclBundle\Tests\Fixtures\TestTenant;
use Jul6Art\AclBundle\Tests\Fixtures\TestUser;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[CoversNothing]
final class PermissionVoterTest extends AbstractFunctionalTestCase
{
    /**
     * ⚠️ Le voter ne revendique que ce que le parseur sait lire. Sans cette abstention,
     * `ROLE_ADMIN` traverserait ce voter, y serait refusé, et une application entière basculerait
     * en 403 — d'où l'exception du parseur plutôt qu'un `null`.
     */
    public function testItAbstainsOnAnythingThatIsNotAPermissionCode(): void
    {
        $voter = $this->voter();
        $token = $this->token(new TestUser(superAdmin: true));

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, ['ROLE_ADMIN']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, ['EDIT']));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, null, ['cms:page:read']));
    }

    /**
     * Un porteur de jeton qui n'est pas un utilisateur ACL est refusé, non ignoré : le voter a
     * revendiqué l'attribut, il doit trancher.
     */
    public function testATokenWithoutAnAclUserIsRefused(): void
    {
        $token = self::createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($token, null, ['cms:page:read']));
    }

    public function testItGrantsWhatTheStorageGrants(): void
    {
        $voter = $this->voter(rolePermissions: ['cms:page:read']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token(new TestUser()), null, ['cms:page:read']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token(new TestUser()), null, ['cms:page:update']));
    }

    /**
     * ⚠️ Le contexte de repli. Sans lui, un `isGranted()` fait hors du cycle du listener — depuis
     * un autre listener, un `security` d'API Platform évalué tôt — verrait un contexte nul, sauterait
     * la règle « une requête d'API doit nommer son tenant », et accorderait à travers les tenants
     * exactement là où ça compte le plus.
     */
    public function testTheFallbackContextStillEnforcesTheApiTenantRule(): void
    {
        $request = Request::create('/api/pages');
        $voter = $this->voter(request: $request);
        $user = new TestUser(roles: ['ROLE_ORGANIZATION_ADMIN'], tenant: new TestTenant(1, 'acme'));

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->token($user), null, ['cms:page:read']),
            'Une requête d\'API sans tenant nommé doit être refusée, même sans listener.',
        );
    }

    public function testTheFallbackContextReadsTheTenantFromTheRequest(): void
    {
        $request = Request::create('/api/pages', server: ['HTTP_X_TENANT' => 'acme']);
        $voter = $this->voter(request: $request);
        $user = new TestUser(roles: ['ROLE_ORGANIZATION_ADMIN'], tenant: new TestTenant(1, 'acme'));

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($user), null, ['cms:page:read']));
    }

    /**
     * Hors requête — une commande — il n'y a pas de contexte du tout, et la décision se prend sur
     * les seules règles qui n'en dépendent pas. Un `isGranted()` en CLI ne doit pas exploser.
     */
    public function testWithoutARequestTheDecisionIsStillTaken(): void
    {
        $voter = $this->voter(rolePermissions: ['cms:page:read']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token(new TestUser()), null, ['cms:page:read']));
    }

    /**
     * @param list<string> $rolePermissions
     */
    private function voter(array $rolePermissions = ['cms:page:read'], ?Request $request = null): PermissionVoter
    {
        $container = $this->boot(
            withSecurity: true,
            contracts: [PermissionSetProviderInterface::class => InMemoryPermissionSetProvider::class],
        );

        $provider = $container->get(InMemoryPermissionSetProvider::class);
        self::assertInstanceOf(InMemoryPermissionSetProvider::class, $provider);
        // Le fournisseur est instancié par le conteneur : ses jeux sont posés ici, pas au
        // constructeur, pour que le câblage testé soit bien celui d'un projet réel.
        new \ReflectionProperty($provider, 'rolePermissions')->setValue($provider, $rolePermissions);

        if ($request instanceof Request) {
            $stack = $container->get('request_stack');
            self::assertInstanceOf(RequestStack::class, $stack);
            $stack->push($request);
        }

        $voter = $container->get(PermissionVoter::class);
        self::assertInstanceOf(PermissionVoter::class, $voter);

        return $voter;
    }

    private function token(TestUser $user): TokenInterface
    {
        $token = self::createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
