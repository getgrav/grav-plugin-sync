<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Tests\Unit;

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
        $this->assertSame('blog/hello.fr@default', $room->id);
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
        $this->assertSame('blog/hello.es@item', $room->id);
    }

    public function test_region_language(): void
    {
        $room = $this->registry()->roomFor('/blog/hello', 'pt-BR');
        $this->assertSame('blog/hello.pt-br@default', $room->id);
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
}
