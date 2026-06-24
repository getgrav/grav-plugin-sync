<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Tests\Unit;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Sync\RoomRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RoomRegistryTest extends TestCase
{
    private function registry(): RoomRegistry
    {
        return new RoomRegistry();
    }

    public function test_basic_room_id(): void
    {
        $room = $this->registry()->roomFor('/blog/hello');
        $this->assertSame('blog/hello@default', $room->id);
        $this->assertSame('blog/hello', $room->route);
        $this->assertNull($room->language);
        $this->assertSame('default', $room->template);
    }

    public function test_with_language(): void
    {
        $room = $this->registry()->roomFor('/blog/hello', 'fr');
        $this->assertSame('blog/hello@default@fr', $room->id);
        $this->assertSame('fr', $room->language);
    }

    public function test_with_custom_template(): void
    {
        $room = $this->registry()->roomFor('/blog/hello', null, 'item');
        $this->assertSame('blog/hello@item', $room->id);
    }

    public function test_with_language_and_template(): void
    {
        $room = $this->registry()->roomFor('/blog/hello', 'es', 'item');
        $this->assertSame('blog/hello@item@es', $room->id);
    }

    public function test_region_language(): void
    {
        $room = $this->registry()->roomFor('/blog/hello', 'pt-BR');
        $this->assertSame('blog/hello@default@pt-br', $room->id);
    }

    public function test_strips_surrounding_slashes(): void
    {
        $r = $this->registry();
        $this->assertSame('blog/hello@default', $r->roomFor('blog/hello')->id);
        $this->assertSame('blog/hello@default', $r->roomFor('/blog/hello/')->id);
    }

    public function test_rejects_empty_route(): void
    {
        $this->expectException(RuntimeException::class);
        $this->registry()->roomFor('/');
    }

    public function test_rejects_dotdot(): void
    {
        $this->expectException(RuntimeException::class);
        $this->registry()->roomFor('/blog/../secrets');
    }

    public function test_rejects_bad_language(): void
    {
        $this->expectException(RuntimeException::class);
        $this->registry()->roomFor('/blog/hello', 'not-a-lang');
    }

    public function test_rejects_bad_template(): void
    {
        $this->expectException(RuntimeException::class);
        $this->registry()->roomFor('/blog/hello', null, 'bad template');
    }

    public function test_parse_round_trip(): void
    {
        $r = $this->registry();
        $cases = [
            ['blog/hello',          null,  'default'],
            ['blog/hello',          'fr',  'default'],
            ['blog/hello',          null,  'item'],
            ['blog/hello',          'es',  'item'],
            ['root',                null,  'default'],
            ['a/b/c/d',             'pt-br', 'modular'],
        ];
        foreach ($cases as [$route, $lang, $tpl]) {
            $room = $r->roomFor($route, $lang, $tpl);
            $parsed = $r->parse($room->id);
            $this->assertSame($route, $parsed['route'], "route roundtrip for {$room->id}");
            $this->assertSame($lang, $parsed['language'], "lang roundtrip for {$room->id}");
            $this->assertSame($tpl, $parsed['template'], "template roundtrip for {$room->id}");
        }
    }

    public function test_parse_malformed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->registry()->parse('no-at-sign');
    }

    /**
     * Regression for getgrav/grav-plugin-admin2#59: under
     * `system.home.hide_in_urls`, a home-folder page's public `route()`
     * drops the home segment (`/home/my-article` → `/my-article`), but the
     * editor opens the collab room / page-scoped endpoints by the raw route
     * (`home/my-article`). `roomForPage()` must key off the raw route so the
     * `page-saved` broadcast lands on the channel the client subscribes to.
     */
    public function test_room_for_page_uses_raw_route_for_home_child(): void
    {
        if (!interface_exists(PageInterface::class)) {
            $this->markTestSkipped('Grav core (PageInterface) not autoloaded in this environment.');
        }
        $page = $this->createMock(PageInterface::class);
        $page->method('route')->willReturn('/my-article');        // hide_in_urls public route
        $page->method('rawRoute')->willReturn('/home/my-article'); // slug/folder route
        $page->method('template')->willReturn('default');

        $r = $this->registry();
        $room = $r->roomForPage($page);

        // Matches the raw-route-derived id the CRDT room / client use…
        $this->assertSame('home/my-article@default', $room->id);
        $this->assertSame($r->roomFor('/home/my-article')->id, $room->id);
        // …and is NOT the home-stripped public route that would silently
        // diverge from the client's subscription.
        $this->assertNotSame($r->roomFor('/my-article')->id, $room->id);
    }

    public function test_room_for_page_raw_route_matches_route_for_non_home_page(): void
    {
        if (!interface_exists(PageInterface::class)) {
            $this->markTestSkipped('Grav core (PageInterface) not autoloaded in this environment.');
        }
        $page = $this->createMock(PageInterface::class);
        $page->method('route')->willReturn('/blog/hello');
        $page->method('rawRoute')->willReturn('/blog/hello');
        $page->method('template')->willReturn('item');

        $room = $this->registry()->roomForPage($page, 'fr');
        $this->assertSame('blog/hello@item@fr', $room->id);
    }
}
