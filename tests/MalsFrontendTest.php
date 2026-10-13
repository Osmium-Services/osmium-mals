<?php

declare(strict_types=1);

namespace Tests\Services\Mals;

use PHPUnit\Framework\TestCase;

/**
 * The add-to-cart click is handled by a script the service ships and loads at the end of every page, because the
 * site's security policy blocks inline onclick handlers.
 */
final class MalsFrontendTest extends TestCase
{
    private function manifest(): array
    {
        return \json_decode((string) \file_get_contents(__DIR__ . '/../service.json'), true);
    }

    public function test_the_manifest_loads_the_click_script_at_the_end_of_the_page(): void
    {
        $blocks = $this->manifest()['frontend']['themeBlocks'] ?? [];

        $this->assertSame([['hook' => 'body-end', 'file' => 'theme/mals-cart-script.phtml']], $blocks);
        $this->assertFileExists(__DIR__ . '/../' . $blocks[0]['file']);
    }

    public function test_the_block_loads_the_shipped_script_from_the_services_public_folder(): void
    {
        $block = (string) \file_get_contents(__DIR__ . '/../theme/mals-cart-script.phtml');

        $this->assertStringContainsString('/assets/services/mals/mals-cart.js', $block);
        $this->assertFileExists(__DIR__ . '/../public/mals-cart.js');
    }

    public function test_the_script_handles_the_data_hook_a_theme_puts_on_the_link_and_not_an_inline_handler(): void
    {
        $script = (string) \file_get_contents(__DIR__ . '/../public/mals-cart.js');

        $this->assertStringContainsString("closest('[data-mals-add]')", $script);
        $this->assertStringContainsString('form.submit()', $script);
        $this->assertStringNotContainsString('onclick', $script);
    }

    public function test_the_setup_note_tells_a_theme_how_to_mark_the_link(): void
    {
        $note = $this->manifest()['setup']['postInstallNote'];

        $this->assertStringContainsString('data-mals-add', $note);
        $this->assertStringContainsString('serviceBodyEndHtml', $note);
    }
}
