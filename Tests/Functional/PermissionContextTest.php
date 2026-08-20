<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Functional;

use Jul6Art\AclBundle\Security\PermissionContext;
use Jul6Art\AclBundle\Security\PermissionContextResolver;
use Jul6Art\AclBundle\Tests\Fixtures\AnnotatedController;
use Jul6Art\AclBundle\Tests\Fixtures\BareController;
use Jul6Art\AclBundle\Tests\Fixtures\TestTenant;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Request;

#[CoversNothing]
final class PermissionContextTest extends AbstractFunctionalTestCase
{
    public function testAClassLevelAttributeApplies(): void
    {
        $context = $this->resolve([new AnnotatedController(), 'inherited'], Request::create('/pages'));

        self::assertNotNull($context);
        self::assertSame('cms:page:read', $context->permission);
        self::assertSame('cms:page', $context->resource);
        self::assertSame('read', $context->action);
    }

    /**
     * La déclaration la plus proche de l'action gagne, et il n'y a pas de fusion : sinon une action
     * en lecture seule héritant d'une classe en écriture serait gardée par la mauvaise permission.
     */
    public function testAMethodLevelAttributeWinsOverTheClass(): void
    {
        $context = $this->resolve([new AnnotatedController(), 'overridden'], Request::create('/pages/1'));

        self::assertNotNull($context);
        self::assertSame('cms:page:update', $context->permission);
    }

    /**
     * Aucun attribut, aucune entrée de carte : pas de contexte. Ce n'est pas un refus — un
     * contrôleur qui ne déclare rien n'est simplement pas gardé par ce mécanisme.
     */
    public function testAControllerWithoutAnythingResolvesNoContext(): void
    {
        self::assertNull($this->resolve([new BareController(), 'index'], Request::create('/rien')));
    }

    /**
     * L'échappatoire pour un contrôleur qu'on ne peut pas annoter — celui d'un bundle tiers, ou une
     * action héritée en cours de migration.
     */
    public function testTheRouteMapCoversAControllerThatCannotBeAnnotated(): void
    {
        $request = Request::create('/connexion');
        $request->attributes->set('_route', 'app_security_login');

        $context = $this->resolve(
            [new BareController(), 'index'],
            $request,
            ['route_permissions' => ['app_security_login' => 'auth:login']],
        );

        self::assertNotNull($context);
        self::assertSame('auth:login', $context->permission);
    }

    /**
     * L'attribut passe devant la carte : la carte n'est qu'un repli.
     */
    public function testAnAttributeWinsOverTheRouteMap(): void
    {
        $request = Request::create('/pages');
        $request->attributes->set('_route', 'une_route');

        $context = $this->resolve(
            [new AnnotatedController(), 'inherited'],
            $request,
            ['route_permissions' => ['une_route' => 'autre:code']],
        );

        self::assertNotNull($context);
        self::assertSame('cms:page:read', $context->permission);
    }

    // ── Résolution du tenant : trois sources, pas également fiables ──────────

    /**
     * L'objet résolu gagne : quelque chose en amont l'a chargé et validé.
     */
    public function testAResolvedTenantObjectWinsOverEverythingElse(): void
    {
        $request = Request::create('/pages', server: ['HTTP_X_TENANT' => 'depuis-l-entete']);
        $request->attributes->set('_tenant', new TestTenant(1, 'depuis-l-objet'));
        $request->attributes->set('organization', 'depuis-la-route');

        $context = $this->resolve([new AnnotatedController(), 'inherited'], $request);

        self::assertNotNull($context);
        self::assertSame('depuis-l-objet', $context->domain);
    }

    public function testARouteParameterWinsOverTheHeader(): void
    {
        $request = Request::create('/pages', server: ['HTTP_X_TENANT' => 'depuis-l-entete']);
        $request->attributes->set('organization', 'depuis-la-route');

        $context = $this->resolve([new AnnotatedController(), 'inherited'], $request);

        self::assertNotNull($context);
        self::assertSame('depuis-la-route', $context->domain);
    }

    /**
     * L'en-tête est la simple prétention du client : dernier recours.
     */
    public function testTheHeaderIsTheLastResort(): void
    {
        $request = Request::create('/pages', server: ['HTTP_X_TENANT' => 'depuis-l-entete']);

        $context = $this->resolve([new AnnotatedController(), 'inherited'], $request);

        self::assertNotNull($context);
        self::assertSame('depuis-l-entete', $context->domain);
    }

    public function testTheHeaderNameIsConfigured(): void
    {
        $request = Request::create('/pages', server: ['HTTP_X_ORGANIZATION' => 'acme']);

        $context = $this->resolve([new AnnotatedController(), 'inherited'], $request, ['tenant_header' => 'X-ORGANIZATION']);

        self::assertNotNull($context);
        self::assertSame('acme', $context->domain);
    }

    public function testTheRouteParametersAreConfigured(): void
    {
        $request = Request::create('/pages');
        $request->attributes->set('workspace', 'acme');

        $context = $this->resolve([new AnnotatedController(), 'inherited'], $request, ['tenant_route_parameters' => ['workspace']]);

        self::assertNotNull($context);
        self::assertSame('acme', $context->domain);
    }

    /**
     * Un objet posé sur l'attribut mais qui n'est pas un tenant est ignoré — pas une erreur : un
     * projet peut nommer cet attribut autrement et y mettre autre chose.
     */
    public function testANonTenantObjectOnTheAttributeIsIgnored(): void
    {
        $request = Request::create('/pages', server: ['HTTP_X_TENANT' => 'depuis-l-entete']);
        $request->attributes->set('_tenant', new \stdClass());

        $context = $this->resolve([new AnnotatedController(), 'inherited'], $request);

        self::assertNotNull($context);
        self::assertSame('depuis-l-entete', $context->domain);
    }

    public function testAnApiPathIsRecognised(): void
    {
        $web = $this->resolve([new AnnotatedController(), 'inherited'], Request::create('/pages'));
        $api = $this->resolve([new AnnotatedController(), 'inherited'], Request::create('/api/pages'));

        self::assertNotNull($web);
        self::assertNotNull($api);
        self::assertFalse($web->isApi);
        self::assertTrue($api->isApi);
    }

    /**
     * Le contexte journalisé réduit l'acteur à un identifiant : une ligne d'audit ne doit pas
     * transporter une adresse e-mail, et un objet utilisateur complet dans un log est la façon dont
     * des données personnelles finissent dans un agrégateur.
     */
    public function testTheLoggableFormCarriesNoPersonalData(): void
    {
        $context = $this->resolve([new AnnotatedController(), 'inherited'], Request::create('/pages'));

        self::assertNotNull($context);
        self::assertSame(
            ['permission' => 'cms:page:read', 'resource' => 'cms:page', 'action' => 'read', 'domain' => null, 'actor_id' => null, 'is_api' => false],
            $context->toArray(),
        );
    }

    /**
     * @param array<string, mixed> $bundleConfig
     */
    private function resolve(callable $controller, Request $request, array $bundleConfig = []): ?PermissionContext
    {
        $resolver = $this->boot(bundleConfig: $bundleConfig, withSecurity: true)->get(PermissionContextResolver::class);
        self::assertInstanceOf(PermissionContextResolver::class, $resolver);

        return $resolver->resolve($controller, $request);
    }
}
