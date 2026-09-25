<?php

declare(strict_types=1);

namespace Seo\Tests;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\ComponentSlot;
use InvalidArgumentException;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use Seo\Page;
use Seo\ParsedPage;
use Seo\Robots;
use Seo\Seo;

final class HeadTest extends TestCase
{
    public function test_the_title_is_suffixed_with_the_separator_and_site_name_by_default(): void
    {
        $this->withSite();

        $this->visit('/faq', new Page(title: 'FAQ'))
            ->assertSee('<title>FAQ · UpFiles</title>', false)
            ->assertSee('<meta property="og:title" content="FAQ · UpFiles">', false);
    }

    public function test_suffix_site_name_false_leaves_the_title_alone(): void
    {
        $this->withSite(['title_separator' => ' | ']);

        $this->visit('/', new Page(title: 'UpFiles - Make Money', suffixSiteName: false))
            ->assertSee('<title>UpFiles - Make Money</title>', false);

        $this->visit('/faq', new Page(title: 'FAQ'))->assertSee('<title>FAQ | UpFiles</title>', false);
    }

    public function test_a_page_that_never_calls_page_is_indexable_by_default(): void
    {
        $this->withSite();
        $this->withLocales();

        $this->visit('/fr/reset-password?utm_source=x')
            ->assertOk()
            ->assertSee('<title>UpFiles</title>', false)
            ->assertSee('<meta name="robots" content="max-image-preview:large">', false)
            ->assertSee('<link rel="canonical" href="http://localhost/fr/reset-password">', false)
            ->assertSee('<link rel="alternate" hreflang="x-default" href="http://localhost/reset-password">', false)
            ->assertSee('<meta property="og:url" content="http://localhost/fr/reset-password">', false)
            ->assertDontSee('name="description"', false);
    }

    public function test_without_index_by_default_only_a_described_page_is_indexable(): void
    {
        $this->withSite(['index_by_default' => false]);

        $this->visit('/reset-password')
            ->assertOk()
            ->assertSee('<title>UpFiles</title>', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertDontSee('name="description"', false)
            ->assertDontSee('og:description', false)
            ->assertDontSee('rel="canonical"', false)
            ->assertDontSee('og:url', false);

        $this->visit('/faq', new Page(description: 'Questions.'))
            ->assertSee('<meta name="robots" content="max-image-preview:large">', false)
            ->assertSee('<link rel="canonical" href="http://localhost/faq">', false);
    }

    public function test_no_page_with_a_title_renders_it_suffixed(): void
    {
        $this->withSite();

        $this->visit('/reset-password', title: 'Verify email')
            ->assertSee('<title>Verify email · UpFiles</title>', false)
            ->assertSee('<meta property="og:title" content="Verify email · UpFiles">', false);
    }

    public function test_the_seo_directive_sets_the_page_from_a_view(): void
    {
        $this->withSite();
        Route::get('terms', static fn () => view('directive'));

        $this->get('/terms')
            ->assertViewIs('directive')
            ->assertSee('<title>Terms · UpFiles</title>', false)
            ->assertSee('<meta name="description" content="The rules.">', false)
            ->assertSee('<link rel="canonical" href="http://localhost/terms">', false);
    }

    public function test_calls_merge_per_field_the_later_wins_and_the_page_belongs_to_its_request(): void
    {
        $first = Request::create('/faq');
        $second = Request::create('/faq');

        $this->app->instance('request', $first);
        $this->seo()->page(title: 'Draft', description: 'Questions.', jsonLd: [['@type' => 'Thing']]);
        $this->seo()->page(title: 'FAQ', robots: Robots::noindex, jsonLd: [['@type' => 'FAQPage']]);
        $this->app->instance('request', $second);

        $this->assertEquals(
            new Page(title: 'FAQ', description: 'Questions.', robots: Robots::noindex, jsonLd: [['@type' => 'Thing'], ['@type' => 'FAQPage']]),
            $this->seo()->pageFor($first),
        );
        $this->assertNull($this->seo()->pageFor($second));
    }

    public function test_the_views_seo_merges_over_the_controllers_page(): void
    {
        $this->withSite();
        Route::get('terms', static function (Seo $seo) {
            $seo->page(title: 'Draft', canonical: '/legal/terms');

            return view('directive');
        });

        $this->get('/terms')
            ->assertSee('<title>Terms · UpFiles</title>', false)
            ->assertSee('<meta name="description" content="The rules.">', false)
            ->assertSee('<link rel="canonical" href="http://localhost/legal/terms">', false);
    }

    public function test_a_blank_argument_never_erases_an_earlier_value(): void
    {
        $this->withSite();
        Route::get('merge', function () {
            $this->seo()->page(title: 'T', description: 'From the controller');

            return Blade::render("@seo(description: '')@extends('layout')");
        });

        $this->get('/merge')->assertSee('<meta name="description" content="From the controller">', false);
    }

    public function test_a_blank_image_or_canonical_is_unset(): void
    {
        $this->withSite();
        Route::get('x', static function (Seo $seo): string {
            $seo->page(title: 'X', image: '', canonical: ' ');

            return Blade::render('<x-seo::head />');
        });

        $this->get('/x')
            ->assertSee('<meta property="og:image" content="http://localhost/img/og-image.png">', false)
            ->assertSee('<link rel="canonical" href="http://localhost/x">', false);
    }

    public function test_a_component_layouts_stray_attributes_never_reach_the_head(): void
    {
        // Blade hands an enclosing component's undeclared attributes to every nested component.
        $this->withSite();
        $attributes = new ComponentAttributeBag(['view' => 'compact', 'request' => 'x', 'seo' => 'y']);
        Route::get('x', static fn () => Blade::render('<x-seo::head />', ['attributes' => $attributes]));

        $this->get('/x')->assertOk()->assertSee('<title>UpFiles</title>', false);
    }

    public function test_a_slot_on_the_head_itself_never_replaces_its_rendered_title(): void
    {
        // Blade merges a component's slots into its view data, where a title slot would shadow the computed title.
        $this->withSite();
        Route::get('x', static function (Seo $seo): string {
            $seo->page(title: 'FAQ');

            return Blade::render('<x-seo::head><x-slot:title>Slot</x-slot:title></x-seo::head>');
        });

        $this->get('/x')->assertSee('<title>FAQ · UpFiles</title>', false);
    }

    public function test_a_prop_slot_or_section_is_html_decoded_once_however_it_is_written(): void
    {
        $this->withSite();

        foreach ([
            '<x-seo::head title="{{ $t }}" description="{{ $t }}" />',
            '<x-seo::head :title="$t" :description="$t" />',
            // A layout component forwarding its own slot.
            '<x-seo::head :title="$slot" :description="$slot" />',
            "@section('title', \$t)@section('description', \$t)<x-seo::head />",
        ] as $template) {
            Route::get('x', static fn (): string => Blade::render($template, ['t' => 'Q&A', 'slot' => new ComponentSlot(e('Q&A'))]));
            $html = (string)$this->get('/x')->getContent();

            $this->assertStringContainsString('<title>Q&amp;A · UpFiles</title>', $html, $template);
            $this->assertStringContainsString('<meta name="description" content="Q&amp;A">', $html, $template);
        }
    }

    public function test_the_page_beats_the_props_which_beat_the_sections(): void
    {
        $this->withSite();
        $template = "@section('title', 'Section')@section('description', 'From a section & co')<x-seo::head :title=\"\$title\" :description=\"\$description\" />";
        Route::get('x', static function (Request $request, Seo $seo) use ($template) {
            if ($request->has('page')) {
                $seo->page(title: 'Page', description: 'From the page');
            }

            return Blade::render($template, ['title' => $request->query('prop'), 'description' => $request->query('prop')]);
        });

        $this->get('/x?page=1&prop=Prop')
            ->assertSee('<title>Page · UpFiles</title>', false)
            ->assertSee('<meta name="description" content="From the page">', false);
        $this->get('/x?prop=Prop')
            ->assertSee('<title>Prop · UpFiles</title>', false)
            ->assertSee('<meta name="description" content="Prop">', false);
        $this->get('/x')
            ->assertSee('<title>Section · UpFiles</title>', false)
            ->assertSee('<meta name="description" content="From a section &amp; co">', false);
    }

    public function test_a_blank_title_renders_the_site_name(): void
    {
        $this->withSite();

        $this->visit('/reset-password', title: "  \n ")->assertSee('<title>UpFiles</title>', false);
        $this->visit('/reset-password', new Page(title: ' '))->assertSee('<title>UpFiles</title>', false);
    }

    public function test_a_section_title_is_escaped_once(): void
    {
        // yieldContent() returns HTML: @section('title', $t) has already escaped it.
        $this->withSite(['name' => 'cuty.io', 'title_separator' => ' | ']);

        $this->visit('/reset-password', title: "Conditions d'utilisation & co")
            ->assertSee('<title>Conditions d&#039;utilisation &amp; co | cuty.io</title>', false);
    }

    public function test_a_blank_description_is_omitted(): void
    {
        $this->withSite();

        $this->visit('/faq', new Page(title: 'FAQ', description: '  '))
            ->assertDontSee('name="description"', false)
            ->assertDontSee('og:description', false);

        $this->visit('/faq', new Page(title: 'FAQ', description: 'Questions.'))
            ->assertSee('<meta name="description" content="Questions.">', false)
            ->assertSee('<meta property="og:description" content="Questions.">', false);
    }

    public function test_the_default_robots_is_max_image_preview_large(): void
    {
        $this->withSite();

        $this->visit('/faq', new Page(title: 'FAQ'))
            ->assertSee('<meta name="robots" content="max-image-preview:large">', false);
    }

    public function test_a_noindex_page_has_no_canonical_hreflang_or_og_url(): void
    {
        $this->withSite();
        $this->withLocales();

        $this->visit('/fr/faq', new Page(title: 'FAQ', robots: Robots::noindex))
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertDontSee('rel="canonical"', false)
            ->assertDontSee('hreflang', false)
            ->assertDontSee('og:url', false);
    }

    public function test_a_noindex_canonical_override_feeds_og_url_only(): void
    {
        $this->withSite();

        $this->visit('http://go.test/faq', new Page(title: 'A title', suffixSiteName: false, canonical: 'https://exe.io/abc', robots: Robots::none))
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee('<meta property="og:url" content="https://exe.io/abc">', false)
            ->assertDontSee('rel="canonical"', false);

        $this->visit('http://go.test/faq', new Page(canonical: '/abc', robots: Robots::none))
            ->assertSee('<meta property="og:url" content="http://localhost/abc">', false);
    }

    public function test_an_indexable_page_on_a_noindex_host_renders_noindex_nofollow_and_no_canonical(): void
    {
        $this->withSite();
        $this->withLocales();

        $this->visit('http://dl.test/fr/faq', new Page(title: 'FAQ'))
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertDontSee('rel="canonical"', false)
            ->assertDontSee('hreflang', false)
            ->assertDontSee('og:url', false);
    }

    public function test_there_is_exactly_one_canonical_and_og_url_equals_it(): void
    {
        $this->withSite();

        $html = (string)$this->visit('/faq?utm_source=x', new Page(title: 'FAQ'))->getContent();

        $this->assertSame(1, substr_count($html, 'rel="canonical"'));
        $this->assertStringContainsString('<link rel="canonical" href="http://localhost/faq">', $html);
        $this->assertSame(1, substr_count($html, 'og:url'));
        $this->assertStringContainsString('<meta property="og:url" content="http://localhost/faq">', $html);
    }

    public function test_a_crawl_host_still_canonicalises_to_the_site_origin(): void
    {
        $this->withSite();

        $this->visit('http://go.test/faq', new Page(title: 'FAQ'))
            ->assertSee('<link rel="canonical" href="http://localhost/faq">', false)
            ->assertSee('<meta property="og:url" content="http://localhost/faq">', false);
    }

    public function test_og_image_is_absolute_on_the_site_origin_even_on_a_crawl_host(): void
    {
        $this->withSite();

        $this->visit('http://go.test/faq', new Page(title: 'FAQ'))
            ->assertSee('<meta property="og:image" content="http://localhost/img/og-image.png">', false)
            ->assertSee('<meta property="og:image:alt" content="UpFiles">', false);

        $this->withSite(['image' => 'https://cdn.test/share.png']);

        $this->visit('/faq', new Page(title: 'FAQ'))
            ->assertSee('<meta property="og:image" content="https://cdn.test/share.png">', false)
            ->assertSee('<meta property="og:image:alt" content="UpFiles">', false);
    }

    public function test_a_page_image_replaces_the_site_image_and_takes_the_page_title_as_alt(): void
    {
        $this->withSite();

        $this->visit('http://go.test/faq', new Page(title: 'Tom & Jerry', image: '/img/faq.png'))
            ->assertSee('<meta property="og:image" content="http://localhost/img/faq.png">', false)
            ->assertSee('<meta property="og:image:alt" content="Tom &amp; Jerry">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false);

        $this->withSite(['image' => null]);

        $this->visit('/reset-password', new Page(image: 'https://cdn.test/reset.png'), title: 'Reset')
            ->assertSee('<meta property="og:image" content="https://cdn.test/reset.png">', false)
            ->assertSee('<meta property="og:image:alt" content="Reset">', false);
        $this->visit('/reset-password', new Page(image: '/reset.png'))
            ->assertSee('<meta property="og:image:alt" content="UpFiles">', false);
    }

    public function test_a_protocol_relative_og_image_and_logo_keep_their_host(): void
    {
        $this->withSite(['image' => '//cdn.test/og.png', 'logo' => '//cdn.test/logo.png']);

        $response = $this->visit('/', new Page(title: 'UpFiles', suffixSiteName: false))
            ->assertSee('<meta property="og:image" content="http://cdn.test/og.png">', false);

        $this->assertSame('http://cdn.test/logo.png', ParsedPage::parse((string)$response->getContent())->jsonLd[0]['logo']);
    }

    public function test_malformed_utf_8_in_a_value_an_admin_edits_never_500s_the_page(): void
    {
        $this->withSite(['name' => "Up\xC3Files"]);

        $home = $this->visit('/', new Page(title: 'UpFiles', suffixSiteName: false))->assertOk();
        $this->assertSame("Up\u{FFFD}Files", ParsedPage::parse((string)$home->getContent())->jsonLd[1]['name']);

        // A byte-limited truncation cuts a character in half.
        $faq = $this->visit('/faq', new Page(title: 'FAQ', jsonLd: [['name' => substr('Résumé', 0, 2)]]))->assertOk();
        $this->assertSame("R\u{FFFD}", ParsedPage::parse((string)$faq->getContent())->jsonLd[0]['name']);
    }

    public function test_no_image_renders_no_og_image_tags_and_no_twitter_card(): void
    {
        $this->withSite(['image' => null]);

        $this->visit('/faq', new Page(title: 'FAQ'))
            ->assertOk()
            ->assertSee('<meta property="og:url" content="http://localhost/faq">', false)
            ->assertDontSee('og:image', false)
            ->assertDontSee('twitter:', false);
    }

    public function test_a_missing_or_svg_image_still_renders(): void
    {
        $this->withSite(['image' => '/nowhere/share.svg']);

        $this->visit('/faq', new Page(title: 'FAQ'))
            ->assertOk()
            ->assertSee('<meta property="og:image" content="http://localhost/nowhere/share.svg">', false);
    }

    public function test_only_twitter_card_is_emitted(): void
    {
        $this->withSite();

        $this->visit('/faq', new Page(title: 'FAQ', description: 'Questions.'))
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->assertDontSee('twitter:title', false)
            ->assertDontSee('twitter:description', false)
            ->assertDontSee('twitter:image', false);
    }

    public function test_the_verification_tag_and_the_graph_render_on_the_home_page_with_any_query(): void
    {
        $this->withSite(['google_verification' => 'g-code']);
        $this->withLocales();

        foreach (['/', '/?lang=fr', '/?utm_source=x'] as $url) {
            $response = $this->visit($url, new Page(title: 'UpFiles', suffixSiteName: false))
                ->assertSee('<meta name="google-site-verification" content="g-code">', false);

            $this->assertSame(['Organization', 'WebSite'], array_column(ParsedPage::parse((string)$response->getContent())->jsonLd, '@type'), $url);
        }
    }

    public function test_a_home_page_without_a_verification_code_emits_no_verification_tag(): void
    {
        $this->withSite();

        $this->visit('/', new Page(title: 'UpFiles', suffixSiteName: false))->assertDontSee('google-site-verification', false);
    }

    public function test_the_verification_tag_and_the_graph_stay_off_other_pages_and_a_noindex_home(): void
    {
        $this->withSite(['google_verification' => 'g-code', 'index_by_default' => false]);

        $this->withLocales();

        // Google reads the site name and verification from the domain root, not a locale's home.
        $pages = [
            '/fr'                       => new Page(title: 'Accueil'),
            '/ar/'                      => new Page(title: 'Home'),
            '/faq'                      => new Page(title: 'FAQ'),
            '/'                         => new Page(title: 'Home', robots: Robots::noindex),
            'http://dl.test/'           => new Page(title: 'Home'),
            'http://localhost/?noindex' => null,
        ];

        foreach ($pages as $url => $page) {
            $response = $this->visit($url, $page)->assertDontSee('google-site-verification', false);

            $this->assertStringNotContainsString('application/ld+json', (string)$response->getContent(), $url);
        }
    }

    public function test_the_graph_renders_optional_fields_only_when_set(): void
    {
        $this->withSite();

        $this->assertSame([
            ['@type' => 'Organization', 'name' => 'UpFiles', 'url' => 'http://localhost/'],
            ['@type' => 'WebSite', 'name' => 'UpFiles', 'url' => 'http://localhost/'],
        ], ParsedPage::parse((string)$this->visit('/', new Page(title: 'UpFiles', suffixSiteName: false))->getContent())->jsonLd);

        $this->withSite(['logo' => '/img/logo-512.png', 'same_as' => ['https://x.com/upfiles'], 'alternate_names' => ['Up Files']]);

        $this->visit('http://localhost/', new Page(title: 'UpFiles', suffixSiteName: false))->assertSee(
            '<script type="application/ld+json">{"@context":"https://schema.org","@graph":['
            . '{"@type":"Organization","name":"UpFiles","alternateName":["Up Files"],"url":"http://localhost/","logo":"http://localhost/img/logo-512.png","sameAs":["https://x.com/upfiles"]},'
            . '{"@type":"WebSite","name":"UpFiles","alternateName":["Up Files"],"url":"http://localhost/"}'
            . ']}</script>',
            false,
        );
    }

    public function test_raw_json_ld_renders_one_script_per_node_after_the_graph_and_stays_escaped(): void
    {
        $this->withSite();
        $node = new class implements JsonSerializable
        {
            /** @return array<string, string> */
            public function jsonSerialize(): array
            {
                return ['@type' => 'Product', 'name' => '<b>'];
            }
        };

        $html = (string)$this->visit('/', new Page(title: 'UpFiles', suffixSiteName: false, jsonLd: [
            ['@type' => 'FAQPage', 'name' => '</script><script>alert(1)</script>'],
            ['@type' => 'Thing', 'name' => "It's \"quoted\" & <b>"],
            $node,
        ]))->getContent();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('"name":"\\u003C/script\\u003E\\u003Cscript\\u003Ealert(1)\\u003C/script\\u003E"', $html);
        $nodes = ParsedPage::parse($html)->jsonLd;
        $this->assertSame(4, substr_count($html, 'application/ld+json'));
        $this->assertSame(['Organization', 'WebSite', 'FAQPage', 'Thing', 'Product'], array_column($nodes, '@type'));
        $this->assertSame("It's \"quoted\" & <b>", $nodes[3]['name']);
        $this->assertStringContainsString('{"@type":"Product","name":"\\u003Cb\\u003E"}', $html);
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedJsonLd(): iterable
    {
        yield 'a single node not wrapped in a list' => [['@type' => 'FAQPage']];
        yield 'a node already encoded' => [['{"@type":"FAQPage"}']];
    }

    #[DataProvider('malformedJsonLd')]
    public function test_malformed_json_ld_throws_at_the_call(mixed $jsonLd): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Page::$jsonLd');

        $this->seo()->page(jsonLd: $jsonLd);
    }

    public function test_og_site_name_equals_the_website_name(): void
    {
        $this->withSite(['name' => 'Up & Files']);

        $response = $this->visit('/', new Page(title: 'Home', suffixSiteName: false))
            ->assertSee('<meta property="og:site_name" content="Up &amp; Files">', false);

        $this->assertSame('Up & Files', ParsedPage::parse((string)$response->getContent())->jsonLd[1]['name']);
    }

    public function test_seo_after_the_head_in_a_partial_or_the_body_still_reaches_it(): void
    {
        $this->withSite();
        Route::get('partial', static fn (): string => Blade::render("<head><x-seo::head /></head><body>@include('partial')</body>"));
        Route::get('body', static fn (): string => Blade::render("<head><x-seo::head /></head><body>@seo(title: 'Body')</body>"));

        $this->get('/partial')->assertOk()->assertSee('<title>Partial · UpFiles</title>', false);
        $this->get('/body')->assertOk()->assertSee('<title>Body · UpFiles</title>', false);
    }

    public function test_a_view_rendering_another_full_view_keeps_one_head_in_head(): void
    {
        $this->withSite();
        Route::get('x', static fn (): string => Blade::render("<head><x-seo::head /></head><body>{!! view('page')->render() !!}@seo(title: 'Outer')</body>"));

        $html = (string)$this->get('/x')->assertOk()->getContent();

        $this->assertStringStartsWith('<head><title>Outer · UpFiles</title>', $html);
        $this->assertSame(1, substr_count($html, '<title>'));
        $this->assertStringNotContainsString('<!--seo-head:', $html);
    }

    public function test_a_response_cache_in_route_middleware_stores_the_filled_head(): void
    {
        $this->withSite();
        $cache = new class
        {
            public string $stored = '';

            public function handle(Request $request, Closure $next): mixed
            {
                $response = $next($request);
                $this->stored = (string)$response->getContent();

                return $response;
            }
        };
        $this->app->instance('response-cache', $cache);
        Route::get('terms', static fn () => view('directive'))->middleware('response-cache');

        $this->get('/terms')->assertOk();

        $this->assertStringContainsString('<title>Terms · UpFiles</title>', $cache->stored);
    }

    public function test_an_error_page_rendered_by_the_exception_handler_gets_its_head(): void
    {
        $this->withSite();

        $this->get('/missing')
            ->assertNotFound()
            ->assertSee('<title>Not found · UpFiles</title>', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertDontSee('rel="canonical"', false)
            ->assertDontSee('og:url', false);
    }

    public function test_an_error_page_ignores_the_page_its_controller_set_before_failing(): void
    {
        $this->withSite();
        Route::get('post', static function (Seo $seo): never {
            $seo->page(title: 'Deleted post', description: 'Gone.', canonical: '/post/other', jsonLd: [['@type' => 'Article']]);
            abort(404);
        });

        $this->get('/post')
            ->assertNotFound()
            ->assertSee('<title>Not found · UpFiles</title>', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertDontSee('name="description"', false)
            ->assertDontSee('rel="canonical"', false)
            ->assertDontSee('og:url', false)
            ->assertDontSee('application/ld+json', false);
    }

    public function test_a_json_response_is_left_alone(): void
    {
        $this->withSite();
        Route::get('x', static fn () => response()->json(['html' => view('directive')->render()]));
        // response($array) is an Illuminate\Http\Response with a JSON body.
        Route::get('y', static fn () => response(['html' => view('directive')->render()]));

        foreach (['/x', '/y'] as $url) {
            $this->assertMatchesRegularExpression('~<!--seo-head:[0-9a-f]{16}-->~', (string)$this->get($url)->assertOk()->json('html'), $url);
        }
    }

    public function test_a_sub_request_made_after_the_head_renders_leaves_the_outer_head_to_fill(): void
    {
        $this->withSite();
        Route::get('inner', static fn (): string => 'inner');
        Route::get('outer', static fn (): string => Blade::render(
            '@seo(title: \'Outer\')<head><x-seo::head /></head><body>{{ app(\Illuminate\Contracts\Http\Kernel::class)->handle(\Illuminate\Http\Request::create(\'/inner\'))->getContent() }}</body>',
        ));

        $this->get('/outer')->assertSee('<head><title>Outer · UpFiles</title>', false);
    }

    public function test_a_marker_in_the_content_without_the_requests_nonce_is_left_alone(): void
    {
        $this->withSite();
        Route::get('x', static fn (): string => Blade::render('<head><x-seo::head /></head><body><!--seo-head:0123456789abcdef--></body>'));

        $this->get('/x')
            ->assertSee('<head><title>UpFiles</title>', false)
            ->assertSee('<body><!--seo-head:0123456789abcdef--></body>', false);
    }

    public function test_a_published_head_view_renders_in_place_of_the_packages(): void
    {
        $this->withSite();
        View::prependNamespace('seo', __DIR__ . '/Fixtures/published');

        $this->visit('/faq', new Page(title: 'FAQ'))->assertSee('<title>Published FAQ · UpFiles</title>', false);
    }

    public function test_the_page_site_and_pending_head_end_with_the_scope_as_between_queue_jobs(): void
    {
        // A queue worker reuses one console Request for every job.
        $this->withSite();
        $this->app->instance('request', $request = Request::create('/'));
        $this->seo()->page(title: 'Job one');
        $site = $this->seo()->site($request);
        $response = new Response(Blade::render('<x-seo::head />'));

        $this->app->forgetScopedInstances();
        event(new RequestHandled($request, $response));

        $this->assertNull($this->seo()->pageFor($request));
        $this->assertNotSame($site, $this->seo()->site($request));
        $this->assertStringStartsWith('<!--seo-head:', (string)$response->getContent());
    }

    public function test_the_upfiles_home_renders_every_tag_in_emission_order(): void
    {
        $this->withSite(['google_verification' => 'g-code', 'organization_type' => 'OnlineBusiness']);
        $description = 'UpFiles is a file-sharing platform that allows users to make money by sharing files. We offer the best storage and payout rates ever!';

        $this->assertSame(<<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>UpFiles - Make Money by Sharing Files!</title>
            <meta name="description" content="{$description}">
            <meta name="robots" content="max-image-preview:large">
            <link rel="canonical" href="http://localhost/">
            <meta property="og:site_name" content="UpFiles">
            <meta property="og:type" content="website">
            <meta property="og:title" content="UpFiles - Make Money by Sharing Files!">
            <meta property="og:description" content="{$description}">
            <meta property="og:url" content="http://localhost/">
            <meta property="og:image" content="http://localhost/img/og-image.png">
            <meta property="og:image:alt" content="UpFiles">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="google-site-verification" content="g-code">
            <script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"OnlineBusiness","name":"UpFiles","url":"http://localhost/"},{"@type":"WebSite","name":"UpFiles","url":"http://localhost/"}]}</script>
            </head>
            <body>
            </body>
            </html>

            HTML, $this->visit('/', new Page(title: 'UpFiles - Make Money by Sharing Files!', description: $description, suffixSiteName: false))->getContent());
    }

    public function test_the_hreflang_block_renders_in_emission_order(): void
    {
        $this->withSite(['name' => 'cuty.io', 'title_separator' => ' | ', 'image' => '/og.png']);
        $this->withLocales(['en', 'fr'], 'en');

        $this->visit('/fr/faq', new Page(title: "Conditions d'utilisation", description: 'Desc.'))->assertSeeInOrder([
            '<html lang="fr">',
            '<title>Conditions d&#039;utilisation | cuty.io</title>',
            '<meta name="description" content="Desc.">',
            '<meta name="robots" content="max-image-preview:large">',
            '<link rel="canonical" href="http://localhost/fr/faq">',
            '<link rel="alternate" hreflang="en" href="http://localhost/faq">',
            '<link rel="alternate" hreflang="fr" href="http://localhost/fr/faq">',
            '<link rel="alternate" hreflang="x-default" href="http://localhost/faq">',
            '<meta property="og:site_name" content="cuty.io">',
            '<meta property="og:type" content="website">',
            '<meta property="og:title" content="Conditions d&#039;utilisation | cuty.io">',
            '<meta property="og:description" content="Desc.">',
            '<meta property="og:url" content="http://localhost/fr/faq">',
            '<meta property="og:image" content="http://localhost/og.png">',
            '<meta property="og:image:alt" content="cuty.io">',
            '<meta name="twitter:card" content="summary_large_image">',
        ], false);
    }

    public function test_the_site_is_rebuilt_when_the_locale_changes_within_a_request(): void
    {
        // A siteUsing() closure may read the locale, and a Site built before SetLocale ran must not leak it.
        $this->seo()->siteUsing(static fn (Application $app): array => ['name' => $app->getLocale() === 'fr' ? 'Accueil' : 'Home']);
        $request = Request::create('/fr');
        $site = $this->seo()->site($request);

        $this->assertSame('Home', $site->name);
        $this->assertSame($site, $this->seo()->site($request));

        $this->app->setLocale('fr');

        $this->assertSame('Accueil', $this->seo()->site($request)->name);
        $this->assertSame($this->seo()->site($request), $this->seo()->site($request));
    }
}
