<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Functional;

use Jul6Art\AclBundle\Contract\FeatureCheckerInterface;
use Jul6Art\AclBundle\Event\FeatureAccessDeniedEvent;
use Jul6Art\AclBundle\Event\FeatureDenialReason;
use Jul6Art\AclBundle\Security\FeatureAccessListener;
use Jul6Art\AclBundle\Tests\Fixtures\AnnotatedController;
use Jul6Art\AclBundle\Tests\Fixtures\BareController;
use Jul6Art\AclBundle\Tests\Fixtures\StaticFeatureChecker;
use Jul6Art\AclBundle\Tests\Fixtures\TestTenant;
use Jul6Art\AclBundle\Tests\Fixtures\TestUser;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversNothing]
final class FeatureAccessTest extends AbstractFunctionalTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        StaticFeatureChecker::$enabled = [];
    }

    public function testAnEnabledFeatureLeavesTheControllerAlone(): void
    {
        StaticFeatureChecker::$enabled = ['cms'];
        $controller = [new AnnotatedController(), 'inherited'];

        $event = $this->handle($controller, $this->tenantUser());

        self::assertSame($controller, $event->getController(), 'Le contrôleur ne doit pas être remplacé quand la fonctionnalité est active.');
    }

    /**
     * ⚠️ Sans gestionnaire d'événement, le refus est un 403. Une porte de fonctionnalité qui
     * s'ouvrirait faute de gestionnaire transformerait chaque module payant en module gratuit.
     */
    public function testADisabledFeatureIsRefusedWithA403(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->handle([new AnnotatedController(), 'inherited'], $this->tenantUser());
    }

    /**
     * Plusieurs codes valent **OU** : une capacité partagée par plusieurs modules s'ouvre dès que
     * l'un d'eux est activé.
     */
    public function testSeveralCodesMeanOr(): void
    {
        StaticFeatureChecker::$enabled = ['sirh.manage'];
        $controller = [new AnnotatedController(), 'anyOfThree'];

        self::assertSame($controller, $this->handle($controller, $this->tenantUser())->getController());
    }

    public function testNoneOfSeveralCodesIsARefusal(): void
    {
        StaticFeatureChecker::$enabled = ['autre.chose'];

        $this->expectException(AccessDeniedException::class);

        $this->handle([new AnnotatedController(), 'anyOfThree'], $this->tenantUser());
    }

    /**
     * La déclaration sur la méthode gagne, sans fusion : `anyOfThree` ne demande pas `cms` bien que
     * sa classe le déclare.
     */
    public function testAMethodLevelDeclarationWinsOverTheClass(): void
    {
        StaticFeatureChecker::$enabled = ['cms'];

        $this->expectException(AccessDeniedException::class);

        $this->handle([new AnnotatedController(), 'anyOfThree'], $this->tenantUser());
    }

    public function testASuperAdminBypassesTheGate(): void
    {
        $controller = [new AnnotatedController(), 'inherited'];

        self::assertSame($controller, $this->handle($controller, new TestUser(superAdmin: true))->getController());
    }

    /**
     * Un acteur sans tenant est refusé pour une raison distincte : « votre compte n'est rattaché à
     * rien » n'est pas « ce module n'est pas activé », et un projet doit pouvoir les distinguer.
     */
    public function testAUserWithoutATenantIsRefusedForItsOwnReason(): void
    {
        $reasons = [];
        $collect = static function (FeatureAccessDeniedEvent $event) use (&$reasons): void {
            $reasons[] = $event->reason;
        };

        try {
            $this->handle([new AnnotatedController(), 'inherited'], new TestUser(), $collect);
        } catch (AccessDeniedException) {
        }

        self::assertSame([FeatureDenialReason::NoTenant], $reasons);
    }

    public function testADisabledFeatureIsRefusedForTheOtherReason(): void
    {
        $reasons = [];
        $collect = static function (FeatureAccessDeniedEvent $event) use (&$reasons): void {
            $reasons[] = $event->reason;
        };

        try {
            $this->handle([new AnnotatedController(), 'inherited'], $this->tenantUser(), $collect);
        } catch (AccessDeniedException) {
        }

        self::assertSame([FeatureDenialReason::Disabled], $reasons);
    }

    /**
     * Le projet décide ce que voit l'utilisateur. Une personne qui clique sur une entrée de menu
     * d'un module non activé doit atterrir sur une page qui le dit, pas sur une erreur — mais la
     * page, le message et sa traduction appartiennent au projet.
     */
    public function testAListenerCanReplaceTheRefusalWithARedirect(): void
    {
        $event = $this->handle(
            [new AnnotatedController(), 'inherited'],
            $this->tenantUser(),
            static fn (FeatureAccessDeniedEvent $e) => $e->setResponse(new RedirectResponse('/acces-refuse')),
        );

        $controller = $event->getController();
        self::assertIsCallable($controller);

        $response = $controller();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/acces-refuse', $response->getTargetUrl());
    }

    public function testAControllerWithoutTheAttributeIsUntouched(): void
    {
        $controller = [new BareController(), 'index'];

        self::assertSame($controller, $this->handle($controller, new TestUser())->getController());
    }

    /**
     * Une requête anonyme passe : une porte de fonctionnalité n'est pas une authentification, et
     * répondre 403 ici masquerait la redirection de connexion que le pare-feu va émettre.
     */
    public function testAnAnonymousRequestPassesThrough(): void
    {
        $controller = [new AnnotatedController(), 'inherited'];

        self::assertSame($controller, $this->handle($controller, null)->getController());
    }

    private function tenantUser(): TestUser
    {
        return new TestUser(tenant: new TestTenant(1, 'acme'));
    }

    /**
     * @param (callable(FeatureAccessDeniedEvent): void)|null $onDenial
     */
    private function handle(callable $controller, ?TestUser $user, ?callable $onDenial = null): ControllerEvent
    {
        $container = $this->boot(
            withSecurity: true,
            contracts: [FeatureCheckerInterface::class => StaticFeatureChecker::class],
        );

        if ($user instanceof TestUser) {
            $token = self::createStub(TokenInterface::class);
            $token->method('getUser')->willReturn($user);

            $storage = $container->get('security.token_storage');
            self::assertInstanceOf(TokenStorageInterface::class, $storage);
            $storage->setToken($token);
        }

        if (null !== $onDenial) {
            $dispatcher = $container->get('event_dispatcher');
            self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
            $dispatcher->addListener(FeatureAccessDeniedEvent::class, $onDenial);
        }

        $listener = $container->get(FeatureAccessListener::class);
        self::assertInstanceOf(FeatureAccessListener::class, $listener);

        $event = new ControllerEvent(
            self::createStub(HttpKernelInterface::class),
            $controller,
            Request::create('/pages'),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $listener->onKernelController($event);

        return $event;
    }
}
