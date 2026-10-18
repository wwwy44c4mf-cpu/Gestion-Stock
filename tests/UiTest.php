<?php

namespace App\Tests;

use App\Ui\Navigation;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UiTest extends WebTestCase
{
    public function testLoginHasAccessibleShellWithoutPrivilegedLinks(): void
    {
        $client = static::createClient();
        $client->request('GET', '/connexion');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.skip-link[href="#contenu"]');
        self::assertSelectorExists('main#contenu');
        self::assertSelectorExists('nav[aria-label="Navigation principale"] a[aria-current="page"]');
        self::assertSelectorNotExists('nav a[href="/espace/administration"]');
        self::assertSelectorNotExists('select#magasin');
        self::assertSelectorExists('script[type="module"][src="/assets/app.js"]');
    }

    public function testNavigationUsesRoleAndStoreContext(): void
    {
        $navigation = new Navigation();
        $sections = fn (array $roles, ?string $store = null) => array_column($navigation->items($roles, $store), 'active');
        self::assertSame(['login'], $sections([]));
        self::assertNotContains('administration', $sections(['ROLE_GESTIONNAIRE'], 'store-1'));
        self::assertNotContains('mouvements', $sections(['ROLE_GESTIONNAIRE']));
        self::assertContains('mouvements', $sections(['ROLE_GESTIONNAIRE'], 'store-1'));
        self::assertNotContains('inventaires', $sections(['ROLE_UTILISATEUR'], 'store-1'));
        self::assertContains('consommations', $sections(['ROLE_UTILISATEUR'], 'store-1'));
        self::assertCount(9, $sections(['ROLE_ADMIN']));
    }

    public function testModulePlaceholdersAndUnknownSections(): void
    {
        $client = static::createClient();
        foreach (Navigation::SECTIONS as $section => $title) {
            $client->request('GET', '/espace/'.$section);
            self::assertResponseRedirects('/connexion');
        }
        $client->request('GET', '/espace/inconnu');
        self::assertResponseStatusCodeSame(404);
    }

    public function testSharedComponentsEscapeUserContent(): void
    {
        self::bootKernel();
        $twig = static::getContainer()->get('twig');
        $field = $twig->render('components/field.html.twig', ['id' => 'nom', 'label' => 'Nom', 'error' => '<script>test</script>']);
        self::assertStringContainsString('aria-invalid="true"', $field);
        self::assertStringContainsString('aria-describedby="nom-help"', $field);
        self::assertStringNotContainsString('<script>', $field);
        $table = $twig->render('components/table.html.twig', ['caption' => 'Articles', 'columns' => ['Nom'], 'rows' => [['<script>test</script>']]]);
        self::assertStringNotContainsString('<script>', $table);
        $pagination = $twig->render('components/pagination.html.twig', ['page' => 2, 'pages' => 3, 'route' => 'home']);
        self::assertStringContainsString('rel="prev"', $pagination);
        self::assertStringContainsString('rel="next"', $pagination);
    }
}
