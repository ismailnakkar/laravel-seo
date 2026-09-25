<?php

declare(strict_types=1);

namespace Seo\Tests;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Seo\Page;
use Seo\Testing\SeoAssertions;

/** The package's crawler assertions over localized pages, with the language middleware and entry redirect running. */
final class LanguagesCrawlTest extends LanguagesTestCase
{
    use SeoAssertions;

    protected function defineWebRoutes($router): void
    {
        $router->localized(function (Router $router): void {
            $router->get('/', $this->renderFixturePage(...))->name('home');
            $router->get('faq', $this->renderFixturePage(...));
        });
    }

    public function test_crawlers_see_every_copy_while_a_browser_is_still_redirected(): void
    {
        $this->withSite();
        $this->fixturePage = static fn (Request $request): Page => new Page(title: str_ends_with($request->getPathInfo(), 'faq') ? 'FAQ' : 'Home');
        $this->withSitemap(['/', '/faq']);

        $this->withHeaders(['Accept-Language' => 'fr'])->get('/')->assertStatus(302)->assertHeader('Location', 'http://localhost/fr');

        $this->assertHreflangReciprocal('/');
        $this->assertHreflangReciprocal('/fr');
        $this->assertSitemapComplete();
    }
}
